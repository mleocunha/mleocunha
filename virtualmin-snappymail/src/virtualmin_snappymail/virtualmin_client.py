"""Adapter around the official Virtualmin CLI.

Website features are resolved via :mod:`webstack` so both Apache
(``--web``/``--ssl``) and Nginx (``--virtualmin-nginx``/``--virtualmin-nginx-ssl``)
Virtualmin flavours work. Mail is never enabled on webmail subservers.
"""

from __future__ import annotations

import re
import shutil
import subprocess
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable, Sequence

from .domain import normalize_domain, try_normalize_domain
from .errors import SubserverConflictError, VirtualminError
from .security import assert_safe_argv_token, redact_secrets
from .webstack import (
    FORBIDDEN as FORBIDDEN_SUB_FEATURES,
    WEBSITE_CODES as WEBSITE_FEATURE_CODES,
    WebStackProfile,
    detect_web_stack,
    domain_has_website,
    domain_is_web_only,
    flavor_from_features,
)

_NGINX_VHOST_DIRS = (
    Path("/etc/nginx/sites-available"),
    Path("/etc/nginx/sites-enabled"),
    Path("/etc/nginx/conf.d"),
)

# Re-export legacy names used by tests/docs.
APACHE_WEB_FEATURES = ("web", "ssl")
NGINX_WEB_FEATURES = ("virtualmin-nginx", "virtualmin-nginx-ssl")
BASE_WEB_ONLY_FEATURES = ("dir", "dns", "logrotate")


@dataclass
class DomainInfo:
    name: str
    values: dict[str, str] = field(default_factory=dict)

    @property
    def parent(self) -> str | None:
        return self.values.get("Parent domain") or None

    @property
    def username(self) -> str | None:
        return self.values.get("Username")

    @property
    def home(self) -> Path | None:
        h = self.values.get("Home directory")
        return Path(h) if h else None

    @property
    def html_dir(self) -> Path | None:
        h = self.values.get("HTML directory")
        return Path(h) if h else None

    @property
    def ip(self) -> str | None:
        for key in (
            "IP address",
            "IPv4 address",
            "IP Address",
            "Real IP address",
            "External IP address",
        ):
            val = (self.values.get(key) or "").strip()
            if val and val.lower() not in {"", "none", "n/a"}:
                # Virtualmin sometimes prints "1.2.3.4 (shared)"
                return val.split()[0]
        return None

    @property
    def ip_is_shared(self) -> bool | None:
        """True/False when multiline labels the IP; None if unknown."""
        for key in (
            "IP address",
            "IPv4 address",
            "IP Address",
            "Real IP address",
            "External IP address",
        ):
            val = (self.values.get(key) or "").strip()
            if not val or val.lower() in {"", "none", "n/a"}:
                continue
            lowered = val.lower()
            if "shared" in lowered:
                return True
            if "private" in lowered or "dedicated" in lowered or "virtual" in lowered:
                return False
            return False
        return None

    @property
    def features(self) -> set[str]:
        raw = self.values.get("Features", "")
        plugins = self.values.get("Plugins", "")
        return {f for f in (raw + " " + plugins).split() if f}

    @property
    def domain_type(self) -> str | None:
        return self.values.get("Type")

    def has_feature(self, feature: str) -> bool:
        return feature in self.features

    def has_website(self) -> bool:
        return domain_has_website(self.features, url=self.values.get("URL"))

    def web_flavor(self) -> str:
        return flavor_from_features(self.features)

    def is_subserver(self) -> bool:
        t = (self.domain_type or "").lower()
        return "sub-server" in t or bool(self.parent)

    def is_web_only(self) -> bool:
        return domain_is_web_only(self.features, url=self.values.get("URL"))


def parse_multiline_domains(text: str) -> list[DomainInfo]:
    """Parse `virtualmin list-domains --multiline` output.

    Virtualmin GPL prints the domain header *without* a trailing colon::

        exemplo.com.br
            Type: Top-level server
            Features: unix dir dns mail web ssl

    Some docs/examples show a trailing colon; both forms are accepted.
    """
    domains: list[DomainInfo] = []
    current: DomainInfo | None = None
    # Header: non-indented line that is either "name" or "name:"
    header_re = re.compile(r"^(\S\S*)\s*:?\s*$")
    kv_re = re.compile(r"^\s{2,}([^:]+):\s*(.*)$")

    for line in text.splitlines():
        if not line.strip():
            continue
        # Indented key/value lines belong to the current domain.
        if line.startswith(" ") or line.startswith("\t"):
            if current is None:
                continue
            km = kv_re.match(line)
            if km:
                current.values[km.group(1).strip()] = km.group(2).strip()
            continue
        hm = header_re.match(line)
        if not hm:
            continue
        name = hm.group(1).strip().lower().rstrip(":")
        # Skip obvious non-domain banners
        if name.lower() in {"id", "file", "type", "features", "plugins"}:
            continue
        current = DomainInfo(name=name)
        domains.append(current)
    return domains


class VirtualminClient:
    def __init__(
        self,
        binary: str | None = None,
        *,
        timeout: int = 600,
        runner=None,
    ) -> None:
        self.binary = binary or shutil.which("virtualmin") or "/usr/sbin/virtualmin"
        self.timeout = timeout
        self._runner = runner or subprocess.run
        self._help_cache: dict[str, str] = {}

    def available(self) -> bool:
        return Path(self.binary).is_file() or shutil.which(self.binary) is not None

    def run(self, args: Sequence[str], *, check: bool = True) -> subprocess.CompletedProcess[str]:
        safe_args = [assert_safe_argv_token(str(a)) for a in args]
        cmd = [self.binary, *safe_args]
        try:
            proc = self._runner(
                cmd,
                check=False,
                capture_output=True,
                text=True,
                timeout=self.timeout,
            )
        except FileNotFoundError as exc:
            raise VirtualminError(
                f"Virtualmin CLI not found at {self.binary}. "
                "Install/run this tool on a Virtualmin host."
            ) from exc
        except subprocess.TimeoutExpired as exc:
            raise VirtualminError(f"Virtualmin command timed out: {' '.join(safe_args)}") from exc

        if check and proc.returncode != 0:
            err = redact_secrets((proc.stderr or proc.stdout or "").strip())
            raise VirtualminError(
                f"virtualmin {' '.join(safe_args)} failed (exit {proc.returncode}): {err}"
            )
        return proc

    def help(self, command: str | None = None) -> str:
        key = command or ""
        if key in self._help_cache:
            return self._help_cache[key]
        args = ["help", command] if command else ["help"]
        proc = self.run(args, check=False)
        text = (proc.stdout or "") + (proc.stderr or "")
        self._help_cache[key] = text
        return text

    def supports_flag(self, command: str, flag: str) -> bool:
        text = self.help(command)
        # Match --flag as a token in help text, including Virtualmin synopsis
        # forms like ``[--shared-ip address]`` / ``[--acme]``.
        return re.search(
            rf"(?:^|[\s\[|]){re.escape(flag)}(?:\s|$|,|\]|/|=)",
            text,
        ) is not None

    def list_domains_multiline(self, domain: str | None = None) -> list[DomainInfo]:
        args = ["list-domains", "--multiline"]
        if domain:
            args.extend(["--domain", normalize_domain(domain)])
        # Missing domains exit non-zero; callers that probe optional hosts need that.
        proc = self.run(args, check=False)
        if proc.returncode != 0:
            return []
        return parse_multiline_domains(proc.stdout or "")

    def get_domain(self, domain: str) -> DomainInfo | None:
        want = normalize_domain(domain)
        domains = self.list_domains_multiline(want)
        for d in domains:
            if d.name == want:
                return d
        # Fallback if multiline parsing ever drifts: confirm via --name-only then
        # re-parse a filtered dump.
        proc = self.run(["list-domains", "--name-only", "--domain", want], check=False)
        names = {
            nd
            for line in (proc.stdout or "").splitlines()
            if line.strip() and proc.returncode == 0
            for nd in (try_normalize_domain(line),)
            if nd
        }
        if want not in names:
            return None
        # Domain exists but multiline parse missed it — fetch all multiline and find it.
        for d in self.list_domains_multiline():
            if d.name == want:
                return d
        # Last resort: synthesize a minimal DomainInfo so callers can proceed
        # with feature probes via dedicated commands.
        return DomainInfo(name=want, values={"Type": "Unknown"})

    def domain_exists(self, domain: str) -> bool:
        want = normalize_domain(domain)
        proc = self.run(["list-domains", "--name-only", "--domain", want], check=False)
        if proc.returncode != 0:
            return False
        names = {
            nd
            for line in (proc.stdout or "").splitlines()
            if line.strip()
            for nd in (try_normalize_domain(line),)
            if nd
        }
        return want in names

    def list_children(self, parent: str) -> list[str]:
        parent = normalize_domain(parent)
        proc = self.run(["list-domains", "--parent", parent, "--name-only"], check=False)
        if proc.returncode != 0:
            return []
        out: list[str] = []
        for line in (proc.stdout or "").splitlines():
            nd = try_normalize_domain(line)
            if nd:
                out.append(nd)
        return out

    def available_create_domain_flags(self) -> set[str]:
        """Return creatable flag names from `virtualmin help create-domain`.

        Uses bracketed ``[--flag]`` forms so narrative POD examples mentioning
        disabled features (e.g. ``--web`` on Nginx-only hosts) are ignored.
        """
        from .webstack import parse_create_domain_flags

        return parse_create_domain_flags(self.help("create-domain"))

    def list_feature_codes(self, *, parent: str | None = None) -> set[str]:
        """Feature codes from `virtualmin list-features --name-only`."""
        args = ["list-features", "--name-only"]
        if parent:
            args.extend(["--parent", normalize_domain(parent)])
        proc = self.run(args, check=False)
        if proc.returncode != 0:
            return set()
        return {line.strip() for line in (proc.stdout or "").splitlines() if line.strip()}

    def list_enabled_features(self, *, parent: str | None = None) -> set[str]:
        """Features with Enabled: Yes from `virtualmin list-features --multiline`."""
        from .webstack import enabled_feature_codes

        args = ["list-features", "--multiline"]
        if parent:
            args.extend(["--parent", normalize_domain(parent)])
        proc = self.run(args, check=False)
        if proc.returncode != 0:
            return set()
        return enabled_feature_codes(proc.stdout or "")

    def detect_web_stack_profile(
        self,
        *,
        parent: DomainInfo | None = None,
        extra_features: Iterable[str] = (),
    ) -> WebStackProfile:
        flags = self.available_create_domain_flags()
        parent_name = parent.name if parent else None
        listed = self.list_feature_codes(parent=parent_name)
        enabled = self.list_enabled_features(parent=parent_name)
        return detect_web_stack(
            create_flags=flags,
            parent_features=parent.features if parent else None,
            list_feature_codes=listed,
            enabled_features=enabled,
            os_has_nginx=bool(shutil.which("nginx")),
            os_has_apache=bool(shutil.which("apache2") or shutil.which("httpd")),
            extra_features=extra_features,
        )

    def resolve_web_only_features(
        self,
        *,
        parent: DomainInfo | None = None,
        extra_features: Iterable[str] = (),
    ) -> list[str]:
        """Pick web-only features that this Virtualmin build actually supports."""
        profile = self.detect_web_stack_profile(
            parent=parent, extra_features=extra_features
        )
        if not profile.has_website:
            raise VirtualminError(
                "Neither Apache (--web/--ssl) nor Nginx (--virtualmin-nginx/"
                "--virtualmin-nginx-ssl) website features could be resolved. "
                "Enable a webserver feature in Virtualmin module configuration. "
                f"Detection notes: {'; '.join(profile.notes) or 'none'}; "
                f"sources: {', '.join(profile.sources) or 'none'}"
            )
        return list(profile.create_features)

    def get_domain_ip(self, domain: str) -> str | None:
        """Resolve a domain's IPv4/IPv6 via multiline fields or ``--ip-only``."""
        domain = normalize_domain(domain)
        info = self.get_domain(domain)
        if info and info.ip:
            return info.ip
        proc = self.run(
            ["list-domains", "--domain", domain, "--ip-only"],
            check=False,
        )
        if proc.returncode != 0:
            return None
        for line in (proc.stdout or "").splitlines():
            token = line.strip().split()[0] if line.strip() else ""
            if not token:
                continue
            # IPv4
            if re.match(r"^\d{1,3}(?:\.\d{1,3}){3}$", token):
                return token
            # IPv6 (very loose)
            if ":" in token and re.match(r"^[0-9A-Fa-f:]+$", token):
                return token
        return None

    def list_shared_ips(self) -> set[str]:
        """Additional shared IPs from ``list-shared-addresses``."""
        shared: set[str] = set()
        proc = self.run(["list-shared-addresses", "--name-only"], check=False)
        if proc.returncode != 0:
            proc = self.run(["list-shared-addresses"], check=False)
            if proc.returncode != 0:
                return shared
        for line in (proc.stdout or "").splitlines():
            raw = line.strip()
            if not raw or raw.lower().startswith("default"):
                # Default shared address is NOT usable with --shared-ip.
                continue
            if ":" in raw and not re.match(r"^\d", raw):
                raw = raw.split(":", 1)[-1].strip()
            token = raw.split()[0] if raw else ""
            if re.match(r"^\d{1,3}(?:\.\d{1,3}){3}$", token):
                shared.add(token)
            elif ":" in token and re.match(r"^[0-9A-Fa-f:]+$", token):
                shared.add(token)
        return shared

    def find_domains_on_ip(self, ip: str) -> list[str]:
        """Domains Virtualmin associates with ``ip`` (``list-domains --ip``)."""
        ip = (ip or "").strip().split()[0]
        if not ip:
            return []
        proc = self.run(["list-domains", "--ip", ip, "--name-only"], check=False)
        if proc.returncode != 0:
            return []
        out: list[str] = []
        for line in (proc.stdout or "").splitlines():
            nd = try_normalize_domain(line)
            if nd:
                out.append(nd)
        return out

    def convert_domain_to_default_ip(self, domain: str) -> bool:
        """Revert a private-IP virtual server to the system default shared IP."""
        domain = normalize_domain(domain)
        if not self.supports_flag("modify-domain", "--default-ip"):
            return False
        args = ["modify-domain", "--domain", domain, "--default-ip"]
        if self.supports_flag("modify-domain", "--skip-warnings"):
            args.append("--skip-warnings")
        proc = self.run(args, check=False)
        return proc.returncode == 0

    def detect_os_iface_for_ip(self, ip: str) -> str | None:
        """Return the Linux interface that owns ``ip`` (via ``ip -4 addr``)."""
        ip = (ip or "").strip().split()[0]
        if not ip:
            return None
        try:
            proc = self._runner(
                ["ip", "-4", "-o", "addr", "show"],
                check=False,
                capture_output=True,
                text=True,
                timeout=30,
            )
        except Exception:  # noqa: BLE001
            return None
        for line in (getattr(proc, "stdout", "") or "").splitlines():
            # Example: "2: eth0    inet 191.176.16.2/24 ..."
            if f" inet {ip}/" in f" {line} " or f" inet {ip} " in f" {line} ":
                parts = line.split()
                if len(parts) >= 2:
                    iface = parts[1].split("@", 1)[0]
                    if iface and iface != "lo":
                        return iface
        # Fallback: route get
        try:
            proc = self._runner(
                ["ip", "-4", "route", "get", ip],
                check=False,
                capture_output=True,
                text=True,
                timeout=30,
            )
        except Exception:  # noqa: BLE001
            return None
        parts = (getattr(proc, "stdout", "") or "").split()
        if "dev" in parts:
            iface = parts[parts.index("dev") + 1]
            if iface and iface != "lo":
                return iface
        return None

    def ensure_default_network_ip(
        self,
        ip: str,
        *,
        config_path: Path | None = None,
    ) -> list[str]:
        """Ensure Virtualmin can resolve the system default shared IP.

        Fixes ``New virtual server has no IP address! Perhaps Virtualmin could
        not work out the system's default IP`` by filling blank ``iface=`` /
        ``localip=`` in ``/etc/webmin/virtual-server/config``.

        Does **not** overwrite non-empty admin-set values.
        """
        actions: list[str] = []
        ip = (ip or "").strip().split()[0]
        if not ip:
            return actions

        config = config_path or Path("/etc/webmin/virtual-server/config")
        if not config.is_file():
            return actions

        iface = self.detect_os_iface_for_ip(ip)
        raw = config.read_text(encoding="utf-8", errors="replace")
        lines = raw.splitlines()
        kv: dict[str, str] = {}
        for line in lines:
            if "=" in line and not line.lstrip().startswith("#"):
                key, _, val = line.partition("=")
                kv[key.strip()] = val.strip()

        updates: dict[str, str] = {}
        current_iface = (kv.get("iface") or "").strip()
        if iface and not current_iface:
            updates["iface"] = iface
        current_localip = (kv.get("localip") or "").strip()
        if not current_localip:
            updates["localip"] = ip

        if not updates:
            return actions

        new_lines = list(lines)
        for key, value in updates.items():
            found = False
            rebuilt: list[str] = []
            for line in new_lines:
                if line.startswith(f"{key}="):
                    found = True
                    rebuilt.append(f"{key}={value}")
                    actions.append(f"set virtual-server {key}={value} (was blank)")
                else:
                    rebuilt.append(line)
            if not found:
                rebuilt.append(f"{key}={value}")
                actions.append(f"set virtual-server {key}={value}")
            new_lines = rebuilt

        backup = Path(str(config) + ".vsm-bak")
        if not backup.exists():
            backup.write_text(raw, encoding="utf-8")
            actions.append(f"backup {backup}")
        config.write_text("\n".join(new_lines) + "\n", encoding="utf-8")
        return actions

    def ensure_shared_address(self, ip: str) -> bool:
        """Register ``ip`` as a Virtualmin shared address if missing.

        On many hosts the public IP is already assigned to an existing server
        (often the *default* address, not a private/virt IP), so
        ``create-shared-address`` refuses with "already using address" and
        ``--default-ip`` refuses with "can only be used when the virtual server
        has a private address".

        In that topology we heal Virtualmin's ``iface``/``localip`` so new
        subservers can inherit the parent IP without ``--shared-ip``.
        """
        ip = (ip or "").strip().split()[0]
        if not ip:
            return False
        if ip in self.list_shared_ips():
            return True

        # Always try to heal default-IP detection first (needed for --parent creates).
        self.ensure_default_network_ip(ip)

        proc = self.run(["create-shared-address", "--ip", ip], check=False)
        if ip in self.list_shared_ips():
            return True

        err = ((proc.stderr or "") + "\n" + (proc.stdout or "")).strip()
        holders: list[str] = []
        m = re.search(
            r"virtual server\s+(\S+)\s+is already using address",
            err,
            flags=re.IGNORECASE,
        )
        if m:
            nd = try_normalize_domain(m.group(1).rstrip("."))
            if nd:
                holders.append(nd)
        for dom in self.find_domains_on_ip(ip):
            if dom not in holders:
                holders.append(dom)

        converted = False
        for dom in holders:
            info = self.get_domain(dom)
            if info and info.ip_is_shared is True:
                continue
            if self.convert_domain_to_default_ip(dom):
                converted = True

        if converted:
            self.run(["create-shared-address", "--ip", ip], check=False)

        return ip in self.list_shared_ips()

    def resolve_parent_ip_flags(
        self,
        *,
        parent_domain: str,
        parent_ip: str | None,
        parent: DomainInfo | None = None,
    ) -> list[str]:
        """Preferred explicit IP flags after healing network/default IP.

        Returns ``--shared-ip`` when the parent IP is on the shared list.
        Otherwise returns ``[]`` so create inherits from ``--parent`` (works
        once Virtualmin default IP / iface is configured).
        """
        _ = parent_domain, parent
        if not parent_ip:
            return []
        self.ensure_default_network_ip(parent_ip)
        if self.ensure_shared_address(parent_ip):
            return ["--shared-ip", parent_ip]
        return []

    def disable_parent_webmail_redirect(self, parent_domain: str) -> bool:
        """Remove Virtualmin webmail/admin redirects on the parent.

        Those redirects claim ``webmail.<parent>`` / ``admin.<parent>`` as
        extra ``server_name`` entries on the parent's Nginx/Apache vhost.
        Creating a real ``webmail.*`` sub-server then fails with::

            An Nginx virtual host with the same name already exists

        Returns True when ``modify-web --no-webmail`` was attempted.
        """
        parent_domain = normalize_domain(parent_domain)
        help_text = self.help("modify-web")
        if not help_text:
            return False
        lowered = help_text.lower()
        if "unknown" in lowered and "not found" in lowered:
            return False
        if "--no-webmail" not in help_text and "no-webmail" not in lowered:
            return False
        self.run(
            ["modify-web", "--domain", parent_domain, "--no-webmail"],
            check=False,
        )
        return True

    def find_orphan_nginx_vhost_files(self, domain: str) -> list[Path]:
        """Nginx conf files for ``domain`` when Virtualmin has no such domain."""
        domain = normalize_domain(domain)
        if self.domain_exists(domain):
            return []
        names = {
            f"{domain}.conf",
            f"{domain}",
            f"{domain}.nginx.conf",
        }
        found: list[Path] = []
        for directory in _NGINX_VHOST_DIRS:
            if not directory.is_dir():
                continue
            for name in names:
                path = directory / name
                if path.is_file() or path.is_symlink():
                    found.append(path)
        # Deduplicate while preserving order.
        seen: set[str] = set()
        unique: list[Path] = []
        for path in found:
            key = str(path.resolve()) if path.exists() else str(path)
            if key in seen:
                continue
            seen.add(key)
            unique.append(path)
        return unique

    def remove_orphan_nginx_vhost_files(self, domain: str) -> list[str]:
        """Best-effort removal of orphan nginx confs for a non-Virtualmin hostname.

        Only touches exact basename matches under standard nginx conf dirs.
        Does nothing when Virtualmin already owns ``domain``.
        """
        removed: list[str] = []
        for path in self.find_orphan_nginx_vhost_files(domain):
            try:
                path.unlink()
                removed.append(str(path))
            except OSError:
                continue
        if removed and shutil.which("nginx"):
            # Validate + reload so create-domain sees a clean nginx state.
            test = self._runner(
                ["nginx", "-t"],
                check=False,
                capture_output=True,
                text=True,
                timeout=30,
            )
            if getattr(test, "returncode", 1) == 0:
                self._runner(
                    ["nginx", "-s", "reload"],
                    check=False,
                    capture_output=True,
                    text=True,
                    timeout=30,
                )
        return removed

    def prepare_webmail_hostname(self, *, webmail_domain: str, parent_domain: str) -> list[str]:
        """Clear parent webmail redirects / orphan nginx names before create-domain."""
        actions: list[str] = []
        webmail_domain = normalize_domain(webmail_domain)
        parent_domain = normalize_domain(parent_domain)
        if self.disable_parent_webmail_redirect(parent_domain):
            actions.append(f"modify-web --domain {parent_domain} --no-webmail")
        orphans = self.remove_orphan_nginx_vhost_files(webmail_domain)
        for path in orphans:
            actions.append(f"removed orphan nginx vhost {path}")
        return actions

    @staticmethod
    def _is_vhost_name_conflict(err: str) -> bool:
        lowered = (err or "").lower()
        return (
            "virtual host with the same name already exists" in lowered
            or "vhost with the same name already exists" in lowered
        )

    @staticmethod
    def _is_ssl_plugin_failure(err: str) -> bool:
        lowered = (err or "").lower()
        return any(
            needle in lowered
            for needle in (
                "virtualmin-nginx-ssl",
                "uninitialized value in string eq",
                "nginx ssl website failed",
                "ssl website failed",
            )
        )

    def resolve_acme_flag(self, profile: WebStackProfile | None = None) -> str | None:
        """Prefer profile.acme_flag; fall back to help-text token scan."""
        if profile and profile.acme_flag:
            return profile.acme_flag
        if self.supports_flag("create-domain", "--acme"):
            return "acme"
        if self.supports_flag("create-domain", "--letsencrypt"):
            return "letsencrypt"
        return None

    def _ssl_feature_for_profile(self, profile: WebStackProfile) -> str | None:
        if profile.ssl_feature:
            return profile.ssl_feature
        if profile.flavor == "nginx":
            return "virtualmin-nginx-ssl"
        if profile.flavor == "apache":
            return "ssl"
        return None

    @staticmethod
    def _is_ip_mode_failure(err: str) -> bool:
        lowered = (err or "").lower()
        return any(
            needle in lowered
            for needle in (
                "not in the shared ip",
                "already in use",
                "already used by virtual server",
                "virtual interface",
                "unknown --shared-ip",
                "unknown --ip",
                "no ip address",
                "could not work out",
                "default ip",
            )
        )

    def _create_domain_base_args(
        self,
        *,
        webmail_domain: str,
        parent_domain: str,
        description: str,
        features: Iterable[str],
        ip_flags: Sequence[str],
        with_letsencrypt: bool,
        profile: WebStackProfile,
        include_website: bool,
        include_ssl: bool,
    ) -> list[str]:
        feats = list(features)
        ssl_feat = self._ssl_feature_for_profile(profile)

        if not include_website:
            feats = [f for f in feats if f not in WEBSITE_FEATURE_CODES]
        elif not include_ssl and ssl_feat:
            feats = [f for f in feats if f != ssl_feat]

        args = [
            "create-domain",
            "--domain",
            webmail_domain,
            "--parent",
            parent_domain,
            "--desc",
            description,
        ]
        for f in feats:
            args.append(f"--{f}")

        # Explicit IP flags (--shared-ip after ensure, or --ip/--ip-already).
        # Empty means inherit from --parent (Virtualmin default for subservers).
        args.extend(list(ip_flags))

        if self.supports_flag("create-domain", "--skip-warnings"):
            args.append("--skip-warnings")

        if include_website and include_ssl and ssl_feat:
            if self.supports_flag("create-domain", "--generate-ssl-cert"):
                args.append("--generate-ssl-cert")
            if self.supports_flag("create-domain", "--link-ssl-cert"):
                args.append("--link-ssl-cert")
            acme = self.resolve_acme_flag(profile) if with_letsencrypt else None
            if acme:
                args.append(f"--{acme}")

        if "--mail" in args:
            raise VirtualminError("Internal error: refusing to create webmail host with --mail")
        return args

    def _cleanup_failed_webmail_create(self, webmail_domain: str) -> None:
        """Remove half-created Virtualmin domain / orphan nginx conf after failed create."""
        if self.domain_exists(webmail_domain):
            self.run(["delete-domain", "--domain", webmail_domain], check=False)
        self.remove_orphan_nginx_vhost_files(webmail_domain)

    def _enable_website_after_create(
        self,
        *,
        webmail_domain: str,
        profile: WebStackProfile,
        with_letsencrypt: bool,
    ) -> None:
        """Enable site (+ SSL) on an already-created subserver that has an IP."""
        site = profile.site_feature
        ssl_feat = self._ssl_feature_for_profile(profile)
        if site:
            self.enable_feature(webmail_domain, site)
        if ssl_feat:
            try:
                self.enable_feature(webmail_domain, ssl_feat)
            except VirtualminError:
                # Nginx often auto-chains SSL when the site feature is enabled.
                pass
        if with_letsencrypt:
            self.generate_letsencrypt(webmail_domain)

    def create_web_only_subserver(
        self,
        *,
        webmail_domain: str,
        parent_domain: str,
        description: str = "SnappyMail webmail (web-only)",
        with_letsencrypt: bool = True,
        extra_features: Iterable[str] = (),
    ) -> WebStackProfile:
        webmail_domain = normalize_domain(webmail_domain)
        parent_domain = normalize_domain(parent_domain)
        parent = self.get_domain(parent_domain)
        profile = self.detect_web_stack_profile(
            parent=parent, extra_features=extra_features
        )
        if not profile.has_website:
            raise VirtualminError(
                "Cannot create webmail subserver: no Apache/Nginx website feature "
                f"available. notes={list(profile.notes)} sources={list(profile.sources)}"
            )

        parent_ip = self.get_domain_ip(parent_domain)
        if parent and parent_ip and not parent.ip:
            parent.values["IP address"] = parent_ip
        ip_flags = self.resolve_parent_ip_flags(
            parent_domain=parent_domain,
            parent_ip=parent_ip,
            parent=parent,
        )
        if profile.flavor == "nginx" and not parent_ip:
            raise VirtualminError(
                f"Cannot determine IP address for parent {parent_domain}. "
                "Nginx SSL setup needs the parent IP. Run: "
                f"`virtualmin list-domains --domain {parent_domain} --ip-only` "
                "and retry after Virtualmin shows an IP."
            )

        # Parent "Redirect webmail and admin" claims webmail.<parent> as a
        # server_name on the parent's vhost. Clear that before create-domain.
        prep_actions = self.prepare_webmail_hostname(
            webmail_domain=webmail_domain, parent_domain=parent_domain
        )

        # Attempt order after healing private→shared IP ownership:
        # 1) inherit from --parent (correct for subservers)
        # 2) --shared-ip when ensure_shared_address succeeded
        # 3) --ip/--ip-already last resort
        preferred = list(ip_flags)
        ip_variants: list[tuple[str, Sequence[str]]] = [
            ("inherit-parent-ip", ()),
        ]
        if preferred:
            ip_variants.append(("preferred", preferred))
        if parent_ip:
            alt = ["--ip", parent_ip]
            if self.supports_flag("create-domain", "--ip-already"):
                alt.append("--ip-already")
            if alt != list(preferred):
                ip_variants.append(("ip-already", alt))
            if preferred[:1] != ["--shared-ip"]:
                ip_variants.append(("shared-ip", ["--shared-ip", parent_ip]))

        attempts: list[tuple[str, list[str]]] = []
        for ip_label, flags in ip_variants:
            attempts.append(
                (
                    f"full/{ip_label}",
                    self._create_domain_base_args(
                        webmail_domain=webmail_domain,
                        parent_domain=parent_domain,
                        description=description,
                        features=profile.create_features,
                        ip_flags=flags,
                        with_letsencrypt=with_letsencrypt,
                        profile=profile,
                        include_website=True,
                        include_ssl=True,
                    ),
                )
            )
            attempts.append(
                (
                    f"staged-no-website/{ip_label}",
                    self._create_domain_base_args(
                        webmail_domain=webmail_domain,
                        parent_domain=parent_domain,
                        description=description,
                        features=profile.create_features,
                        ip_flags=flags,
                        with_letsencrypt=False,
                        profile=profile,
                        include_website=False,
                        include_ssl=False,
                    ),
                )
            )

        last_error: VirtualminError | None = None
        last_mode = ""
        for mode, args in attempts:
            last_mode = mode
            try:
                self.run(args)
                if mode.startswith("staged-no-website"):
                    self._enable_website_after_create(
                        webmail_domain=webmail_domain,
                        profile=profile,
                        with_letsencrypt=with_letsencrypt,
                    )
                return profile
            except VirtualminError as exc:
                last_error = exc
                if self._is_vhost_name_conflict(exc.message):
                    leftover = self.find_orphan_nginx_vhost_files(webmail_domain)
                    leftover_txt = ", ".join(str(p) for p in leftover) or (
                        "(none found under /etc/nginx)"
                    )
                    prep_txt = "; ".join(prep_actions) or "(no automatic prep actions applied)"
                    raise SubserverConflictError(
                        f"Hostname {webmail_domain} is still claimed by an existing Nginx/Apache "
                        f"virtual host (Virtualmin create-domain refused). "
                        f"Prep attempted: {prep_txt}. "
                        f"Orphan nginx confs still present: {leftover_txt}. "
                        f"Manual fix: "
                        f"`virtualmin modify-web --domain {parent_domain} --no-webmail` then "
                        f"remove any leftover `/etc/nginx/sites-*/{webmail_domain}.conf` in "
                        f"Webmin → Servers → Nginx, reload nginx, and re-run install. "
                        f"Original error: {exc.message}"
                    ) from exc
                # Continue through IP-mode / SSL-plugin / staged fallbacks.
                if (
                    self._is_ssl_plugin_failure(exc.message)
                    or self._is_ip_mode_failure(exc.message)
                    or "no ip address" in exc.message.lower()
                    or profile.flavor == "nginx"
                ):
                    self._cleanup_failed_webmail_create(webmail_domain)
                    continue
                raise

        assert last_error is not None
        raise VirtualminError(
            f"Failed to create {webmail_domain} "
            f"(parent IP={parent_ip or 'unknown'}, preferred_ip_flags={list(ip_flags) or 'inherit'}, "
            f"last_mode={last_mode}). "
            f"Last error: {last_error.message}"
        ) from last_error

    def delete_domain(self, domain: str) -> None:
        domain = normalize_domain(domain)
        self.run(["delete-domain", "--domain", domain])

    def disable_feature(self, domain: str, *features: str) -> None:
        domain = normalize_domain(domain)
        args = ["disable-feature", "--domain", domain]
        for f in features:
            args.append(f"--{f}")
        self.run(args)

    def enable_feature(self, domain: str, *features: str) -> None:
        domain = normalize_domain(domain)
        for f in features:
            if f in FORBIDDEN_SUB_FEATURES:
                raise VirtualminError(f"Refusing to enable forbidden feature on webmail host: {f}")
        args = ["enable-feature", "--domain", domain]
        for f in features:
            args.append(f"--{f}")
        self.run(args)

    def generate_letsencrypt(self, domain: str) -> None:
        domain = normalize_domain(domain)
        # Prefer generate-letsencrypt-cert when present; some hosts only expose ACME at create time.
        help_le = self.help("generate-letsencrypt-cert")
        if help_le and "unknown" not in help_le.lower() and "not found" not in help_le.lower():
            self.run(["generate-letsencrypt-cert", "--domain", domain], check=False)
            return
        help_gen = self.help("generate-cert")
        if "--acme" in help_gen or "letsencrypt" in help_gen.lower():
            # Best-effort; ignore failure so install can continue to manual ACME.
            self.run(["generate-cert", "--domain", domain, "--letsencrypt"], check=False)

    def modify_web_php(self, domain: str, *, mode: str = "fpm") -> None:
        domain = normalize_domain(domain)
        # modify-web works for both Apache and Nginx plugins on current Virtualmin.
        help_text = self.help("modify-web")
        if help_text and "unknown" in help_text.lower() and "not found" in help_text.lower():
            return
        args = ["modify-web", "--domain", domain]
        if not help_text or "--mode" in help_text:
            args.extend(["--mode", mode])
        self.run(args, check=False)

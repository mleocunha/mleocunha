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

from .domain import normalize_domain
from .errors import VirtualminError
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
        # Match --flag as a token in help text.
        return re.search(rf"(?m)(?:^|\s){re.escape(flag)}(?:\s|$|,)", text) is not None

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
            normalize_domain(line)
            for line in (proc.stdout or "").splitlines()
            if line.strip() and proc.returncode == 0
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
            normalize_domain(line)
            for line in (proc.stdout or "").splitlines()
            if line.strip()
        }
        return want in names

    def list_children(self, parent: str) -> list[str]:
        parent = normalize_domain(parent)
        proc = self.run(["list-domains", "--parent", parent, "--name-only"], check=False)
        if proc.returncode != 0:
            return []
        return [normalize_domain(line) for line in (proc.stdout or "").splitlines() if line.strip()]

    def available_create_domain_flags(self) -> set[str]:
        """Return creatable flag names from `virtualmin help create-domain`.

        Uses bracketed ``[--flag]`` forms so narrative POD examples mentioning
        disabled features (e.g. ``--web`` on Nginx-only hosts) are ignored.
        """
        from .webstack import parse_create_domain_flags

        return parse_create_domain_flags(self.help("create-domain"))

    def list_feature_codes(self, *, parent: str | None = None) -> set[str]:
        """Feature codes from `virtualmin list-features` (optional --parent)."""
        args = ["list-features", "--name-only"]
        if parent:
            args.extend(["--parent", normalize_domain(parent)])
        proc = self.run(args, check=False)
        if proc.returncode != 0:
            return set()
        return {line.strip() for line in (proc.stdout or "").splitlines() if line.strip()}

    def detect_web_stack_profile(
        self,
        *,
        parent: DomainInfo | None = None,
        extra_features: Iterable[str] = (),
    ) -> WebStackProfile:
        flags = self.available_create_domain_flags()
        listed: set[str] = set()
        if parent:
            listed = self.list_feature_codes(parent=parent.name)
        else:
            listed = self.list_feature_codes()
        return detect_web_stack(
            create_flags=flags,
            parent_features=parent.features if parent else None,
            list_feature_codes=listed,
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

        args = [
            "create-domain",
            "--domain",
            webmail_domain,
            "--parent",
            parent_domain,
            "--desc",
            description,
        ]
        for f in profile.create_features:
            args.append(f"--{f}")

        if with_letsencrypt and profile.acme_flag:
            args.append(f"--{profile.acme_flag}")

        if "--mail" in args:
            raise VirtualminError("Internal error: refusing to create webmail host with --mail")
        self.run(args)
        return profile

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

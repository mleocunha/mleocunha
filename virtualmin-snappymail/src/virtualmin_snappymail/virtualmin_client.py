"""Adapter around the official Virtualmin CLI.

Never invents flags at runtime without probing help when possible.
Feature flags used for web-only create are the documented set:
  --dir --dns --web --ssl --logrotate
and intentionally omit --mail.
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

WEB_ONLY_FEATURES = ("dir", "dns", "web", "ssl", "logrotate")
# Features that must never be enabled on the webmail subserver.
FORBIDDEN_SUB_FEATURES = ("mail", "spam", "virus")


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
        return {f for f in raw.split() if f}

    @property
    def domain_type(self) -> str | None:
        return self.values.get("Type")

    def has_feature(self, feature: str) -> bool:
        return feature in self.features

    def is_subserver(self) -> bool:
        t = (self.domain_type or "").lower()
        return "sub-server" in t or bool(self.parent)

    def is_web_only(self) -> bool:
        return self.has_feature("web") and not self.has_feature("mail")


def parse_multiline_domains(text: str) -> list[DomainInfo]:
    """Parse `virtualmin list-domains --multiline` output."""
    domains: list[DomainInfo] = []
    current: DomainInfo | None = None
    header_re = re.compile(r"^(\S.+):\s*$")
    kv_re = re.compile(r"^\s{2,}([^:]+):\s*(.*)$")

    for line in text.splitlines():
        if not line.strip():
            continue
        hm = header_re.match(line)
        if hm and not line.startswith(" "):
            current = DomainInfo(name=hm.group(1).strip().lower())
            domains.append(current)
            continue
        if current is None:
            continue
        km = kv_re.match(line)
        if km:
            current.values[km.group(1).strip()] = km.group(2).strip()
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
        proc = self.run(args)
        return parse_multiline_domains(proc.stdout or "")

    def get_domain(self, domain: str) -> DomainInfo | None:
        domains = self.list_domains_multiline(normalize_domain(domain))
        want = normalize_domain(domain)
        for d in domains:
            if d.name == want:
                return d
        return None

    def domain_exists(self, domain: str) -> bool:
        return self.get_domain(domain) is not None

    def list_children(self, parent: str) -> list[str]:
        parent = normalize_domain(parent)
        proc = self.run(["list-domains", "--parent", parent, "--name-only"], check=False)
        if proc.returncode != 0:
            return []
        return [normalize_domain(line) for line in (proc.stdout or "").splitlines() if line.strip()]

    def create_web_only_subserver(
        self,
        *,
        webmail_domain: str,
        parent_domain: str,
        description: str = "SnappyMail webmail (web-only)",
        with_letsencrypt: bool = True,
        extra_features: Iterable[str] = (),
    ) -> None:
        webmail_domain = normalize_domain(webmail_domain)
        parent_domain = normalize_domain(parent_domain)

        # Probe create-domain help once; fall back to documented flags.
        help_text = self.help("create-domain")
        features = list(WEB_ONLY_FEATURES)
        for f in extra_features:
            if f not in features and f not in FORBIDDEN_SUB_FEATURES:
                features.append(f)

        args = [
            "create-domain",
            "--domain",
            webmail_domain,
            "--parent",
            parent_domain,
            "--desc",
            description,
        ]
        for f in features:
            flag = f"--{f}"
            if help_text and flag not in help_text and f not in ("dir", "web", "dns", "ssl", "logrotate"):
                # Skip unknown optional features; keep core web set.
                continue
            args.append(flag)

        if with_letsencrypt and (not help_text or "--letsencrypt" in help_text):
            args.append("--letsencrypt")

        # Explicitly never pass --mail.
        assert "--mail" not in args
        self.run(args)

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
        # Prefer generate-letsencrypt-cert; fall back to enable ssl + create flag semantics.
        help_text = self.help("generate-letsencrypt-cert")
        if "Unknown" in help_text and "generate-letsencrypt" in help_text.lower():
            pass
        self.run(["generate-letsencrypt-cert", "--domain", domain])

    def modify_web_php(self, domain: str, *, mode: str = "fpm") -> None:
        domain = normalize_domain(domain)
        help_text = self.help("modify-web")
        args = ["modify-web", "--domain", domain]
        if "--mode" in help_text or not help_text:
            args.extend(["--mode", mode])
        self.run(args)

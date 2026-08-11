"""Runtime environment audit for the host where the manager runs."""

from __future__ import annotations

import platform
import shutil
import subprocess
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any

from .mail_discovery import discover_mail_topology
from .virtualmin_client import VirtualminClient


@dataclass
class EnvironmentReport:
    os_release: dict[str, str] = field(default_factory=dict)
    python: str = ""
    perl: str = ""
    tools: dict[str, str | None] = field(default_factory=dict)
    webmin_version: str | None = None
    virtualmin_version: str | None = None
    php: dict[str, Any] = field(default_factory=dict)
    web_server: str | None = None
    virtualmin_webstack: dict[str, Any] = field(default_factory=dict)
    mail: dict[str, Any] = field(default_factory=dict)
    virtualmin_available: bool = False
    notes: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def _read_os_release() -> dict[str, str]:
    path = Path("/etc/os-release")
    data: dict[str, str] = {}
    if not path.is_file():
        return {"platform": platform.platform()}
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if "=" in line:
            k, _, v = line.partition("=")
            data[k] = v.strip().strip('"')
    return data


def _version_cmd(cmd: list[str]) -> str | None:
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=10, check=False)
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return None
    out = (proc.stdout or proc.stderr or "").strip()
    return out.splitlines()[0] if out else None


def audit_environment(client: VirtualminClient | None = None) -> EnvironmentReport:
    report = EnvironmentReport(
        os_release=_read_os_release(),
        python=platform.python_version(),
        perl=_version_cmd(["perl", "-e", "print $^V"]) or None,  # type: ignore[assignment]
    )
    report.perl = _version_cmd(["perl", "-v"]) or "not found"

    tool_names = [
        "virtualmin",
        "webmin",
        "php",
        "php-fpm",
        "nginx",
        "apache2",
        "httpd",
        "postfix",
        "postconf",
        "doveadm",
        "doveconf",
        "certbot",
        "openssl",
        "curl",
    ]
    report.tools = {name: shutil.which(name) for name in tool_names}

    client = client or VirtualminClient()
    report.virtualmin_available = client.available()
    if report.virtualmin_available:
        try:
            help_out = client.help()
            ver = None
            for path in (
                Path("/usr/share/webmin/virtual-server/version"),
                Path("/usr/libexec/webmin/virtual-server/version"),
                Path("/etc/webmin/virtual-server/version"),
            ):
                if path.is_file():
                    ver = path.read_text(encoding="utf-8", errors="replace").strip() or None
                    if ver:
                        break
            if not ver:
                ver = "available (CLI present; version.pl not shipped)"
            report.virtualmin_version = ver
            if "create-domain" in help_out:
                report.notes.append("Virtualmin CLI help lists create-domain")
            try:
                profile = client.detect_web_stack_profile()
                report.virtualmin_webstack = profile.to_dict()
                report.notes.append(
                    f"Virtualmin website stack for new web-only subservers: {profile.flavor} "
                    f"features={list(profile.create_features)}"
                )
                report.notes.extend(list(profile.notes))
            except Exception as exc:  # noqa: BLE001
                report.notes.append(f"Webstack detection error: {exc}")
        except Exception as exc:  # noqa: BLE001
            report.notes.append(f"Virtualmin probe error: {exc}")
    else:
        report.notes.append("Virtualmin CLI not available on this host")

    for candidate in (
        Path("/usr/share/webmin/version"),
        Path("/usr/libexec/webmin/version"),
        Path("/etc/webmin/version"),
    ):
        if candidate.is_file():
            report.webmin_version = candidate.read_text(encoding="utf-8", errors="replace").strip()
            break

    php_bin = report.tools.get("php")
    if php_bin:
        report.php = {
            "binary": php_bin,
            "version": _version_cmd([php_bin, "-v"]),
            "modules": _version_cmd([php_bin, "-m"]),
        }
    else:
        report.php = {"binary": None}

    if report.tools.get("nginx") and (report.tools.get("apache2") or report.tools.get("httpd")):
        report.web_server = "nginx+apache"
        report.notes.append(
            "Both nginx and apache binaries present; Virtualmin feature flags decide the stack"
        )
    elif report.tools.get("nginx"):
        report.web_server = "nginx"
    elif report.tools.get("apache2") or report.tools.get("httpd"):
        report.web_server = "apache"
    else:
        report.web_server = None
        report.notes.append("No nginx/apache binary detected")

    topo = discover_mail_topology()
    report.mail = topo.to_dict()
    report.notes.extend(topo.notes)
    return report

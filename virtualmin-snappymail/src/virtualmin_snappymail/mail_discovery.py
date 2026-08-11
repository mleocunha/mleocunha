"""Discover real IMAP/SMTP topology — never assume 127.0.0.1:993/587."""

from __future__ import annotations

import re
import shutil
import socket
import ssl
import subprocess
from dataclasses import asdict, dataclass, field
from typing import Any


@dataclass
class Endpoint:
    host: str
    port: int
    security: str  # ssl | starttls | none
    source: str = ""

    def label(self) -> str:
        return f"{self.host}:{self.port}/{self.security}"


@dataclass
class MailTopology:
    hostname: str = ""
    imap: Endpoint | None = None
    smtp: Endpoint | None = None
    notes: list[str] = field(default_factory=list)
    raw: dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> dict[str, Any]:
        return {
            "hostname": self.hostname,
            "imap": asdict(self.imap) if self.imap else None,
            "smtp": asdict(self.smtp) if self.smtp else None,
            "notes": self.notes,
        }


def _run(cmd: list[str], timeout: int = 15) -> str:
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, check=False)
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return ""
    return (proc.stdout or "") + (proc.stderr or "")


def _listening_ports() -> set[int]:
    ports: set[int] = set()
    out = _run(["ss", "-tln"])
    for m in re.finditer(r":(\d+)\s", out):
        ports.add(int(m.group(1)))
    return ports


def _parse_doveconf(text: str) -> dict[str, str]:
    vals: dict[str, str] = {}
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, _, v = line.partition("=")
        vals[k.strip()] = v.strip().strip('"')
    return vals


def _parse_postconf(text: str) -> dict[str, str]:
    vals: dict[str, str] = {}
    for line in text.splitlines():
        if " = " in line:
            k, _, v = line.partition(" = ")
            vals[k.strip()] = v.strip()
    return vals


def probe_tcp(host: str, port: int, timeout: float = 3.0) -> bool:
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except OSError:
        return False


def probe_tls(host: str, port: int, timeout: float = 5.0) -> bool:
    try:
        ctx = ssl.create_default_context()
        with socket.create_connection((host, port), timeout=timeout) as sock:
            with ctx.wrap_socket(sock, server_hostname=host):
                return True
    except OSError:
        return False


def discover_mail_topology(*, prefer_host: str | None = None) -> MailTopology:
    hostname = prefer_host or socket.getfqdn() or "localhost"
    topo = MailTopology(hostname=hostname)
    listening = _listening_ports()
    topo.raw["listening_ports"] = sorted(listening)

    dove = _parse_doveconf(_run(["doveconf", "-n"])) if shutil.which("doveconf") else {}
    post = _parse_postconf(_run(["postconf", "-n"])) if shutil.which("postconf") else {}
    master = _run(["postconf", "-M"]) if shutil.which("postconf") else ""
    topo.raw["dovecot"] = dove
    topo.raw["postfix"] = post

    # IMAP candidates
    imap_candidates: list[Endpoint] = []
    ssl_enabled = dove.get("ssl", "").lower() in {"yes", "required"}
    if 993 in listening:
        host = "127.0.0.1" if probe_tcp("127.0.0.1", 993) else hostname
        imap_candidates.append(Endpoint(host, 993, "ssl", "listener:993"))
    if 143 in listening:
        host = "127.0.0.1" if probe_tcp("127.0.0.1", 143) else hostname
        sec = "starttls" if ssl_enabled else "none"
        imap_candidates.append(Endpoint(host, 143, sec, "listener:143"))

    # SMTP submission candidates
    smtp_candidates: list[Endpoint] = []
    has_submission = bool(re.search(r"(?m)^submission\s", master)) or 587 in listening
    has_smtps = bool(re.search(r"(?m)^smtps\s", master)) or 465 in listening
    if has_submission or 587 in listening:
        host = "127.0.0.1" if probe_tcp("127.0.0.1", 587) else hostname
        smtp_candidates.append(Endpoint(host, 587, "starttls", "submission:587"))
    if has_smtps or 465 in listening:
        host = "127.0.0.1" if probe_tcp("127.0.0.1", 465) else hostname
        smtp_candidates.append(Endpoint(host, 465, "ssl", "smtps:465"))
    if 25 in listening and not smtp_candidates:
        host = "127.0.0.1" if probe_tcp("127.0.0.1", 25) else hostname
        smtp_candidates.append(Endpoint(host, 25, "starttls", "smtp:25"))
        topo.notes.append("No submission/smtps detected; falling back to port 25")

    # Prefer validated local endpoints
    for c in imap_candidates:
        if probe_tcp(c.host, c.port):
            topo.imap = c
            break
    if not topo.imap and imap_candidates:
        topo.imap = imap_candidates[0]
        topo.notes.append("IMAP candidate not confirmed via TCP probe")
    if not topo.imap:
        topo.notes.append("IMAP endpoint not discovered")

    for c in smtp_candidates:
        if probe_tcp(c.host, c.port):
            topo.smtp = c
            break
    if not topo.smtp and smtp_candidates:
        topo.smtp = smtp_candidates[0]
        topo.notes.append("SMTP candidate not confirmed via TCP probe")
    if not topo.smtp:
        topo.notes.append("SMTP endpoint not discovered")

    if not shutil.which("doveconf"):
        topo.notes.append("doveconf not available")
    if not shutil.which("postconf"):
        topo.notes.append("postconf not available")

    return topo


def snappymail_domain_ini(
    *,
    parent_domain: str,
    topo: MailTopology,
) -> str:
    """Generate SnappyMail domain INI for parent mail identity."""
    imap = topo.imap or Endpoint("127.0.0.1", 993, "ssl", "fallback")
    smtp = topo.smtp or Endpoint("127.0.0.1", 587, "starttls", "fallback")

    imap_secure = {"ssl": "SSL", "starttls": "STARTTLS", "none": "None"}[imap.security]
    smtp_secure = {"ssl": "SSL", "starttls": "STARTTLS", "none": "None"}[smtp.security]

    # SnappyMail Domain::ValidateWhiteList matches full email, local-part, or
    # "@domain" — a bare "domain.tld" matches nothing and blocks every login
    # with "not whitelisted". Empty white_list = allow all mailboxes on this
    # domain (safe here: only this domain's IMAP/SMTP is configured).
    return f"""\
imap_host = "{imap.host}"
imap_port = {imap.port}
imap_secure = "{imap_secure}"
imap_short_login = Off
smtp_host = "{smtp.host}"
smtp_port = {smtp.port}
smtp_secure = "{smtp_secure}"
smtp_short_login = Off
smtp_auth = On
smtp_php_mail = Off
white_list = ""
"""

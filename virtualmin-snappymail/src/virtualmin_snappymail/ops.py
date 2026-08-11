"""Install / remove / status / diagnose / repair / upgrade / discover / adopt operations."""

from __future__ import annotations

import json
import shutil
import socket
import ssl
import urllib.error
import urllib.request
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any

from . import get_manager_version
from .domain import (
    coerce_mail_parent_domain,
    normalize_domain,
    parent_from_webmail,
    suggest_domains,
    try_normalize_domain,
    webmail_domain_for,
)
from .errors import (
    AlreadyInstalledError,
    MailOnSubserverError,
    NotManagedError,
    ParentMissingError,
    ParentNoMailError,
    SubserverConflictError,
    VirtualminError,
    VSMError,
)
from .logging_util import audit_event, utc_now_iso
from .mail_discovery import (
    discover_mail_topology,
    invalid_white_list_tokens,
    parse_white_list_value,
    probe_tcp,
    read_domain_ini_white_list,
)
from .manifest import Manifest, load_manifest, manifest_path_for_home, save_manifest
from .snappymail_app import (
    configure_domain,
    detect_installed_version,
    install_fresh,
    looks_like_snappymail,
    upgrade_inplace,
    apply_ownership,
    apply_permissions,
)
from .virtualmin_client import DomainInfo, VirtualminClient


@dataclass
class CheckResult:
    name: str
    ok: bool
    detail: str = ""

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


@dataclass
class StatusRow:
    domain: str
    snappymail: str
    https: str
    imap: str
    smtp: str
    mode: str
    webmail_domain: str = ""
    managed: bool = False

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def list_mail_parents(client: VirtualminClient) -> list[str]:
    """Return top-level Virtualmin domains with the mail feature enabled."""
    try:
        proc = client.run(
            ["list-domains", "--with-feature", "mail", "--toplevel", "--name-only"],
            check=False,
        )
        if proc.returncode == 0 and (proc.stdout or "").strip():
            return sorted(
                {
                    nd
                    for line in (proc.stdout or "").splitlines()
                    if line.strip()
                    for nd in (try_normalize_domain(line),)
                    if nd
                }
            )
    except Exception:  # noqa: BLE001
        pass
    out: list[str] = []
    try:
        for d in client.list_domains_multiline():
            if not d.parent and d.has_feature("mail"):
                out.append(d.name)
    except Exception:  # noqa: BLE001
        return []
    return sorted(set(out))


def _hint_mail_parents(client: VirtualminClient, *, needle: str | None = None) -> str:
    parents = list_mail_parents(client)
    if not parents:
        return (
            "No top-level domains with Mail were found. "
            "Create/enable Mail on a Virtual Server first, then retry."
        )
    parts: list[str] = []
    if needle:
        close = suggest_domains(needle, parents)
        if close:
            parts.append(f"Did you mean: {', '.join(close)}?")
    preview = ", ".join(parents[:20])
    more = "" if len(parents) <= 20 else f" (+{len(parents) - 20} more)"
    parts.append(
        f"Mail-enabled top-level domains on this host: {preview}{more}. "
        "Use one of these exact names with: virtualmin-snappymail install <domain>"
    )
    return " ".join(parts)


def _require_parent_with_mail(client: VirtualminClient, parent: str) -> DomainInfo:
    parent = coerce_mail_parent_domain(parent)
    info = client.get_domain(parent)
    if not info:
        # Distinguish "parser miss" from true absence using name-only.
        if client.domain_exists(parent):
            raise VirtualminError(
                f"Virtual server {parent} exists but could not be parsed from "
                f"`virtualmin list-domains --multiline`. Reinstall the latest "
                f"virtualmin-snappymail and retry."
            )
        raise ParentMissingError(
            f"Virtual server not found: {parent}. {_hint_mail_parents(client, needle=parent)}"
        )
    if info.parent:
        raise ParentMissingError(
            f"{parent} is a sub-server; provide the top-level mail domain. "
            f"{_hint_mail_parents(client, needle=parent)}"
        )
    if not info.has_feature("mail"):
        # If features were not parsed (fallback DomainInfo), re-check via CLI.
        if not info.features:
            proc = client.run(
                ["list-domains", "--with-feature", "mail", "--name-only", "--domain", parent],
                check=False,
            )
            names = {
                nd
                for line in (proc.stdout or "").splitlines()
                if line.strip()
                for nd in (try_normalize_domain(line),)
                if nd
            }
            if parent in names:
                info.values["Features"] = "mail"
            else:
                raise ParentNoMailError(
                    f"Mail for Domain is not enabled on {parent}. "
                    "Enable mail on the parent before installing SnappyMail. "
                    f"{_hint_mail_parents(client, needle=parent)}"
                )
        else:
            raise ParentNoMailError(
                f"Mail for Domain is not enabled on {parent}. "
                "Enable mail on the parent before installing SnappyMail. "
                f"{_hint_mail_parents(client, needle=parent)}"
            )
    return info


def _assert_web_only(sub: DomainInfo) -> None:
    if sub.has_feature("mail"):
        raise MailOnSubserverError(
            f"Sub-server {sub.name} has Mail enabled. "
            "Webmail hosts must be web-only (Mail OFF)."
        )


def install_domain(
    client: VirtualminClient,
    parent_domain: str,
    *,
    logger=None,
    version: str | None = None,
    with_letsencrypt: bool = True,
    force_php_fpm: bool = True,
    mode: str = "subserver",
    path: str | None = None,
) -> dict[str, Any]:
    parent_domain = coerce_mail_parent_domain(parent_domain)
    mode = (mode or "subserver").strip().lower()
    if mode not in {"subserver", "path"}:
        raise VSMError(f"Unknown install mode: {mode!r} (use subserver|path)", code="VSM-DOMAIN-INVALID")
    if mode == "path":
        return _install_path_mode(
            client,
            parent_domain,
            logger=logger,
            version=version,
            path=path,
        )
    return _install_subserver_mode(
        client,
        parent_domain,
        logger=logger,
        version=version,
        with_letsencrypt=with_letsencrypt,
        force_php_fpm=force_php_fpm,
    )


def _normalize_url_path(path: str | None) -> str:
    """Return a clean relative URL path segment (no leading/trailing slashes).

    ``None`` defaults to ``webmail``. An empty string means site root.
    """
    if path is None:
        path = "webmail"
    raw = path.strip().strip("/")
    if not raw:
        return ""
    if ".." in raw or raw.startswith("/") or "\\" in raw:
        raise VSMError(f"Invalid install path: {path!r}", code="VSM-DOMAIN-INVALID")
    for part in raw.split("/"):
        if not part or part in {".", ".."} or any(ch in part for ch in " \t\n\r;|&$`\"'<>(){}[]"):
            raise VSMError(f"Invalid install path: {path!r}", code="VSM-DOMAIN-INVALID")
    return raw


def _install_path_mode(
    client: VirtualminClient,
    domain: str,
    *,
    logger=None,
    version: str | None = None,
    path: str | None = None,
) -> dict[str, Any]:
    """Install SnappyMail under the domain's website path (Roundcube-style)."""
    domain = normalize_domain(domain)
    info = client.get_domain(domain)
    if not info:
        raise ParentMissingError(f"Virtual server not found: {domain}")
    if not info.has_website() and not info.has_feature("dir"):
        # Website check — allow if html_dir known
        if not info.html_dir:
            raise VSMError(
                f"{domain} has no website/HTML directory for path install",
                code="VSM-VIRTUALMIN",
            )

    rel = _normalize_url_path(path)
    html = info.html_dir or (info.home / "public_html" if info.home else None)
    if not html or not info.home:
        raise VSMError(f"Cannot resolve HTML directory for {domain}", code="VSM-VIRTUALMIN")

    docroot = html if not rel else html / rel
    docroot.mkdir(parents=True, exist_ok=True)

    mp = manifest_path_for_home(info.home)
    existing = load_manifest(mp)
    if existing and existing.managed and looks_like_snappymail(docroot):
        raise AlreadyInstalledError(
            f"SnappyMail already installed for {domain} at path /{rel or ''}. "
            "Use upgrade, repair or remove."
        )
    # Also refuse if a managed subserver install exists for this mail parent
    if not info.parent and info.has_feature("mail"):
        wm = webmail_domain_for(domain)
        sub = client.get_domain(wm)
        if sub and sub.home and sub.html_dir:
            man = load_manifest(manifest_path_for_home(sub.home))
            if man and man.managed and looks_like_snappymail(sub.html_dir):
                raise AlreadyInstalledError(
                    f"SnappyMail already installed via webmail subserver {wm}. "
                    "Remove that install first, or use upgrade on the parent."
                )

    mail_identity = domain
    if info.parent:
        mail_identity = info.parent
    elif not info.has_feature("mail"):
        # Path install on a non-mail domain: still whitelist this hostname.
        mail_identity = domain

    topo = discover_mail_topology(prefer_host=None)
    installed_ver = install_fresh(
        docroot,
        parent_domain=mail_identity,
        topo=topo,
        version=version,
        user=info.username,
        group=info.username,
    )

    try:
        rel_doc = str(docroot.relative_to(info.home))
    except ValueError:
        rel_doc = str(docroot)

    url_path = f"/{rel}/" if rel else "/"
    manifest = Manifest(
        managed=True,
        parent_domain=info.parent or domain,
        webmail_domain=domain,
        version=installed_ver,
        installed_at=utc_now_iso(),
        document_root=rel_doc,
        mail_identity_domain=mail_identity,
        install_mode="path",
        install_path=rel,
    )
    save_manifest(mp, manifest)

    if logger:
        audit_event(
            logger,
            action="install",
            parent_domain=domain,
            webmail_domain=domain,
            result="ok",
            version_after=installed_ver,
            manager_version=get_manager_version(),
            install_mode="path",
            install_path=rel,
        )

    return {
        "parent_domain": info.parent or domain,
        "webmail_domain": domain,
        "version": installed_ver,
        "home": str(info.home),
        "document_root": str(docroot),
        "url": f"https://{domain}{url_path}",
        "admin_url": f"https://{domain}{url_path.rstrip('/')}/?Admin",
        "mode": "path",
        "install_mode": "path",
        "install_path": rel,
        "mail_identity_domain": mail_identity,
    }


def _install_subserver_mode(
    client: VirtualminClient,
    parent_domain: str,
    *,
    logger=None,
    version: str | None = None,
    with_letsencrypt: bool = True,
    force_php_fpm: bool = True,
) -> dict[str, Any]:
    parent_domain = normalize_domain(parent_domain)
    webmail = webmail_domain_for(parent_domain)
    parent = _require_parent_with_mail(client, parent_domain)

    existing_manifest = None
    existing_sub = client.get_domain(webmail)
    if existing_sub and existing_sub.home:
        mp = manifest_path_for_home(existing_sub.home)
        existing_manifest = load_manifest(mp)
        if existing_manifest and existing_manifest.managed:
            docroot = existing_sub.html_dir or (existing_sub.home / "public_html")
            if looks_like_snappymail(docroot):
                raise AlreadyInstalledError(
                    f"SnappyMail already installed for {parent_domain} at {webmail}. "
                    "Use upgrade, repair or remove."
                )

    if existing_sub:
        if existing_sub.parent and existing_sub.parent != parent_domain:
            raise SubserverConflictError(
                f"{webmail} exists under parent {existing_sub.parent}, expected {parent_domain}"
            )
        _assert_web_only(existing_sub)
        if not existing_sub.has_website():
            raise SubserverConflictError(f"{webmail} exists but Website feature is off")
        webstack_flavor = existing_sub.web_flavor()
    else:
        profile = client.create_web_only_subserver(
            webmail_domain=webmail,
            parent_domain=parent_domain,
            with_letsencrypt=with_letsencrypt,
        )
        webstack_flavor = profile.flavor
        existing_sub = client.get_domain(webmail)
        if not existing_sub:
            raise VSMError(f"Sub-server {webmail} was not found after create-domain", code="VSM-VIRTUALMIN")
        if existing_sub.has_feature("mail"):
            # Attempt auto-repair once, then fail hard if still on.
            try:
                client.disable_feature(webmail, "mail")
                existing_sub = client.get_domain(webmail) or existing_sub
            except Exception:  # noqa: BLE001
                pass
            if existing_sub.has_feature("mail"):
                raise MailOnSubserverError(
                    f"Provisioning error: {webmail} has Mail ON after create. Aborting."
                )

    assert existing_sub.home and existing_sub.html_dir
    if with_letsencrypt:
        try:
            client.generate_letsencrypt(webmail)
        except Exception as exc:  # noqa: BLE001
            if logger:
                logger.warning("Let's Encrypt provisioning deferred/failed: %s", exc)

    if force_php_fpm:
        try:
            client.modify_web_php(webmail, mode="fpm")
        except Exception as exc:  # noqa: BLE001
            if logger:
                logger.warning("PHP-FPM mode not applied: %s", exc)

    topo = discover_mail_topology(prefer_host=None)
    installed_ver = install_fresh(
        existing_sub.html_dir,
        parent_domain=parent_domain,
        topo=topo,
        version=version,
        user=existing_sub.username or parent.username,
        group=existing_sub.username or parent.username,
    )

    try:
        rel_doc = str(existing_sub.html_dir.relative_to(existing_sub.home))
    except ValueError:
        rel_doc = "public_html"
    manifest = Manifest(
        managed=True,
        parent_domain=parent_domain,
        webmail_domain=webmail,
        version=installed_ver,
        installed_at=utc_now_iso(),
        document_root=rel_doc,
        mail_identity_domain=parent_domain,
        install_mode="subserver",
        install_path="",
    )
    save_manifest(manifest_path_for_home(existing_sub.home), manifest)

    if logger:
        audit_event(
            logger,
            action="install",
            parent_domain=parent_domain,
            webmail_domain=webmail,
            result="ok",
            version_after=installed_ver,
            manager_version=get_manager_version(),
            install_mode="subserver",
        )

    return {
        "parent_domain": parent_domain,
        "webmail_domain": webmail,
        "version": installed_ver,
        "home": str(existing_sub.home),
        "document_root": str(existing_sub.html_dir),
        "url": f"https://{webmail}/",
        "admin_url": f"https://{webmail}/?Admin",
        "mode": "web-only",
        "install_mode": "subserver",
        "webstack": webstack_flavor,
        "mail_identity_domain": parent_domain,
    }


def remove_domain(
    client: VirtualminClient,
    parent_domain: str,
    *,
    remove_subserver: bool = False,
    logger=None,
) -> dict[str, Any]:
    parent_domain = normalize_domain(parent_domain)

    # Path-mode install lives on the domain home itself.
    domain_info = client.get_domain(parent_domain)
    if domain_info and domain_info.home:
        path_man = load_manifest(manifest_path_for_home(domain_info.home))
        if path_man and path_man.managed and path_man.install_mode == "path":
            return _remove_path_mode(
                client,
                domain_info,
                path_man,
                logger=logger,
            )

    webmail = webmail_domain_for(parent_domain)
    sub = client.get_domain(webmail)
    if not sub:
        raise NotManagedError(f"No sub-server {webmail} found")
    if sub.parent and sub.parent != parent_domain:
        raise SubserverConflictError(f"{webmail} parent mismatch")
    if not sub.home:
        raise VSMError("Sub-server home unknown")

    mp = manifest_path_for_home(sub.home)
    man = load_manifest(mp)
    if not man or not man.managed:
        raise NotManagedError(
            f"{webmail} is not marked as managed by virtualmin-snappymail. "
            "Refusing remove to avoid accidental data loss. Use adopt first if appropriate."
        )

    docroot = sub.html_dir or (sub.home / "public_html")
    # Preserve backup of data before wiping app
    backup_note = None
    if docroot.is_dir() and looks_like_snappymail(docroot):
        backup_dir = sub.home / ".snappymail-backups"
        backup_dir.mkdir(parents=True, exist_ok=True)
        stamp = utc_now_iso().replace(":", "")
        archive = backup_dir / f"pre-remove-{stamp}.tar.gz"
        import tarfile

        with tarfile.open(archive, "w:gz") as tar:
            tar.add(docroot, arcname="public_html")
        backup_note = str(archive)
        # Remove application files only
        for child in list(docroot.iterdir()):
            if child.is_dir():
                shutil.rmtree(child)
            else:
                child.unlink()
        # Leave a placeholder
        (docroot / "index.html").write_text(
            "<!-- SnappyMail removed by virtualmin-snappymail -->\n", encoding="utf-8"
        )

    if mp.is_file():
        mp.unlink()

    deleted_sub = False
    if remove_subserver:
        client.delete_domain(webmail)
        deleted_sub = True

    if logger:
        audit_event(
            logger,
            action="remove",
            parent_domain=parent_domain,
            webmail_domain=webmail,
            result="ok",
            remove_subserver=deleted_sub,
            backup=backup_note,
        )

    return {
        "parent_domain": parent_domain,
        "webmail_domain": webmail,
        "application_removed": True,
        "subserver_removed": deleted_sub,
        "backup": backup_note,
        "install_mode": "subserver",
    }


def _remove_path_mode(
    client: VirtualminClient,
    domain_info: DomainInfo,
    man: Manifest,
    *,
    logger=None,
) -> dict[str, Any]:
    domain = domain_info.name
    html = domain_info.html_dir or (domain_info.home / "public_html" if domain_info.home else None)
    if not html or not domain_info.home:
        raise VSMError(f"Cannot resolve HTML directory for {domain}", code="VSM-VIRTUALMIN")
    rel = (man.install_path or "").strip().strip("/")
    docroot = html if not rel else html / rel

    backup_note = None
    if docroot.is_dir() and looks_like_snappymail(docroot):
        backup_dir = domain_info.home / ".snappymail-backups"
        backup_dir.mkdir(parents=True, exist_ok=True)
        stamp = utc_now_iso().replace(":", "")
        archive = backup_dir / f"pre-remove-path-{stamp}.tar.gz"
        import tarfile

        with tarfile.open(archive, "w:gz") as tar:
            tar.add(docroot, arcname=rel or "public_html")
        backup_note = str(archive)
        if not rel:
            # Top-level path install: clear app files, keep directory
            for child in list(docroot.iterdir()):
                if child.is_dir():
                    shutil.rmtree(child)
                else:
                    child.unlink()
            (docroot / "index.html").write_text(
                "<!-- SnappyMail removed by virtualmin-snappymail -->\n", encoding="utf-8"
            )
        else:
            shutil.rmtree(docroot)

    mp = manifest_path_for_home(domain_info.home)
    if mp.is_file():
        mp.unlink()

    if logger:
        audit_event(
            logger,
            action="remove",
            parent_domain=domain,
            webmail_domain=domain,
            result="ok",
            install_mode="path",
            backup=backup_note,
        )

    return {
        "parent_domain": man.parent_domain or domain,
        "webmail_domain": domain,
        "application_removed": True,
        "subserver_removed": False,
        "backup": backup_note,
        "install_mode": "path",
        "install_path": rel,
    }


def _https_status(hostname: str) -> str:
    try:
        ctx = ssl.create_default_context()
        with socket.create_connection((hostname, 443), timeout=5) as sock:
            with ctx.wrap_socket(sock, server_hostname=hostname) as ssock:
                ssock.getpeercert()
                return "OK"
    except Exception:
        # Try HTTP as degraded signal
        try:
            urllib.request.urlopen(f"https://{hostname}/", timeout=5)  # noqa: S310
            return "OK"
        except Exception:
            return "FAIL"


def _endpoint_status(host: str | None, port: int | None) -> str:
    if not host or not port:
        return "N/A"
    return "OK" if probe_tcp(host, port) else "FAIL"


def status_for_domain(client: VirtualminClient, parent_domain: str) -> StatusRow:
    parent_domain = coerce_mail_parent_domain(parent_domain)
    topo = discover_mail_topology()

    # Path-mode install on the domain itself (Manage Web Apps path option)
    info = client.get_domain(parent_domain)
    if info and info.home:
        man = load_manifest(manifest_path_for_home(info.home))
        if man and man.managed and man.install_mode == "path":
            html = info.html_dir or (info.home / "public_html")
            rel = (man.install_path or "").strip().strip("/")
            docroot = html if not rel else html / rel
            ver = detect_installed_version(docroot) if looks_like_snappymail(docroot) else "-"
            return StatusRow(
                domain=parent_domain,
                snappymail=ver or "-",
                https=_https_status(parent_domain),
                imap=_endpoint_status(topo.imap.host if topo.imap else None, topo.imap.port if topo.imap else None),
                smtp=_endpoint_status(topo.smtp.host if topo.smtp else None, topo.smtp.port if topo.smtp else None),
                mode="path",
                webmail_domain=parent_domain,
                managed=True,
            )

    webmail = webmail_domain_for(parent_domain)
    sub = client.get_domain(webmail)
    if not sub:
        return StatusRow(
            domain=parent_domain,
            snappymail="-",
            https="-",
            imap=_endpoint_status(topo.imap.host if topo.imap else None, topo.imap.port if topo.imap else None),
            smtp=_endpoint_status(topo.smtp.host if topo.smtp else None, topo.smtp.port if topo.smtp else None),
            mode="missing",
            webmail_domain=webmail,
        )

    docroot = sub.html_dir or (sub.home / "public_html" if sub.home else None)
    ver = detect_installed_version(docroot) if docroot and looks_like_snappymail(docroot) else "-"
    man = load_manifest(manifest_path_for_home(sub.home)) if sub.home else None
    mode = "web-only" if sub.is_web_only() else ("mail-on" if sub.has_feature("mail") else "partial")
    return StatusRow(
        domain=parent_domain,
        snappymail=ver or "-",
        https=_https_status(webmail),
        imap=_endpoint_status(topo.imap.host if topo.imap else None, topo.imap.port if topo.imap else None),
        smtp=_endpoint_status(topo.smtp.host if topo.smtp else None, topo.smtp.port if topo.smtp else None),
        mode=mode,
        webmail_domain=webmail,
        managed=bool(man and man.managed),
    )


def status_all(client: VirtualminClient) -> list[StatusRow]:
    rows: list[StatusRow] = []
    # Prefer domains that have mail feature as parents
    try:
        proc = client.run(["list-domains", "--with-feature", "mail", "--toplevel", "--name-only"], check=False)
        parents = [
            nd
            for x in (proc.stdout or "").splitlines()
            if x.strip()
            for nd in (try_normalize_domain(x),)
            if nd
        ]
    except Exception:  # noqa: BLE001
        parents = []
        for d in client.list_domains_multiline():
            if not d.parent and d.has_feature("mail"):
                parents.append(d.name)
    for p in parents:
        rows.append(status_for_domain(client, p))
    return rows


def diagnose_domain(client: VirtualminClient, parent_domain: str) -> list[CheckResult]:
    parent_domain = coerce_mail_parent_domain(parent_domain)
    webmail = webmail_domain_for(parent_domain)
    checks: list[CheckResult] = []

    parent = client.get_domain(parent_domain)
    if not parent:
        detail = parent_domain
        close = suggest_domains(parent_domain, list_mail_parents(client))
        if close:
            detail = f"{parent_domain} (did you mean: {', '.join(close)}?)"
        checks.append(CheckResult("parent_exists", False, detail))
        return checks
    checks.append(CheckResult("parent_exists", True, parent_domain))
    checks.append(CheckResult("parent_mail_enabled", parent.has_feature("mail"), "mail" if parent.has_feature("mail") else "missing"))
    checks.append(CheckResult("parent_unix_user", bool(parent.username), parent.username or ""))
    checks.append(CheckResult("parent_home", bool(parent.home and parent.home.exists()), str(parent.home or "")))

    sub = client.get_domain(webmail)
    checks.append(CheckResult("subserver_exists", bool(sub), webmail))
    if not sub:
        return checks
    checks.append(CheckResult("parent_child_relation", sub.parent == parent_domain, f"parent={sub.parent}"))
    checks.append(CheckResult("subserver_is_subserver", sub.is_subserver(), sub.domain_type or ""))
    checks.append(CheckResult("subserver_web_enabled", sub.has_website(), "web|nginx"))
    checks.append(
        CheckResult(
            "subserver_webstack",
            sub.web_flavor() in {"apache", "nginx"},
            sub.web_flavor(),
        )
    )
    checks.append(CheckResult("subserver_mail_disabled", not sub.has_feature("mail"), "mail_off" if not sub.has_feature("mail") else "MAIL_ON"))
    checks.append(CheckResult("subserver_web_only", sub.is_web_only(), "ok" if sub.is_web_only() else "not-web-only"))

    docroot = sub.html_dir or (sub.home / "public_html" if sub.home else None)
    checks.append(CheckResult("document_root_exists", bool(docroot and docroot.exists()), str(docroot or "")))
    snappy = bool(docroot and looks_like_snappymail(docroot))
    checks.append(CheckResult("snappymail_present", snappy, detect_installed_version(docroot) if snappy else ""))
    man = load_manifest(manifest_path_for_home(sub.home)) if sub.home else None
    checks.append(CheckResult("manifest_present", bool(man), str(manifest_path_for_home(sub.home)) if sub.home else ""))
    if man:
        checks.append(CheckResult("manifest_parent_match", man.parent_domain == parent_domain, man.parent_domain))

    if sub.username and docroot and docroot.exists():
        import os
        import pwd

        try:
            st = docroot.stat()
            owner = pwd.getpwuid(st.st_uid).pw_name
            checks.append(CheckResult("ownership", owner == sub.username, f"{owner} vs {sub.username}"))
        except KeyError:
            checks.append(CheckResult("ownership", False, "owner lookup failed"))

    # PHP
    php = shutil.which("php")
    checks.append(CheckResult("php_binary", bool(php), php or "missing"))
    if php and docroot and (docroot / "index.php").is_file():
        checks.append(CheckResult("php_index_readable", True, "index.php"))

    # DNS
    try:
        socket.getaddrinfo(webmail, 443)
        checks.append(CheckResult("dns_resolves", True, webmail))
    except socket.gaierror as exc:
        checks.append(CheckResult("dns_resolves", False, str(exc)))

    checks.append(CheckResult("https_responds", _https_status(webmail) == "OK", webmail))

    topo = discover_mail_topology()
    checks.append(
        CheckResult(
            "imap_endpoint",
            bool(topo.imap and probe_tcp(topo.imap.host, topo.imap.port)),
            topo.imap.label() if topo.imap else "undiscovered",
        )
    )
    checks.append(
        CheckResult(
            "smtp_endpoint",
            bool(topo.smtp and probe_tcp(topo.smtp.host, topo.smtp.port)),
            topo.smtp.label() if topo.smtp else "undiscovered",
        )
    )

    # SnappyMail domain ini coherence + whitelist validity
    if docroot and snappy:
        ini = docroot / "data" / "_data_" / "_default_" / "domains" / f"{parent_domain}.ini"
        checks.append(CheckResult("snappymail_domain_ini", ini.is_file(), str(ini)))
        if ini.is_file():
            raw_wl = read_domain_ini_white_list(ini)
            tokens = parse_white_list_value(raw_wl or "")
            bad = invalid_white_list_tokens(tokens, parent_domain=parent_domain)
            if raw_wl is None:
                checks.append(CheckResult("snappymail_white_list", True, "absent (allow all)"))
            elif not tokens:
                checks.append(CheckResult("snappymail_white_list", True, 'empty (allow all)'))
            elif bad:
                checks.append(
                    CheckResult(
                        "snappymail_white_list",
                        False,
                        f"invalid tokens {bad!r} — use full email, local-part, @domain, or empty; "
                        f"run: virtualmin-snappymail repair {parent_domain}",
                    )
                )
            else:
                checks.append(CheckResult("snappymail_white_list", True, f"{len(tokens)} token(s)"))

        pwd_file = docroot / "data" / "_data_" / "_default_" / "admin_password.txt"
        checks.append(
            CheckResult(
                "snappymail_admin_password_file",
                pwd_file.is_file(),
                str(pwd_file) if pwd_file.is_file() else f"missing — open https://{webmail}/?Admin once to generate",
            )
        )

    return checks


def resolve_snappy_install(
    client: VirtualminClient, parent_domain: str
) -> dict[str, Any]:
    """Locate SnappyMail document root / URL for a mail parent (subserver or path mode)."""
    parent_domain = coerce_mail_parent_domain(parent_domain)
    info = client.get_domain(parent_domain)
    if not info:
        close = suggest_domains(parent_domain, list_mail_parents(client))
        hint = f" Did you mean: {', '.join(close)}?" if close else ""
        raise ParentMissingError(f"Virtual server not found: {parent_domain}.{hint}")

    # Path-mode on the parent itself
    if info.home:
        man = load_manifest(manifest_path_for_home(info.home))
        if man and man.managed and man.install_mode == "path":
            html = info.html_dir or (info.home / "public_html")
            rel = (man.install_path or "").strip().strip("/")
            docroot = html if not rel else html / rel
            url_path = f"/{rel}" if rel else ""
            return {
                "parent_domain": parent_domain,
                "webmail_domain": parent_domain,
                "install_mode": "path",
                "document_root": docroot,
                "url": f"https://{parent_domain}{url_path}/",
                "admin_url": f"https://{parent_domain}{url_path}/?Admin",
                "present": looks_like_snappymail(docroot),
            }

    webmail = webmail_domain_for(parent_domain)
    sub = client.get_domain(webmail)
    if not sub:
        raise NotManagedError(
            f"No webmail subserver {webmail} for {parent_domain}. "
            f"Install with: virtualmin-snappymail install {parent_domain}"
        )
    docroot = sub.html_dir or (sub.home / "public_html" if sub.home else None)
    if not docroot:
        raise NotManagedError(f"Could not resolve HTML directory for {webmail}")
    return {
        "parent_domain": parent_domain,
        "webmail_domain": webmail,
        "install_mode": "subserver",
        "document_root": docroot,
        "url": f"https://{webmail}/",
        "admin_url": f"https://{webmail}/?Admin",
        "present": looks_like_snappymail(docroot),
    }


def admin_password_info(client: VirtualminClient, parent_domain: str) -> dict[str, Any]:
    """Show where SnappyMail admin credentials live (does not invent a password)."""
    loc = resolve_snappy_install(client, parent_domain)
    docroot = Path(loc["document_root"])
    pwd_path = docroot / "data" / "_data_" / "_default_" / "admin_password.txt"
    app_ini = docroot / "data" / "_data_" / "_default_" / "configs" / "application.ini"
    password: str | None = None
    if pwd_path.is_file():
        password = pwd_path.read_text(encoding="utf-8", errors="replace").strip() or None
    notes: list[str] = []
    if not loc["present"]:
        notes.append("Document root does not look like SnappyMail yet.")
    if password is None:
        notes.append(
            f"admin_password.txt missing — open {loc['admin_url']} once as root/admin "
            "to let SnappyMail generate it, then re-run this command."
        )
        notes.append(
            "Login user is always 'admin'. To regenerate: comment out admin_password in "
            f"{app_ini} (if present) and reopen {loc['admin_url']}."
        )
    return {
        "parent_domain": loc["parent_domain"],
        "webmail_domain": loc["webmail_domain"],
        "install_mode": loc["install_mode"],
        "document_root": str(docroot),
        "admin_url": loc["admin_url"],
        "admin_user": "admin",
        "admin_password_file": str(pwd_path),
        "admin_password": password,
        "notes": notes,
    }


def repair_domain(client: VirtualminClient, parent_domain: str, *, logger=None) -> dict[str, Any]:
    parent_domain = coerce_mail_parent_domain(parent_domain)
    webmail = webmail_domain_for(parent_domain)
    parent = _require_parent_with_mail(client, parent_domain)
    sub = client.get_domain(webmail)
    if not sub or not sub.home or not sub.html_dir:
        raise NotManagedError(f"Sub-server {webmail} missing; run install")

    actions: list[str] = []
    if sub.has_feature("mail"):
        client.disable_feature(webmail, "mail")
        actions.append("disabled_mail_on_subserver")
        sub = client.get_domain(webmail) or sub
        if sub.has_feature("mail"):
            raise MailOnSubserverError("Could not disable mail on webmail subserver")

    if not sub.has_feature("dir"):
        try:
            client.enable_feature(webmail, "dir")
            actions.append("enabled_dir")
        except Exception as exc:  # noqa: BLE001
            actions.append(f"enable_dir_failed:{exc}")

    if not sub.has_website():
        try:
            resolved = client.resolve_web_only_features(parent=parent)
            web_feats = [f for f in resolved if f not in ("dir", "dns", "logrotate")]
            if web_feats:
                client.enable_feature(webmail, *web_feats)
                actions.append(f"enabled_{'+'.join(web_feats)}")
                sub = client.get_domain(webmail) or sub
        except Exception as exc:  # noqa: BLE001
            actions.append(f"enable_website_failed:{exc}")

    topo = discover_mail_topology()
    docroot = sub.html_dir
    if looks_like_snappymail(docroot):
        configure_domain(docroot, parent_domain=parent_domain, topo=topo)
        actions.append("reconfigured_snappymail_domain")
        apply_permissions(docroot)
        actions.append("fixed_permissions")
        if sub.username:
            apply_ownership(docroot, sub.username, sub.username)
            actions.append("fixed_ownership")

    man_path = manifest_path_for_home(sub.home)
    man = load_manifest(man_path) or Manifest()
    man.managed = True
    man.parent_domain = parent_domain
    man.webmail_domain = webmail
    man.mail_identity_domain = parent_domain
    man.version = detect_installed_version(docroot) or man.version or ""
    if not man.installed_at:
        man.installed_at = utc_now_iso()
    save_manifest(man_path, man)
    actions.append("manifest_upserted")

    if logger:
        audit_event(logger, action="repair", parent_domain=parent_domain, webmail_domain=webmail, result="ok", actions=actions)

    return {"parent_domain": parent_domain, "webmail_domain": webmail, "actions": actions}


def upgrade_domain(client: VirtualminClient, parent_domain: str, *, version: str | None = None, logger=None) -> dict[str, Any]:
    parent_domain = normalize_domain(parent_domain)

    domain_info = client.get_domain(parent_domain)
    if domain_info and domain_info.home:
        path_man = load_manifest(manifest_path_for_home(domain_info.home))
        if path_man and path_man.managed and path_man.install_mode == "path":
            html = domain_info.html_dir or (domain_info.home / "public_html")
            rel = (path_man.install_path or "").strip().strip("/")
            docroot = html if not rel else html / rel
            mail_identity = path_man.mail_identity_domain or parent_domain
            topo = discover_mail_topology()
            old, new, backup = upgrade_inplace(
                docroot,
                parent_domain=mail_identity,
                topo=topo,
                version=version,
                user=domain_info.username,
                group=domain_info.username,
            )
            path_man.version = new
            path_man.managed = True
            save_manifest(manifest_path_for_home(domain_info.home), path_man)
            if logger:
                audit_event(
                    logger,
                    action="upgrade",
                    parent_domain=parent_domain,
                    webmail_domain=parent_domain,
                    result="ok",
                    version_before=old,
                    version_after=new,
                    backup=str(backup) if backup else None,
                    install_mode="path",
                )
            return {
                "parent_domain": parent_domain,
                "webmail_domain": parent_domain,
                "version_before": old,
                "version_after": new,
                "backup": str(backup) if backup else None,
                "install_mode": "path",
            }

    webmail = webmail_domain_for(parent_domain)
    _require_parent_with_mail(client, parent_domain)
    sub = client.get_domain(webmail)
    if not sub or not sub.html_dir:
        raise NotManagedError(f"No installation for {parent_domain}")
    topo = discover_mail_topology()
    old, new, backup = upgrade_inplace(
        sub.html_dir,
        parent_domain=parent_domain,
        topo=topo,
        version=version,
        user=sub.username,
        group=sub.username,
    )
    if sub.home:
        man = load_manifest(manifest_path_for_home(sub.home)) or Manifest(
            parent_domain=parent_domain, webmail_domain=webmail
        )
        man.version = new
        man.managed = True
        man.install_mode = man.install_mode or "subserver"
        save_manifest(manifest_path_for_home(sub.home), man)
    if logger:
        audit_event(
            logger,
            action="upgrade",
            parent_domain=parent_domain,
            webmail_domain=webmail,
            result="ok",
            version_before=old,
            version_after=new,
            backup=str(backup) if backup else None,
        )
    return {
        "parent_domain": parent_domain,
        "webmail_domain": webmail,
        "version_before": old,
        "version_after": new,
        "backup": str(backup) if backup else None,
        "install_mode": "subserver",
    }


@dataclass
class DiscoveryHit:
    webmail_domain: str
    parent_domain: str
    path: str
    version: str | None
    has_manifest: bool
    managed: bool
    source: str

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def discover_installations(client: VirtualminClient) -> list[DiscoveryHit]:
    hits: list[DiscoveryHit] = []
    seen: set[str] = set()

    # 1) Subservers named webmail.*
    for d in client.list_domains_multiline():
        if not d.name.startswith("webmail."):
            continue
        parent = d.parent or parent_from_webmail(d.name)
        docroot = d.html_dir or (d.home / "public_html" if d.home else None)
        man = load_manifest(manifest_path_for_home(d.home)) if d.home else None
        snappy = bool(docroot and looks_like_snappymail(docroot))
        if not snappy and not man:
            continue
        hits.append(
            DiscoveryHit(
                webmail_domain=d.name,
                parent_domain=parent,
                path=str(docroot or d.home or ""),
                version=detect_installed_version(docroot) if docroot else None,
                has_manifest=bool(man),
                managed=bool(man and man.managed),
                source="virtualmin-webmail-subserver",
            )
        )
        seen.add(d.name)

    # 2) Walk children of mail domains for manifests / snappy trees
    try:
        proc = client.run(["list-domains", "--with-feature", "mail", "--toplevel", "--name-only"], check=False)
        parents = [x.strip() for x in (proc.stdout or "").splitlines() if x.strip()]
    except Exception:  # noqa: BLE001
        parents = []
    for p in parents:
        for child in client.list_children(p):
            if child in seen:
                continue
            info = client.get_domain(child)
            if not info or not info.home:
                continue
            man = load_manifest(manifest_path_for_home(info.home))
            docroot = info.html_dir or (info.home / "public_html")
            if man or looks_like_snappymail(docroot):
                hits.append(
                    DiscoveryHit(
                        webmail_domain=child,
                        parent_domain=p,
                        path=str(docroot),
                        version=detect_installed_version(docroot),
                        has_manifest=bool(man),
                        managed=bool(man and man.managed),
                        source="scan-children",
                    )
                )
                seen.add(child)
    return hits


def adopt_domain(client: VirtualminClient, parent_domain: str, *, logger=None) -> dict[str, Any]:
    parent_domain = normalize_domain(parent_domain)
    webmail = webmail_domain_for(parent_domain)
    parent = _require_parent_with_mail(client, parent_domain)
    sub = client.get_domain(webmail)
    if not sub:
        # Try discovery hit with matching parent
        for hit in discover_installations(client):
            if hit.parent_domain == parent_domain:
                sub = client.get_domain(hit.webmail_domain)
                webmail = hit.webmail_domain
                break
    if not sub or not sub.home or not sub.html_dir:
        raise NotManagedError(f"Nothing to adopt for {parent_domain}")
    if sub.parent and sub.parent != parent_domain:
        raise SubserverConflictError("Parent mismatch during adopt")
    _assert_web_only(sub)
    if not looks_like_snappymail(sub.html_dir):
        raise NotManagedError(f"No SnappyMail tree at {sub.html_dir}")

    topo = discover_mail_topology()
    configure_domain(sub.html_dir, parent_domain=parent_domain, topo=topo)
    apply_permissions(sub.html_dir)
    if sub.username:
        apply_ownership(sub.html_dir, sub.username, sub.username)

    man = load_manifest(manifest_path_for_home(sub.home)) or Manifest()
    man.managed = True
    man.deployment = "full-copy"
    man.parent_domain = parent_domain
    man.webmail_domain = webmail
    man.mail_identity_domain = parent_domain
    man.version = detect_installed_version(sub.html_dir) or man.version
    if not man.installed_at:
        man.installed_at = utc_now_iso()
    save_manifest(manifest_path_for_home(sub.home), man)

    if logger:
        audit_event(logger, action="adopt", parent_domain=parent_domain, webmail_domain=webmail, result="ok", version=man.version)

    return {
        "parent_domain": parent_domain,
        "webmail_domain": webmail,
        "version": man.version,
        "adopted": True,
        "destructive_reinstall": False,
    }


def adopt_all(client: VirtualminClient, *, logger=None) -> list[dict[str, Any]]:
    results = []
    for hit in discover_installations(client):
        try:
            results.append(adopt_domain(client, hit.parent_domain, logger=logger))
        except VSMError as exc:
            results.append({"parent_domain": hit.parent_domain, "error": exc.code, "message": exc.message})
    return results

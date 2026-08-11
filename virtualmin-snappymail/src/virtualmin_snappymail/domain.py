"""Domain validation and naming helpers."""

from __future__ import annotations

import difflib
import re
from collections.abc import Iterable

from .errors import DomainInvalidError

# RFC-ish DNS label validation — reject shell metacharacters and path traversal.
_LABEL = r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?"
_DOMAIN_RE = re.compile(rf"^(?:{_LABEL}\.)+{_LABEL}$", re.IGNORECASE)
_WEBMAIL_PREFIX = "webmail."


def normalize_domain(value: str) -> str:
    if value is None:
        raise DomainInvalidError("Domain is required")
    domain = value.strip().lower().rstrip(".")
    if not domain:
        raise DomainInvalidError("Domain is empty")
    if len(domain) > 253:
        raise DomainInvalidError("Domain exceeds 253 characters")
    if ".." in domain or "/" in domain or "\\" in domain:
        raise DomainInvalidError(f"Domain contains illegal path characters: {value!r}")
    if any(ch in domain for ch in " \t\n\r;|&$`\"'<>(){}[]"):
        raise DomainInvalidError(f"Domain contains illegal characters: {value!r}")
    if not _DOMAIN_RE.match(domain):
        raise DomainInvalidError(f"Invalid domain name: {value!r}")
    return domain


def try_normalize_domain(value: str) -> str | None:
    """Like :func:`normalize_domain`, but returns ``None`` for noise tokens.

    Soft-skips Virtualmin banners and non-FQDN names such as ``localhost``.
    """
    try:
        return normalize_domain(value)
    except DomainInvalidError:
        return None


def webmail_domain_for(parent_domain: str) -> str:
    parent = normalize_domain(parent_domain)
    if parent.startswith(_WEBMAIL_PREFIX):
        raise DomainInvalidError(
            f"Parent domain must not already be a webmail host: {parent}"
        )
    return f"{_WEBMAIL_PREFIX}{parent}"


def parent_from_webmail(webmail_domain: str) -> str:
    webmail = normalize_domain(webmail_domain)
    if not webmail.startswith(_WEBMAIL_PREFIX):
        raise DomainInvalidError(f"Not a webmail subdomain: {webmail}")
    parent = webmail[len(_WEBMAIL_PREFIX) :]
    return normalize_domain(parent)


def is_webmail_hostname(domain: str) -> bool:
    try:
        d = normalize_domain(domain)
    except DomainInvalidError:
        return False
    return d.startswith(_WEBMAIL_PREFIX) and d.count(".") >= 2


def coerce_mail_parent_domain(value: str) -> str:
    """Accept ``exemplo.com.br`` or ``webmail.exemplo.com.br``; return the mail parent."""
    domain = normalize_domain(value)
    if domain.startswith(_WEBMAIL_PREFIX):
        return parent_from_webmail(domain)
    return domain


def suggest_domains(needle: str, candidates: Iterable[str], *, n: int = 5) -> list[str]:
    """Close matches for typos (e.g. relatosoft → relatasoft)."""
    try:
        needle_n = normalize_domain(needle)
    except DomainInvalidError:
        needle_n = (needle or "").strip().lower()
    pool: list[str] = []
    for c in candidates:
        nd = try_normalize_domain(str(c))
        if nd:
            pool.append(nd)
    # Prefer parents (no webmail. prefix) when suggesting install targets.
    parents = [p for p in pool if not p.startswith(_WEBMAIL_PREFIX)]
    search_in = parents or pool
    return difflib.get_close_matches(needle_n, search_in, n=n, cutoff=0.72)

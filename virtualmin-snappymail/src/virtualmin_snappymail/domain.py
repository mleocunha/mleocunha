"""Domain validation and naming helpers."""

from __future__ import annotations

import re

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

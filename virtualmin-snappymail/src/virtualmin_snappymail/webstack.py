"""Virtualmin website stack detection (Apache vs Nginx).

Virtualmin installs differ:

* Apache core features: ``web`` + ``ssl``
* Nginx plugins: ``virtualmin-nginx`` + ``virtualmin-nginx-ssl``

Important: ``virtualmin help create-domain`` often mentions ``--web`` inside
narrative/examples even when Apache is disabled in module configuration.
Only flags shown as optional parameters ``[--flag]`` are treated as creatable.

Detection order among *creatable* stacks:

1. Parent domain enabled features (follow the parent's live website stack)
2. ``list-features`` availability
3. OS binaries (weak hint)
4. Prefer Nginx when both creatable (common when migrating off Apache)
"""

from __future__ import annotations

import re
from dataclasses import asdict, dataclass
from typing import Iterable

APACHE_SITE = "web"
APACHE_SSL = "ssl"
NGINX_SITE = "virtualmin-nginx"
NGINX_SSL = "virtualmin-nginx-ssl"

APACHE_FEATURES = (APACHE_SITE, APACHE_SSL)
NGINX_FEATURES = (NGINX_SITE, NGINX_SSL)
BASE_FEATURES = ("dir", "dns", "logrotate")
FORBIDDEN = frozenset({"mail", "spam", "virus"})
WEBSITE_CODES = frozenset(APACHE_FEATURES + NGINX_FEATURES)


@dataclass(frozen=True)
class WebStackProfile:
    """Resolved website stack for provisioning a web-only subserver."""

    flavor: str  # apache | nginx | unknown
    site_feature: str | None
    ssl_feature: str | None
    create_features: tuple[str, ...] = ()
    acme_flag: str | None = None  # acme | letsencrypt | None
    sources: tuple[str, ...] = ()
    notes: tuple[str, ...] = ()

    def to_dict(self) -> dict:
        return asdict(self)

    @property
    def has_website(self) -> bool:
        return self.site_feature is not None


def parse_create_domain_flags(help_text: str) -> set[str]:
    """Extract creatable flags from create-domain help.

    Prefer bracketed optional flags ``[--name]`` from the command-line help
    section. Falling back to all ``--name`` tokens is unsafe because POD
    examples often mention disabled features such as ``--web``.
    """
    if not help_text:
        return set()
    bracketed = set(
        re.findall(r"\[--([A-Za-z0-9-]+)(?:\s|/|=|[^\]]*)?\]", help_text)
    )
    if bracketed:
        return bracketed
    return set(re.findall(r"--([A-Za-z0-9-]+)", help_text))


def flavor_from_features(features: Iterable[str]) -> str:
    feats = set(features)
    has_nginx = bool(feats & set(NGINX_FEATURES))
    has_apache = APACHE_SITE in feats  # require site feature, not ssl alone
    if has_nginx and not has_apache:
        return "nginx"
    if has_apache and not has_nginx:
        return "apache"
    if has_nginx and has_apache:
        return "mixed"
    return "unknown"


def detect_web_stack(
    *,
    create_flags: Iterable[str] | None = None,
    parent_features: Iterable[str] | None = None,
    list_feature_codes: Iterable[str] | None = None,
    os_has_nginx: bool | None = None,
    os_has_apache: bool | None = None,
    extra_features: Iterable[str] = (),
) -> WebStackProfile:
    """Resolve which website features to enable for a web-only subserver."""
    flags = set(create_flags or [])
    parent = set(parent_features or [])
    listed = set(list_feature_codes or [])
    sources: list[str] = []
    notes: list[str] = []

    # Creatable stacks = present in create-domain optional flags.
    # Tighten with list-features when that listing exists and is non-empty.
    creatable_nginx = NGINX_SITE in flags
    creatable_apache = APACHE_SITE in flags
    if listed:
        if NGINX_SITE in listed:
            creatable_nginx = creatable_nginx or NGINX_SITE in flags or NGINX_SITE in listed
        if APACHE_SITE in listed and APACHE_SITE in flags:
            creatable_apache = True
        # If list-features explicitly omits Apache site while Nginx is listed,
        # do not treat narrative --web as creatable.
        if NGINX_SITE in listed and APACHE_SITE not in listed:
            creatable_apache = False
            creatable_nginx = True
            sources.append("list-features:nginx-not-apache")
        elif APACHE_SITE in listed and NGINX_SITE not in listed:
            creatable_nginx = False
            creatable_apache = APACHE_SITE in flags or APACHE_SITE in listed
            sources.append("list-features:apache-not-nginx")

    # Hard rule from create-domain brackets: if nginx is an optional flag and
    # apache site is NOT, Apache is not creatable on this host.
    if NGINX_SITE in flags and APACHE_SITE not in flags:
        creatable_nginx = True
        creatable_apache = False
        sources.append("create-domain:nginx-only-brackets")
    elif APACHE_SITE in flags and NGINX_SITE not in flags:
        creatable_apache = True
        creatable_nginx = False
        sources.append("create-domain:apache-only-brackets")

    parent_flavor = flavor_from_features(parent)

    flavor = "unknown"
    if parent_flavor == "nginx" and creatable_nginx:
        flavor = "nginx"
        sources.append("parent:nginx")
    elif parent_flavor == "apache" and creatable_apache:
        flavor = "apache"
        sources.append("parent:apache")
    elif parent_flavor == "nginx" and not creatable_nginx and creatable_apache:
        flavor = "apache"
        sources.append("parent:nginx-unavailable-fallback-apache")
        notes.append("Parent looks Nginx but create-domain cannot enable it; using Apache")
    elif parent_flavor == "apache" and not creatable_apache and creatable_nginx:
        flavor = "nginx"
        sources.append("parent:apache-unavailable-fallback-nginx")
        notes.append(
            "Parent features mention Apache web, but --web is not creatable "
            "(disabled in module config); using Nginx"
        )
    elif creatable_nginx and not creatable_apache:
        flavor = "nginx"
        sources.append("creatable:nginx-only")
    elif creatable_apache and not creatable_nginx:
        flavor = "apache"
        sources.append("creatable:apache-only")
    elif creatable_nginx and creatable_apache:
        if os_has_nginx and not os_has_apache:
            flavor = "nginx"
            sources.append("both-creatable+os_nginx")
        elif os_has_apache and not os_has_nginx:
            flavor = "apache"
            sources.append("both-creatable+os_apache")
        else:
            # Dual creatable + dual binaries: prefer Nginx (typical modern Virtualmin).
            flavor = "nginx"
            sources.append("both-creatable+prefer_nginx")
            notes.append("Both Apache and Nginx are creatable; defaulting to Nginx")
    elif os_has_nginx and not os_has_apache:
        flavor = "nginx"
        sources.append("os:nginx")
        notes.append("Inferred from OS binaries only; verify Virtualmin features")
    elif os_has_apache and not os_has_nginx:
        flavor = "apache"
        sources.append("os:apache")
        notes.append("Inferred from OS binaries only; verify Virtualmin features")

    chosen: list[str] = []
    for f in BASE_FEATURES:
        if not flags or f in flags:
            chosen.append(f)

    site = ssl = None
    if flavor == "nginx":
        site, ssl = NGINX_SITE, NGINX_SSL
    elif flavor == "apache":
        site, ssl = APACHE_SITE, APACHE_SSL

    # Never emit a site feature that is not creatable when we know the flag set.
    if site == APACHE_SITE and flags and site not in flags:
        if NGINX_SITE in flags:
            flavor, site, ssl = "nginx", NGINX_SITE, NGINX_SSL
            notes.append("Refused non-creatable --web; switched to Nginx")
            sources.append("guard:refuse-web")
        else:
            site = ssl = None
            flavor = "unknown"
            notes.append("--web is not a creatable create-domain flag on this host")
    if site == NGINX_SITE and flags and site not in flags:
        if APACHE_SITE in flags:
            flavor, site, ssl = "apache", APACHE_SITE, APACHE_SSL
            notes.append("Refused non-creatable Nginx flag; switched to Apache")
            sources.append("guard:refuse-nginx")
        else:
            site = ssl = None
            flavor = "unknown"

    if site:
        chosen.append(site)
        if ssl and (not flags or ssl in flags or ssl in listed):
            chosen.append(ssl)
        elif ssl and site == NGINX_SITE:
            # nginx-ssl usually ships with the plugin; include when site is creatable
            chosen.append(ssl)

    for f in extra_features:
        if f in FORBIDDEN or f in chosen:
            continue
        if not flags or f in flags:
            chosen.append(f)

    acme = None
    if "acme" in flags:
        acme = "acme"
    elif "letsencrypt" in flags:
        acme = "letsencrypt"

    if site is None or site not in chosen:
        notes.append(
            "No website feature could be resolved. Enable Apache (web/ssl) or "
            "Nginx (virtualmin-nginx/virtualmin-nginx-ssl) in Virtualmin."
        )
        flavor = "unknown"
        return WebStackProfile(
            flavor=flavor,
            site_feature=None,
            ssl_feature=None,
            create_features=tuple(x for x in chosen if x not in WEBSITE_CODES),
            acme_flag=acme,
            sources=tuple(sources),
            notes=tuple(notes),
        )

    return WebStackProfile(
        flavor=flavor,
        site_feature=site,
        ssl_feature=ssl if ssl in chosen else None,
        create_features=tuple(chosen),
        acme_flag=acme,
        sources=tuple(sources),
        notes=tuple(notes),
    )


def domain_has_website(features: Iterable[str], *, url: str | None = None) -> bool:
    feats = set(features)
    if feats & WEBSITE_CODES:
        return True
    return bool(url)


def domain_is_web_only(features: Iterable[str], *, url: str | None = None) -> bool:
    return domain_has_website(features, url=url) and "mail" not in set(features)

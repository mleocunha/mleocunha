"""Virtualmin website stack detection (Apache vs Nginx).

Virtualmin can serve sites with either:

* Apache core features: ``web`` + ``ssl``
* Nginx plugins: ``virtualmin-nginx`` + ``virtualmin-nginx-ssl``

A single host may expose both in ``create-domain`` help (transition / dual
install), but a given Virtual Server normally uses one stack. Detection order:

1. Parent domain enabled features (strongest signal for a child subserver)
2. Flags advertised by ``virtualmin help create-domain``
3. ``virtualmin list-features --parent …`` when available
4. Local binaries (``nginx`` / ``apache2`` / ``httpd``) as weak hints only

Mail is never part of the webmail subserver feature set.
"""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
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


def flavor_from_features(features: Iterable[str]) -> str:
    feats = set(features)
    has_nginx = bool(feats & set(NGINX_FEATURES))
    has_apache = APACHE_SITE in feats or APACHE_SSL in feats
    if has_nginx and not has_apache:
        return "nginx"
    if has_apache and not has_nginx:
        return "apache"
    if has_nginx and has_apache:
        # Prefer the site feature that is present as primary website marker.
        if NGINX_SITE in feats and APACHE_SITE not in feats:
            return "nginx"
        if APACHE_SITE in feats and NGINX_SITE not in feats:
            return "apache"
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

    parent_flavor = flavor_from_features(parent)
    flags_nginx = NGINX_SITE in flags
    flags_apache = APACHE_SITE in flags
    listed_nginx = NGINX_SITE in listed
    listed_apache = APACHE_SITE in listed

    flavor = "unknown"
    if parent_flavor in {"nginx", "apache"}:
        flavor = parent_flavor
        sources.append(f"parent_features:{parent_flavor}")
    elif parent_flavor == "mixed":
        # Parent oddly has both; prefer nginx plugin if create-domain allows it,
        # otherwise apache.
        if flags_nginx or listed_nginx:
            flavor = "nginx"
            sources.append("parent_mixed+nginx_available")
        elif flags_apache or listed_apache:
            flavor = "apache"
            sources.append("parent_mixed+apache_available")
        else:
            flavor = "nginx" if (os_has_nginx and not os_has_apache) else "apache"
            sources.append("parent_mixed+os_hint")
            notes.append("Parent features include both Apache and Nginx markers")
    elif flags_nginx and not flags_apache:
        flavor = "nginx"
        sources.append("create-domain:nginx-only")
    elif flags_apache and not flags_nginx:
        flavor = "apache"
        sources.append("create-domain:apache-only")
    elif flags_nginx and flags_apache:
        # Dual available — prefer stack matching OS webserver if clear.
        if os_has_nginx and not os_has_apache:
            flavor = "nginx"
            sources.append("create-domain:both+os_nginx")
        elif os_has_apache and not os_has_nginx:
            flavor = "apache"
            sources.append("create-domain:both+os_apache")
        elif listed_nginx and not listed_apache:
            flavor = "nginx"
            sources.append("create-domain:both+list-features_nginx")
        elif listed_apache and not listed_nginx:
            flavor = "apache"
            sources.append("create-domain:both+list-features_apache")
        else:
            # Conservative default on dual installs: Nginx plugin is the usual
            # reason Apache --web is disabled in module config.
            flavor = "nginx" if flags_nginx else "apache"
            sources.append("create-domain:both+prefer_nginx")
            notes.append(
                "Both Apache and Nginx create-domain flags are available; "
                "selected based on heuristics — override by aligning parent features"
            )
    elif listed_nginx and not listed_apache:
        flavor = "nginx"
        sources.append("list-features:nginx")
    elif listed_apache and not listed_nginx:
        flavor = "apache"
        sources.append("list-features:apache")
    elif os_has_nginx and not os_has_apache:
        flavor = "nginx"
        sources.append("os:nginx")
        notes.append("Inferred from OS binaries only; verify Virtualmin features")
    elif os_has_apache and not os_has_nginx:
        flavor = "apache"
        sources.append("os:apache")
        notes.append("Inferred from OS binaries only; verify Virtualmin features")

    # Build feature list
    chosen: list[str] = []
    for f in BASE_FEATURES:
        if not flags or f in flags:
            chosen.append(f)

    site = ssl = None
    if flavor == "nginx":
        if flags and NGINX_SITE not in flags and APACHE_SITE in flags:
            # create-domain cannot use nginx; fall back to apache if possible
            flavor = "apache"
            notes.append("Nginx preferred but not in create-domain help; using Apache")
            sources.append("fallback:apache")
        else:
            site, ssl = NGINX_SITE, NGINX_SSL
    if flavor == "apache":
        if flags and APACHE_SITE not in flags and NGINX_SITE in flags:
            flavor = "nginx"
            site, ssl = NGINX_SITE, NGINX_SSL
            notes.append("Apache preferred but not in create-domain help; using Nginx")
            sources.append("fallback:nginx")
        else:
            site, ssl = APACHE_SITE, APACHE_SSL

    if site is None:
        # Last chance: pick whatever create-domain documents.
        if NGINX_SITE in flags:
            flavor, site, ssl = "nginx", NGINX_SITE, NGINX_SSL
            sources.append("last_resort:nginx_flag")
        elif APACHE_SITE in flags:
            flavor, site, ssl = "apache", APACHE_SITE, APACHE_SSL
            sources.append("last_resort:apache_flag")

    if site:
        if not flags or site in flags:
            chosen.append(site)
        if ssl and (not flags or ssl in flags):
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
        site_feature=site if site in chosen else None,
        ssl_feature=ssl if ssl and ssl in chosen else None,
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

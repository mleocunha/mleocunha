"""Virtualmin website stack detection (Apache vs Nginx).

Authoritative signals (in order):

1. ``virtualmin list-features --multiline`` → ``Enabled: Yes/No``
2. Bracketed optional flags ``[--flag]`` from ``virtualmin help create-domain``
   (POD narrative examples that mention ``--web`` are ignored)
3. Parent domain live features (only among *enabled/creatable* stacks)
4. OS binaries as a weak hint

Never emit ``--web``/``--ssl`` unless Apache is Enabled in list-features
(or present as a bracketed create-domain flag when list-features is unavailable).
"""

from __future__ import annotations

import re
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
    flavor: str  # apache | nginx | unknown
    site_feature: str | None
    ssl_feature: str | None
    create_features: tuple[str, ...] = ()
    acme_flag: str | None = None
    sources: tuple[str, ...] = ()
    notes: tuple[str, ...] = ()
    debug: dict = field(default_factory=dict)

    def to_dict(self) -> dict:
        return asdict(self)

    @property
    def has_website(self) -> bool:
        return self.site_feature is not None


def parse_create_domain_flags(help_text: str) -> set[str]:
    """Extract creatable flags from create-domain help (bracketed only)."""
    if not help_text:
        return set()
    bracketed = set(
        re.findall(r"\[--([A-Za-z0-9-]+)(?:\s|/|=|[^\]]*)?\]", help_text)
    )
    # Never fall back to bare --flag scan: POD examples poison results with --web.
    return bracketed


def parse_list_features_multiline(text: str) -> dict[str, dict[str, str]]:
    """Parse ``virtualmin list-features --multiline`` into feature → attrs."""
    features: dict[str, dict[str, str]] = {}
    current: str | None = None
    header_re = re.compile(r"^(\S\S*)\s*$")
    kv_re = re.compile(r"^\s{2,}([^:]+):\s*(.*)$")
    for line in text.splitlines():
        if not line.strip():
            continue
        if line.startswith(" ") or line.startswith("\t"):
            if current is None:
                continue
            km = kv_re.match(line)
            if km:
                features.setdefault(current, {})[km.group(1).strip().lower()] = km.group(2).strip()
            continue
        hm = header_re.match(line)
        if hm:
            current = hm.group(1).strip()
            features.setdefault(current, {})
    return features


def enabled_feature_codes(list_features_multiline_text: str) -> set[str]:
    parsed = parse_list_features_multiline(list_features_multiline_text)
    enabled: set[str] = set()
    for code, meta in parsed.items():
        val = (meta.get("enabled") or "").strip().lower()
        if val in {"yes", "true", "1"}:
            enabled.add(code)
    return enabled


def flavor_from_features(features: Iterable[str]) -> str:
    feats = set(features)
    has_nginx = bool(feats & set(NGINX_FEATURES))
    has_apache = APACHE_SITE in feats
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
    enabled_features: Iterable[str] | None = None,
    os_has_nginx: bool | None = None,
    os_has_apache: bool | None = None,
    extra_features: Iterable[str] = (),
) -> WebStackProfile:
    """Resolve website features for a web-only subserver."""
    flags = set(create_flags or [])
    parent = set(parent_features or [])
    listed = set(list_feature_codes or [])
    enabled = set(enabled_features) if enabled_features is not None else set()
    sources: list[str] = []
    notes: list[str] = []

    # Creatable website stacks: prefer Enabled=Yes from list-features.
    # Fall back to bracketed create-domain flags.
    if enabled:
        creatable_nginx = NGINX_SITE in enabled
        creatable_apache = APACHE_SITE in enabled
        sources.append("list-features-enabled")
        # If help brackets contradict (feature shown but disabled), trust Enabled.
        if APACHE_SITE in flags and APACHE_SITE not in enabled:
            notes.append("create-domain help mentions --web but feature is Disabled; ignoring")
        if NGINX_SITE in flags and NGINX_SITE not in enabled:
            notes.append("create-domain help mentions nginx but feature is Disabled; ignoring")
    else:
        creatable_nginx = NGINX_SITE in flags
        creatable_apache = APACHE_SITE in flags
        sources.append("create-domain-brackets")
        if listed:
            # name-only listing is weaker; only use to confirm plugins exist
            if NGINX_SITE in listed and APACHE_SITE not in listed:
                creatable_apache = False
                creatable_nginx = True
                sources.append("list-features-name-only:nginx")
            elif APACHE_SITE in listed and NGINX_SITE not in listed:
                creatable_nginx = False
                creatable_apache = True
                sources.append("list-features-name-only:apache")

    # Hard rule from brackets when Enabled map unavailable: nginx-only brackets.
    if not enabled:
        if NGINX_SITE in flags and APACHE_SITE not in flags:
            creatable_nginx, creatable_apache = True, False
            sources.append("brackets:nginx-only")
        elif APACHE_SITE in flags and NGINX_SITE not in flags:
            creatable_apache, creatable_nginx = True, False
            sources.append("brackets:apache-only")

    parent_flavor = flavor_from_features(parent)

    flavor = "unknown"
    if parent_flavor == "nginx" and creatable_nginx:
        flavor = "nginx"
        sources.append("parent:nginx")
    elif parent_flavor == "apache" and creatable_apache:
        flavor = "apache"
        sources.append("parent:apache")
    elif parent_flavor == "apache" and not creatable_apache and creatable_nginx:
        flavor = "nginx"
        sources.append("parent:apache-disabled-fallback-nginx")
        notes.append(
            "Parent features mention Apache, but Apache web is Disabled in Virtualmin; using Nginx"
        )
    elif parent_flavor == "nginx" and not creatable_nginx and creatable_apache:
        flavor = "apache"
        sources.append("parent:nginx-disabled-fallback-apache")
        notes.append("Parent looks Nginx but Nginx feature is Disabled; using Apache")
    elif creatable_nginx and not creatable_apache:
        flavor = "nginx"
        sources.append("creatable:nginx-only")
    elif creatable_apache and not creatable_nginx:
        flavor = "apache"
        sources.append("creatable:apache-only")
    elif creatable_nginx and creatable_apache:
        if os_has_nginx and not os_has_apache:
            flavor = "nginx"
            sources.append("both+os_nginx")
        elif os_has_apache and not os_has_nginx:
            flavor = "apache"
            sources.append("both+os_apache")
        else:
            flavor = "nginx"
            sources.append("both+prefer_nginx")
            notes.append("Both Apache and Nginx are enabled; defaulting to Nginx")
    elif os_has_nginx and not os_has_apache:
        flavor = "nginx"
        sources.append("os:nginx")
        notes.append("Inferred from OS binaries only")
    elif os_has_apache and not os_has_nginx:
        flavor = "apache"
        sources.append("os:apache")
        notes.append("Inferred from OS binaries only")

    site = ssl = None
    if flavor == "nginx":
        site, ssl = NGINX_SITE, NGINX_SSL
    elif flavor == "apache":
        site, ssl = APACHE_SITE, APACHE_SSL

    # Absolute guards — never emit a disabled/non-creatable site feature.
    if site == APACHE_SITE and not creatable_apache:
        if creatable_nginx:
            flavor, site, ssl = "nginx", NGINX_SITE, NGINX_SSL
            notes.append("Blocked non-creatable --web; switched to Nginx")
            sources.append("guard:block-web")
        else:
            flavor, site, ssl = "unknown", None, None
    if site == NGINX_SITE and not creatable_nginx:
        if creatable_apache:
            flavor, site, ssl = "apache", APACHE_SITE, APACHE_SSL
            notes.append("Blocked non-creatable Nginx; switched to Apache")
            sources.append("guard:block-nginx")
        else:
            flavor, site, ssl = "unknown", None, None

    # Build feature list, then intersect with allow-list when known.
    allow = set(enabled) if enabled else set(flags)
    chosen: list[str] = []
    for f in BASE_FEATURES:
        if not allow or f in allow or f in flags or not flags:
            chosen.append(f)

    if site:
        chosen.append(site)
        if ssl:
            # Include SSL companion when enabled/flagged or when site plugin implies it.
            if not allow or ssl in allow or ssl in flags or site == NGINX_SITE:
                chosen.append(ssl)

    for f in extra_features:
        if f in FORBIDDEN or f in chosen:
            continue
        if not allow or f in allow or f in flags:
            chosen.append(f)

    # Final hard filter: if we know enabled features, drop anything not enabled
    # (except base dir/dns/logrotate which may be coded differently).
    if enabled:
        filtered = []
        for f in chosen:
            if f in BASE_FEATURES or f in enabled or f in flags:
                # website codes must be enabled
                if f in WEBSITE_CODES and f not in enabled:
                    continue
                filtered.append(f)
        chosen = filtered

    # If flags known, never keep apache web/ssl outside flags/enabled.
    if flags or enabled:
        chosen = [
            f
            for f in chosen
            if f not in WEBSITE_CODES
            or f in enabled
            or (not enabled and f in flags)
        ]

    if site and site not in chosen:
        site = ssl = None
        flavor = "unknown"

    acme = None
    if "acme" in flags:
        acme = "acme"
    elif "letsencrypt" in flags:
        acme = "letsencrypt"

    if site is None:
        notes.append(
            "No website feature could be resolved. In Virtualmin, enable either "
            "Apache website (web/ssl) or Nginx website (virtualmin-nginx / "
            "virtualmin-nginx-ssl)."
        )

    return WebStackProfile(
        flavor=flavor if site else "unknown",
        site_feature=site,
        ssl_feature=ssl if ssl in chosen else None,
        create_features=tuple(chosen),
        acme_flag=acme,
        sources=tuple(sources),
        notes=tuple(notes),
        debug={
            "create_flags": sorted(flags),
            "enabled_features_website": sorted((enabled or set()) & WEBSITE_CODES),
            "creatable_apache": creatable_apache,
            "creatable_nginx": creatable_nginx,
            "parent_flavor": parent_flavor,
        },
    )


def domain_has_website(features: Iterable[str], *, url: str | None = None) -> bool:
    feats = set(features)
    if feats & WEBSITE_CODES:
        return True
    return bool(url)


def domain_is_web_only(features: Iterable[str], *, url: str | None = None) -> bool:
    return domain_has_website(features, url=url) and "mail" not in set(features)

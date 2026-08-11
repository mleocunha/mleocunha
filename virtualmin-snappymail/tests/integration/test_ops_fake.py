#!/usr/bin/env python3
"""Integration-style tests using a fake VirtualminClient (no live Virtualmin)."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "src"))

from virtualmin_snappymail.errors import (  # noqa: E402
    AlreadyInstalledError,
    MailOnSubserverError,
    ParentNoMailError,
)
from virtualmin_snappymail.ops import (  # noqa: E402
    adopt_domain,
    diagnose_domain,
    discover_installations,
    install_domain,
    remove_domain,
    repair_domain,
    status_for_domain,
)
from virtualmin_snappymail.snappymail_app import looks_like_snappymail  # noqa: E402
from virtualmin_snappymail.virtualmin_client import DomainInfo  # noqa: E402


class FakeClient:
    def __init__(self, domains: dict[str, DomainInfo] | None = None):
        self.domains = domains or {}
        self.created = []
        self.deleted = []
        self.disabled = []
        self.enabled = []

    def available(self):
        return True

    def get_domain(self, domain: str):
        return self.domains.get(domain)

    def domain_exists(self, domain: str):
        return domain in self.domains

    def list_domains_multiline(self, domain=None):
        vals = list(self.domains.values())
        if domain:
            return [d for d in vals if d.name == domain]
        return vals

    def list_children(self, parent: str):
        return [d.name for d in self.domains.values() if d.parent == parent]

    def create_web_only_subserver(self, *, webmail_domain, parent_domain, with_letsencrypt=True, description="", extra_features=()):
        from virtualmin_snappymail.webstack import WebStackProfile

        parent = self.domains[parent_domain]
        home = Path(parent.home) / "domains" / webmail_domain
        html = home / "public_html"
        home.mkdir(parents=True, exist_ok=True)
        html.mkdir(parents=True, exist_ok=True)
        (html / "index.html").write_text("placeholder\n", encoding="utf-8")
        # Mimic Apache by default in unit fake; nginx hosts override Features in dedicated tests.
        info = DomainInfo(
            name=webmail_domain,
            values={
                "Type": "Sub-server",
                "Parent domain": parent_domain,
                "Username": parent.username,
                "Home directory": str(home),
                "HTML directory": str(html),
                "Features": "dir dns web ssl logrotate",
            },
        )
        self.domains[webmail_domain] = info
        self.created.append(webmail_domain)
        return WebStackProfile(
            flavor="apache",
            site_feature="web",
            ssl_feature="ssl",
            create_features=("dir", "dns", "logrotate", "web", "ssl"),
            acme_flag="letsencrypt",
            sources=("fake",),
        )

    def delete_domain(self, domain: str):
        self.deleted.append(domain)
        self.domains.pop(domain, None)

    def disable_feature(self, domain: str, *features: str):
        self.disabled.append((domain, features))
        d = self.domains[domain]
        feats = d.features - set(features)
        d.values["Features"] = " ".join(sorted(feats))

    def enable_feature(self, domain: str, *features: str):
        self.enabled.append((domain, features))
        d = self.domains[domain]
        feats = d.features | set(features)
        d.values["Features"] = " ".join(sorted(feats))

    def generate_letsencrypt(self, domain: str):
        return None

    def modify_web_php(self, domain: str, *, mode: str = "fpm"):
        return None

    def run(self, args, check=True):
        class P:
            returncode = 0
            stdout = "\n".join(d.name for d in self.domains.values() if not d.parent and d.has_feature("mail"))
            stderr = ""

        return P()


def _parent(tmp: Path, name: str = "votoeletronico.com.br") -> DomainInfo:
    home = tmp / "home" / "voto"
    home.mkdir(parents=True)
    (home / "public_html").mkdir()
    return DomainInfo(
        name=name,
        values={
            "Type": "Top-level server",
            "Username": "voto",
            "Home directory": str(home),
            "HTML directory": str(home / "public_html"),
            "Features": "unix dir dns mail web ssl logrotate",
        },
    )


def _seed_snappy(docroot: Path, version: str = "2.38.2") -> None:
    docroot.mkdir(parents=True, exist_ok=True)
    (docroot / "index.php").write_text("<?php // snappymail\n", encoding="utf-8")
    (docroot / "snappymail").mkdir()
    data = docroot / "data"
    data.mkdir()
    (data / "VERSION").write_text(version + "\n", encoding="utf-8")
    (data / "_data_" / "_default_" / "domains").mkdir(parents=True)


class InstallFlowTests(unittest.TestCase):
    def test_parent_without_mail_aborts(self):
        with tempfile.TemporaryDirectory() as tmp:
            p = _parent(Path(tmp))
            p.values["Features"] = "unix dir dns web ssl"
            client = FakeClient({p.name: p})
            with self.assertRaises(ParentNoMailError):
                install_domain(client, p.name)

    def test_install_idempotent(self):
        with tempfile.TemporaryDirectory() as tmp:
            p = _parent(Path(tmp))
            client = FakeClient({p.name: p})

            def fake_install(docroot, **kwargs):
                _seed_snappy(Path(docroot), "2.38.2")
                return "2.38.2"

            with patch("virtualmin_snappymail.ops.install_fresh", side_effect=fake_install):
                with patch("virtualmin_snappymail.ops.discover_mail_topology") as topo:
                    topo.return_value.imap = None
                    topo.return_value.smtp = None
                    result = install_domain(client, p.name, with_letsencrypt=False)
            self.assertEqual(result["webmail_domain"], "webmail.votoeletronico.com.br")
            self.assertEqual(len(client.created), 1)
            with patch("virtualmin_snappymail.ops.install_fresh", side_effect=fake_install):
                with self.assertRaises(AlreadyInstalledError):
                    install_domain(client, p.name, with_letsencrypt=False)

    def test_mail_on_subserver_detected(self):
        with tempfile.TemporaryDirectory() as tmp:
            p = _parent(Path(tmp))
            home = Path(p.home) / "domains" / "webmail.votoeletronico.com.br"
            html = home / "public_html"
            html.mkdir(parents=True)
            sub = DomainInfo(
                name="webmail.votoeletronico.com.br",
                values={
                    "Type": "Sub-server",
                    "Parent domain": p.name,
                    "Username": "voto",
                    "Home directory": str(home),
                    "HTML directory": str(html),
                    "Features": "dir dns web ssl mail",
                },
            )
            client = FakeClient({p.name: p, sub.name: sub})
            with self.assertRaises(MailOnSubserverError):
                install_domain(client, p.name)

    def test_discover_adopt_repair_remove(self):
        with tempfile.TemporaryDirectory() as tmp:
            p = _parent(Path(tmp))
            home = Path(p.home) / "domains" / "webmail.votoeletronico.com.br"
            html = home / "public_html"
            _seed_snappy(html)
            sub = DomainInfo(
                name="webmail.votoeletronico.com.br",
                values={
                    "Type": "Sub-server",
                    "Parent domain": p.name,
                    "Username": "voto",
                    "Home directory": str(home),
                    "HTML directory": str(html),
                    "Features": "dir dns web ssl logrotate",
                },
            )
            client = FakeClient({p.name: p, sub.name: sub})
            with patch("virtualmin_snappymail.ops.discover_mail_topology") as topo:
                from virtualmin_snappymail.mail_discovery import Endpoint, MailTopology

                topo.return_value = MailTopology(
                    imap=Endpoint("127.0.0.1", 993, "ssl"),
                    smtp=Endpoint("127.0.0.1", 587, "starttls"),
                )
                hits = discover_installations(client)
                self.assertTrue(any(h.webmail_domain == sub.name for h in hits))
                adopted = adopt_domain(client, p.name)
                self.assertTrue(adopted["adopted"])
                self.assertTrue((home / ".virtualmin-snappymail.json").is_file())
                repaired = repair_domain(client, p.name)
                self.assertIn("manifest_upserted", repaired["actions"])
                checks = diagnose_domain(client, p.name)
                names = {c.name: c.ok for c in checks}
                self.assertTrue(names["subserver_mail_disabled"])
                self.assertTrue(names["snappymail_present"])
                row = status_for_domain(client, p.name)
                self.assertEqual(row.snappymail, "2.38.2")
                self.assertEqual(row.mode, "web-only")
                removed = remove_domain(client, p.name)
                self.assertTrue(removed["application_removed"])
                self.assertFalse(looks_like_snappymail(html))


class NegativeTests(unittest.TestCase):
    def test_missing_domain(self):
        client = FakeClient({})
        from virtualmin_snappymail.errors import ParentMissingError

        with self.assertRaises(ParentMissingError):
            install_domain(client, "missing.example.com")


if __name__ == "__main__":
    unittest.main()

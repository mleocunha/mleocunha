#!/usr/bin/env python3
"""Unit tests for domain validation, manifesto, escaping, versioning."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "src"))

from virtualmin_snappymail.domain import (  # noqa: E402
    DomainInvalidError,
    normalize_domain,
    parent_from_webmail,
    webmail_domain_for,
)
from virtualmin_snappymail.errors import DomainInvalidError as DIE  # noqa: E402
from virtualmin_snappymail.manifest import Manifest, load_manifest, save_manifest  # noqa: E402
from virtualmin_snappymail.security import confined_path, redact_secrets  # noqa: E402
from virtualmin_snappymail.virtualmin_client import parse_multiline_domains  # noqa: E402
from virtualmin_snappymail.mail_discovery import snappymail_domain_ini, MailTopology, Endpoint  # noqa: E402


class DomainTests(unittest.TestCase):
    def test_normalize_ok(self):
        self.assertEqual(normalize_domain("Example.COM."), "example.com")

    def test_reject_injection(self):
        with self.assertRaises(DIE):
            normalize_domain("evil.com;rm -rf /")
        with self.assertRaises(DIE):
            normalize_domain("../etc/passwd")
        with self.assertRaises(DIE):
            normalize_domain("foo bar.com")

    def test_webmail_derive(self):
        self.assertEqual(webmail_domain_for("votoeletronico.com.br"), "webmail.votoeletronico.com.br")

    def test_parent_from_webmail(self):
        self.assertEqual(parent_from_webmail("webmail.votoeletronico.com.br"), "votoeletronico.com.br")


class ManifestTests(unittest.TestCase):
    def test_roundtrip(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / ".virtualmin-snappymail.json"
            m = Manifest(
                parent_domain="a.com",
                webmail_domain="webmail.a.com",
                version="2.38.2",
                mail_identity_domain="a.com",
            )
            save_manifest(path, m)
            loaded = load_manifest(path)
            self.assertIsNotNone(loaded)
            self.assertTrue(loaded.managed)
            self.assertEqual(loaded.parent_domain, "a.com")
            self.assertNotIn("password", path.read_text())


class SecurityTests(unittest.TestCase):
    def test_confined_path(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            ok = confined_path(base, "public_html", "index.php")
            self.assertTrue(str(ok).startswith(str(base.resolve())))
            with self.assertRaises(ValueError):
                confined_path(base, "..", "etc", "passwd")

    def test_redact(self):
        self.assertIn("***", redact_secrets("password=secret123"))


class ParseMultilineTests(unittest.TestCase):
    def test_parse(self):
        sample = """\
example.com:
    Type: Top-level server
    Username: example
    Home directory: /home/example
    HTML directory: /home/example/public_html
    Features: unix dir dns mail web ssl logrotate
webmail.example.com:
    Type: Sub-server
    Parent domain: example.com
    Username: example
    Home directory: /home/example/domains/webmail.example.com
    HTML directory: /home/example/domains/webmail.example.com/public_html
    Features: dir dns web ssl logrotate
"""
        domains = parse_multiline_domains(sample)
        self.assertEqual(len(domains), 2)
        parent = domains[0]
        self.assertTrue(parent.has_feature("mail"))
        child = domains[1]
        self.assertEqual(child.parent, "example.com")
        self.assertTrue(child.is_web_only())
        self.assertFalse(child.has_feature("mail"))


class DomainIniTests(unittest.TestCase):
    def test_ini_uses_parent_whitelist(self):
        topo = MailTopology(
            imap=Endpoint("127.0.0.1", 993, "ssl"),
            smtp=Endpoint("127.0.0.1", 587, "starttls"),
        )
        ini = snappymail_domain_ini(parent_domain="votoeletronico.com.br", topo=topo)
        self.assertIn('white_list = "votoeletronico.com.br"', ini)
        self.assertIn("993", ini)
        self.assertIn("587", ini)


if __name__ == "__main__":
    unittest.main()

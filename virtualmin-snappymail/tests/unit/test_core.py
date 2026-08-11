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
from virtualmin_snappymail.virtualmin_client import (  # noqa: E402
    DomainInfo,
    VirtualminClient,
    parse_multiline_domains,
)
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
    def test_parse_virtualmin_real_format_no_colon(self):
        # Actual Virtualmin GPL output: domain header has NO trailing colon.
        sample = """\
example.com
    ID: 123
    Type: Top-level server
    Username: example
    Home directory: /home/example
    HTML directory: /home/example/public_html
    Features: unix dir dns mail web ssl logrotate
webmail.example.com
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
        self.assertEqual(parent.name, "example.com")
        self.assertTrue(parent.has_feature("mail"))
        child = domains[1]
        self.assertEqual(child.parent, "example.com")
        self.assertTrue(child.is_web_only())
        self.assertFalse(child.has_feature("mail"))

    def test_parse_colon_header_still_works(self):
        sample = """\
example.com:
    Type: Top-level server
    Features: unix dir dns mail web ssl
"""
        domains = parse_multiline_domains(sample)
        self.assertEqual(len(domains), 1)
        self.assertEqual(domains[0].name, "example.com")
        self.assertTrue(domains[0].has_feature("mail"))

    def test_nginx_features_count_as_website(self):
        d = DomainInfo(
            name="webmail.example.com",
            values={
                "Type": "Sub-server",
                "Parent domain": "example.com",
                "Features": "dir dns virtualmin-nginx virtualmin-nginx-ssl logrotate",
            },
        )
        self.assertTrue(d.has_website())
        self.assertTrue(d.is_web_only())


class FeatureResolveTests(unittest.TestCase):
    def test_resolves_nginx_when_apache_web_absent(self):
        help_text = """
virtualmin create-domain --domain domain.name
                        [--dir]
                        [--dns]
                        [--mail]
                        [--logrotate]
                        [--virtualmin-nginx]
                        [--virtualmin-nginx-ssl]
                        [--acme]
"""

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = help_text
            elif "list-features" in cmd:
                P.stdout = "dir\ndns\nmail\nlogrotate\nvirtualmin-nginx\nvirtualmin-nginx-ssl\n"
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        feats = client.resolve_web_only_features()
        self.assertIn("virtualmin-nginx", feats)
        self.assertIn("virtualmin-nginx-ssl", feats)
        self.assertNotIn("web", feats)
        self.assertNotIn("mail", feats)

    def test_resolves_apache_when_nginx_absent(self):
        help_text = """
virtualmin create-domain
                        [--dir] [--dns] [--mail] [--logrotate]
                        [--web] [--ssl] [--letsencrypt]
"""

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = help_text
            elif "list-features" in cmd:
                P.stdout = "dir\ndns\nmail\nlogrotate\nweb\nssl\n"
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        feats = client.resolve_web_only_features()
        self.assertIn("web", feats)
        self.assertIn("ssl", feats)
        self.assertNotIn("virtualmin-nginx", feats)


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

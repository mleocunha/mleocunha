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
Example narrative still mentions --web --ssl for old docs.

virtualmin create-domain --domain domain.name
                        [--dir]
                        [--dns]
                        [--mail]
                        [--logrotate]
                        [--virtualmin-nginx]
                        [--virtualmin-nginx-ssl]
                        [--acme]
"""
        features_ml = """\
web
    Enabled: No
ssl
    Enabled: No
virtualmin-nginx
    Enabled: Yes
virtualmin-nginx-ssl
    Enabled: Yes
dir
    Enabled: Yes
dns
    Enabled: Yes
logrotate
    Enabled: Yes
"""

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = help_text
            elif "list-features" in cmd and "--multiline" in cmd:
                P.stdout = features_ml
            elif "list-features" in cmd:
                P.stdout = "dir\ndns\nmail\nlogrotate\nweb\nssl\nvirtualmin-nginx\nvirtualmin-nginx-ssl\n"
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
        features_ml = """\
web
    Enabled: Yes
ssl
    Enabled: Yes
dir
    Enabled: Yes
dns
    Enabled: Yes
logrotate
    Enabled: Yes
"""

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = help_text
            elif "list-features" in cmd and "--multiline" in cmd:
                P.stdout = features_ml
            elif "list-features" in cmd:
                P.stdout = "dir\ndns\nmail\nlogrotate\nweb\nssl\n"
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        feats = client.resolve_web_only_features()
        self.assertIn("web", feats)
        self.assertIn("ssl", feats)
        self.assertNotIn("virtualmin-nginx", feats)


class WebmailPrepTests(unittest.TestCase):
    def test_prepare_disables_parent_webmail_redirect(self):
        calls: list[list[str]] = []

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            calls.append(list(cmd))
            if len(cmd) >= 3 and cmd[1] == "help" and cmd[2] == "modify-web":
                P.stdout = "virtualmin modify-web [--webmail] [--no-webmail] [--mode fpm]\n"
            elif "list-domains" in cmd:
                P.returncode = 1
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        actions = client.prepare_webmail_hostname(
            webmail_domain="webmail.votoeletronico.com.br",
            parent_domain="votoeletronico.com.br",
        )
        self.assertTrue(any("no-webmail" in a for a in actions))
        self.assertTrue(
            any(
                cmd[1:] == ["modify-web", "--domain", "votoeletronico.com.br", "--no-webmail"]
                for cmd in calls
            )
        )

    def test_create_maps_nginx_name_conflict_to_sub_conflict(self):
        from virtualmin_snappymail.errors import SubserverConflictError

        help_create = """
virtualmin create-domain
                        [--dir] [--dns] [--logrotate]
                        [--virtualmin-nginx] [--virtualmin-nginx-ssl] [--acme]
                        [--shared-ip] [--ip-already] [--generate-ssl-cert] [--link-ssl-cert]
"""
        features_ml = """\
virtualmin-nginx
    Enabled: Yes
virtualmin-nginx-ssl
    Enabled: Yes
dir
    Enabled: Yes
dns
    Enabled: Yes
logrotate
    Enabled: Yes
"""
        parent_ml = """\
votoeletronico.com.br
    Type: Top-level server
    IP address: 203.0.113.10
    Features: unix dir dns mail virtualmin-nginx virtualmin-nginx-ssl logrotate
"""

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                if len(cmd) >= 3 and cmd[2] == "modify-web":
                    P.stdout = "[--no-webmail] [--mode fpm]\n"
                else:
                    P.stdout = help_create
            elif "list-features" in cmd and "--multiline" in cmd:
                P.stdout = features_ml
            elif "list-features" in cmd:
                P.stdout = "dir\ndns\nlogrotate\nvirtualmin-nginx\nvirtualmin-nginx-ssl\n"
            elif "list-domains" in cmd and "--multiline" in cmd and "--domain" in cmd:
                idx = cmd.index("--domain")
                domain = cmd[idx + 1]
                if domain == "votoeletronico.com.br":
                    P.stdout = parent_ml
                else:
                    P.returncode = 1
            elif "list-domains" in cmd and "--name-only" in cmd:
                P.returncode = 1
            elif cmd[1] == "create-domain":
                P.returncode = 1
                P.stderr = "An Nginx virtual host with the same name already exists\n"
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        with self.assertRaises(SubserverConflictError) as ctx:
            client.create_web_only_subserver(
                webmail_domain="webmail.votoeletronico.com.br",
                parent_domain="votoeletronico.com.br",
            )
        self.assertIn("no-webmail", ctx.exception.message)
        self.assertIn("webmail.votoeletronico.com.br", ctx.exception.message)

    def test_create_retries_without_ssl_on_nginx_ssl_bug(self):
        help_create = """
virtualmin create-domain
                        [--dir] [--dns] [--logrotate]
                        [--virtualmin-nginx] [--virtualmin-nginx-ssl] [--acme]
                        [--shared-ip address] [--ip] [--ip-already]
                        [--generate-ssl-cert] [--link-ssl-cert] [--skip-warnings]
"""
        features_ml = """\
virtualmin-nginx
    Enabled: Yes
virtualmin-nginx-ssl
    Enabled: Yes
dir
    Enabled: Yes
dns
    Enabled: Yes
logrotate
    Enabled: Yes
"""
        parent_ml = """\
votoeletronico.com.br
    Type: Top-level server
    Features: unix dir dns mail virtualmin-nginx virtualmin-nginx-ssl logrotate
"""
        create_calls: list[list[str]] = []
        enable_calls: list[list[str]] = []
        shared_ips: set[str] = set()

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                if len(cmd) >= 3 and cmd[2] == "modify-web":
                    P.stdout = "[--no-webmail]\n"
                else:
                    P.stdout = help_create
            elif "list-features" in cmd and "--multiline" in cmd:
                P.stdout = features_ml
            elif "list-features" in cmd:
                P.stdout = "dir\ndns\nlogrotate\nvirtualmin-nginx\nvirtualmin-nginx-ssl\n"
            elif "list-domains" in cmd and "--ip-only" in cmd:
                P.stdout = "203.0.113.10\n"
            elif "list-shared-addresses" in cmd:
                P.stdout = "\n".join(sorted(shared_ips)) + ("\n" if shared_ips else "")
            elif cmd[1] == "create-shared-address":
                shared_ips.add(cmd[cmd.index("--ip") + 1])
            elif "list-domains" in cmd and "--multiline" in cmd and "--domain" in cmd:
                idx = cmd.index("--domain")
                domain = cmd[idx + 1]
                if domain == "votoeletronico.com.br":
                    P.stdout = parent_ml
                else:
                    P.returncode = 1
            elif "list-domains" in cmd and "--name-only" in cmd:
                if create_calls and any(
                    x in create_calls[-1] for x in ("--virtualmin-nginx", "--virtualmin-nginx-ssl")
                ):
                    P.returncode = 0
                    P.stdout = "webmail.votoeletronico.com.br\n"
                else:
                    P.returncode = 1
            elif cmd[1] == "create-domain":
                create_calls.append(list(cmd))
                if "--virtualmin-nginx" in cmd:
                    P.returncode = 1
                    P.stderr = (
                        "Use of uninitialized value in string eq at "
                        "/usr/share/webmin/virtualmin-nginx-ssl/virtual_feature.pl line 130.\n"
                    )
                else:
                    P.returncode = 0
            elif cmd[1] == "delete-domain":
                P.returncode = 0
            elif cmd[1] == "enable-feature":
                enable_calls.append(list(cmd))
                P.returncode = 0
            elif cmd[1] == "generate-letsencrypt-cert":
                P.returncode = 0
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        profile = client.create_web_only_subserver(
            webmail_domain="webmail.votoeletronico.com.br",
            parent_domain="votoeletronico.com.br",
        )
        self.assertEqual(profile.flavor, "nginx")
        self.assertGreaterEqual(len(create_calls), 2)
        self.assertIn("--virtualmin-nginx", create_calls[0])
        # After ensure_shared_address → --shared-ip
        self.assertIn("--shared-ip", create_calls[0])
        self.assertIn("203.0.113.10", create_calls[0])
        self.assertIn("--skip-warnings", create_calls[0])
        # Staged fallback: no website features on create
        self.assertNotIn("--virtualmin-nginx", create_calls[1])
        self.assertNotIn("--virtualmin-nginx-ssl", create_calls[1])
        self.assertTrue(any("--virtualmin-nginx" in c for c in enable_calls))

    def test_domaininfo_parses_shared_ip(self):
        d = DomainInfo(
            name="votoeletronico.com.br",
            values={"IP address": "203.0.113.10 (shared)"},
        )
        self.assertEqual(d.ip, "203.0.113.10")
        self.assertTrue(d.ip_is_shared)

    def test_ensure_shared_address_registers_missing_ip(self):
        shared: set[str] = set()
        calls: list[list[str]] = []

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            calls.append(list(cmd))
            if cmd[1] == "list-shared-addresses":
                P.stdout = "\n".join(sorted(shared)) + ("\n" if shared else "")
            elif cmd[1] == "create-shared-address":
                shared.add(cmd[cmd.index("--ip") + 1])
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        self.assertTrue(client.ensure_shared_address("191.176.16.2"))
        self.assertIn("191.176.16.2", shared)
        self.assertTrue(
            any(c[1] == "create-shared-address" and "191.176.16.2" in c for c in calls)
        )

    def test_resolve_parent_ip_flags_uses_shared_after_ensure(self):
        shared: set[str] = set()

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = "[--ip address] [--ip-already] [--shared-ip address]\n"
            elif "list-shared-addresses" in cmd:
                P.stdout = "\n".join(sorted(shared)) + ("\n" if shared else "")
            elif cmd[1] == "create-shared-address":
                shared.add(cmd[cmd.index("--ip") + 1])
            elif "list-domains" in cmd:
                P.returncode = 1
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        flags = client.resolve_parent_ip_flags(
            parent_domain="votoeletronico.com.br",
            parent_ip="191.176.16.2",
        )
        self.assertEqual(flags, ["--shared-ip", "191.176.16.2"])
        self.assertIn("191.176.16.2", shared)

    def test_resolve_parent_ip_flags_falls_back_to_ip_when_share_fails(self):
        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = "[--ip address] [--ip-already] [--shared-ip address]\n"
            elif "list-shared-addresses" in cmd:
                P.stdout = ""
            elif cmd[1] == "create-shared-address":
                P.returncode = 1
                P.stderr = "failed\n"
            elif "list-domains" in cmd:
                P.returncode = 1
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        flags = client.resolve_parent_ip_flags(
            parent_domain="example.com",
            parent_ip="203.0.113.10",
        )
        self.assertEqual(flags[:2], ["--ip", "203.0.113.10"])
        self.assertIn("--ip-already", flags)

    def test_get_domain_ip_falls_back_to_ip_only(self):
        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if "list-domains" in cmd and "--ip-only" in cmd:
                P.stdout = "198.51.100.7\n"
            elif "list-domains" in cmd:
                P.returncode = 1
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        self.assertEqual(client.get_domain_ip("votoeletronico.com.br"), "198.51.100.7")


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

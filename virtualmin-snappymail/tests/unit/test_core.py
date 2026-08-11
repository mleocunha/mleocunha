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
    coerce_mail_parent_domain,
    normalize_domain,
    parent_from_webmail,
    suggest_domains,
    try_normalize_domain,
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
from virtualmin_snappymail.mail_discovery import (  # noqa: E402
    snappymail_domain_ini,
    MailTopology,
    Endpoint,
    parse_white_list_value,
    invalid_white_list_tokens,
)


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

    def test_try_normalize_skips_localhost(self):
        self.assertIsNone(try_normalize_domain("localhost"))
        self.assertIsNone(try_normalize_domain("unknown"))
        self.assertEqual(try_normalize_domain("Example.COM."), "example.com")

    def test_normalize_url_path(self):
        from virtualmin_snappymail.ops import _normalize_url_path

        self.assertEqual(_normalize_url_path(None), "webmail")
        self.assertEqual(_normalize_url_path(""), "")
        self.assertEqual(_normalize_url_path("/mail/"), "mail")
        with self.assertRaises(Exception):
            _normalize_url_path("../etc")

    def test_coerce_webmail_to_parent(self):
        self.assertEqual(
            coerce_mail_parent_domain("webmail.relatasoft.com.br"),
            "relatasoft.com.br",
        )
        self.assertEqual(coerce_mail_parent_domain("relatasoft.com.br"), "relatasoft.com.br")

    def test_suggest_domains_typo(self):
        pool = ["relatasoft.com.br", "votoeletronico.com.br", "example.com"]
        hits = suggest_domains("relatosoft.com.br", pool)
        self.assertEqual(hits[0], "relatasoft.com.br")


class WhiteListTests(unittest.TestCase):
    def test_parse_empty(self):
        self.assertEqual(parse_white_list_value('""'), [])
        self.assertEqual(parse_white_list_value(""), [])

    def test_invalid_bare_domain(self):
        bad = invalid_white_list_tokens(
            ["relatasoft.com.br"], parent_domain="relatasoft.com.br"
        )
        self.assertEqual(bad, ["relatasoft.com.br"])

    def test_valid_forms(self):
        bad = invalid_white_list_tokens(
            ["user@relatasoft.com.br", "@relatasoft.com.br", "eleitor0001"],
            parent_domain="relatasoft.com.br",
        )
        self.assertEqual(bad, [])


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
        self.assertIn("--skip-warnings", create_calls[0])
        # Staged fallback: no website features on create
        self.assertNotIn("--virtualmin-nginx", create_calls[1])
        self.assertNotIn("--virtualmin-nginx-ssl", create_calls[1])
        self.assertTrue(any("--virtualmin-nginx" in c for c in enable_calls))
        self.assertIn("203.0.113.10", shared_ips)

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

    def test_ensure_shared_address_converts_private_holder(self):
        shared: set[str] = set()
        calls: list[list[str]] = []
        released = {"done": False}

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            calls.append(list(cmd))
            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = "[--default-ip] [--skip-warnings] [--shared-ip]\n"
            elif cmd[1] == "list-shared-addresses":
                P.stdout = "\n".join(sorted(shared)) + ("\n" if shared else "")
            elif cmd[1] == "create-shared-address":
                if not released["done"]:
                    P.returncode = 1
                    P.stderr = (
                        "The virtual server licenciamento.relatasoft.com.br "
                        "is already using address 191.176.16.2\n"
                    )
                else:
                    shared.add(cmd[cmd.index("--ip") + 1])
            elif cmd[1] == "modify-domain" and "--default-ip" in cmd:
                released["done"] = True
            elif "list-domains" in cmd and "--ip" in cmd and "--name-only" in cmd:
                P.stdout = "licenciamento.relatasoft.com.br\n"
            elif "list-domains" in cmd and "--multiline" in cmd:
                P.stdout = (
                    "licenciamento.relatasoft.com.br\n"
                    "    Type: Top-level server\n"
                    "    IP address: 191.176.16.2\n"
                )
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        self.assertTrue(client.ensure_shared_address("191.176.16.2"))
        self.assertIn("191.176.16.2", shared)
        self.assertTrue(any(c[1] == "modify-domain" and "--default-ip" in c for c in calls))

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

    def test_resolve_parent_ip_flags_inherit_when_share_fails(self):
        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = "[--ip address] [--ip-already] [--shared-ip address] [--default-ip]\n"
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
        self.assertEqual(flags, [])

    def test_find_domains_on_ip_skips_localhost(self):
        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = "localhost\nvotoeletronico.com.br\nunknown\n"
                stderr = ""

            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        self.assertEqual(
            client.find_domains_on_ip("191.176.16.2"),
            ["votoeletronico.com.br"],
        )

    def test_ensure_default_network_ip_fills_blank_iface_localip(self):
        with tempfile.TemporaryDirectory() as td:
            cfg = Path(td) / "config"
            cfg.write_text("home_base=/home\niface=\n", encoding="utf-8")

            def runner(cmd, **kw):
                class P:
                    returncode = 0
                    stdout = ""
                    stderr = ""

                if cmd[:4] == ["ip", "-4", "-o", "addr"]:
                    P.stdout = "2: eth0    inet 191.176.16.2/24 brd 191.176.16.255 scope global eth0\n"
                return P()

            client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
            actions = client.ensure_default_network_ip(
                "191.176.16.2", config_path=cfg
            )
            text = cfg.read_text(encoding="utf-8")
            self.assertIn("iface=eth0", text)
            self.assertIn("defip=191.176.16.2", text)
            self.assertTrue(any("iface=eth0" in a for a in actions))
            self.assertTrue(any("defip=191.176.16.2" in a for a in actions))
            self.assertTrue((Path(str(cfg) + ".vsm-bak")).is_file())

    def test_ensure_default_network_ip_does_not_clobber_existing(self):
        with tempfile.TemporaryDirectory() as td:
            cfg = Path(td) / "config"
            cfg.write_text(
                "iface=ens3\ndefip=198.51.100.1\n",
                encoding="utf-8",
            )

            def runner(cmd, **kw):
                class P:
                    returncode = 0
                    stdout = "2: eth0    inet 191.176.16.2/24 scope global eth0\n"
                    stderr = ""

                return P()

            client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
            actions = client.ensure_default_network_ip(
                "191.176.16.2", config_path=cfg
            )
            self.assertEqual(actions, [])
            text = cfg.read_text(encoding="utf-8")
            self.assertIn("iface=ens3", text)
            self.assertIn("defip=198.51.100.1", text)

    def test_ensure_default_network_ip_force_defip(self):
        with tempfile.TemporaryDirectory() as td:
            cfg = Path(td) / "config"
            cfg.write_text("iface=ens3\ndefip=198.51.100.1\n", encoding="utf-8")

            def runner(cmd, **kw):
                class P:
                    returncode = 0
                    stdout = ""
                    stderr = ""

                return P()

            client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
            actions = client.ensure_default_network_ip(
                "191.176.16.2", config_path=cfg, force_defip=True
            )
            text = cfg.read_text(encoding="utf-8")
            self.assertIn("defip=191.176.16.2", text)
            self.assertTrue(any("defip=191.176.16.2" in a for a in actions))

    def test_create_does_not_speculative_shared_ip(self):
        """When parent IP is not on extra shared list, never pass --shared-ip."""
        create_calls: list[list[str]] = []

        def runner(cmd, **kw):
            class P:
                returncode = 0
                stdout = ""
                stderr = ""

            if len(cmd) >= 2 and cmd[1] == "help":
                P.stdout = (
                    "[--dir] [--dns] [--logrotate] [--virtualmin-nginx] "
                    "[--virtualmin-nginx-ssl] [--shared-ip address] [--ip] "
                    "[--ip-already] [--skip-warnings] [--generate-ssl-cert] "
                    "[--link-ssl-cert] [--acme]\n"
                )
            elif "list-features" in cmd and "--multiline" in cmd:
                P.stdout = (
                    "virtualmin-nginx\n"
                    "    Enabled: Yes\n"
                    "virtualmin-nginx-ssl\n"
                    "    Enabled: Yes\n"
                    "web\n"
                    "    Enabled: No\n"
                )
            elif "list-features" in cmd:
                P.stdout = "dir\ndns\nlogrotate\nvirtualmin-nginx\nvirtualmin-nginx-ssl\n"
            elif "list-shared-addresses" in cmd:
                P.stdout = ""
            elif "list-domains" in cmd and "--multiline" in cmd:
                P.stdout = (
                    "votoeletronico.com.br\n"
                    "    Type: Top-level server\n"
                    "    Features: unix dir dns mail virtualmin-nginx virtualmin-nginx-ssl\n"
                    "    IP address: 191.176.16.2\n"
                    "    Home directory: /home/voto\n"
                    "    HTML directory: /home/voto/public_html\n"
                )
            elif "list-domains" in cmd and "--ip-only" in cmd:
                P.stdout = "191.176.16.2\n"
            elif "list-domains" in cmd and "--name-only" in cmd:
                P.stdout = "votoeletronico.com.br\n"
            elif cmd[1] == "create-shared-address":
                P.returncode = 1
                P.stderr = (
                    "The virtual server licenciamento.relatasoft.com.br "
                    "is already using address 191.176.16.2\n"
                )
            elif cmd[1] == "create-domain":
                create_calls.append(list(cmd))
                # Succeed on inherit (no --shared-ip / --ip)
                if "--shared-ip" in cmd:
                    P.returncode = 1
                    P.stderr = "191.176.16.2 is not in the shared IP addresses list\n"
                elif "--ip" in cmd:
                    P.returncode = 1
                    P.stderr = "The IP address is already used by virtual server x\n"
                else:
                    P.returncode = 0
            elif cmd[1] == "modify-web":
                P.returncode = 0
            elif cmd[1] == "modify-domain":
                P.returncode = 1
                P.stderr = (
                    "The --default-ip flag can only be used when the virtual "
                    "server has a private address\n"
                )
            return P()

        client = VirtualminClient(binary="/usr/sbin/virtualmin", runner=runner)
        # Point ensure_default_network_ip at a temp config so inherit works.
        with tempfile.TemporaryDirectory() as td:
            cfg = Path(td) / "config"
            cfg.write_text("home_base=/home\n", encoding="utf-8")
            orig = client.ensure_default_network_ip

            def _ensure(ip, config_path=None, force_defip=False):
                return orig(ip, config_path=cfg, force_defip=force_defip)

            client.ensure_default_network_ip = _ensure  # type: ignore[method-assign]
            profile = client.create_web_only_subserver(
                webmail_domain="webmail.votoeletronico.com.br",
                parent_domain="votoeletronico.com.br",
                with_letsencrypt=False,
            )
        self.assertEqual(profile.flavor, "nginx")
        self.assertTrue(create_calls)
        for c in create_calls:
            self.assertNotIn("--shared-ip", c)

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
    def test_ini_leaves_whitelist_empty(self):
        topo = MailTopology(
            imap=Endpoint("127.0.0.1", 993, "ssl"),
            smtp=Endpoint("127.0.0.1", 587, "starttls"),
        )
        ini = snappymail_domain_ini(parent_domain="votoeletronico.com.br", topo=topo)
        # Bare "domain.tld" does not match SnappyMail ValidateWhiteList (needs
        # "@domain.tld"); empty whitelist allows all mailboxes on the domain.
        self.assertIn('white_list = ""', ini)
        self.assertNotIn('white_list = "votoeletronico.com.br"', ini)
        self.assertIn("993", ini)
        self.assertIn("587", ini)


if __name__ == "__main__":
    unittest.main()

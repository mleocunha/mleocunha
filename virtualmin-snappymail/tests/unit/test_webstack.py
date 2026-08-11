#!/usr/bin/env python3
"""Unit tests for Apache/Nginx webstack detection."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "src"))

from virtualmin_snappymail.webstack import (  # noqa: E402
    detect_web_stack,
    flavor_from_features,
    parse_create_domain_flags,
)


NS1_HELP = """
Adds a new Virtualmin virtual server, with the settings and features
specified on the command line.

 virtualmin create-domain \\
 --domain foo.com --pass smeg --desc "The server for foo" \\
 --unix --dir --webmin --web --dns --mail --limits-from-plan

virtualmin create-domain --domain domain.name
                        [--dir]
                        [--dns]
                        [--mail]
                        [--logrotate]
                        [--mysql]
                        [--spam]
                        [--virus]
                        [--webmin]
                        [--virtualmin-awstats]
                        [--virtualmin-nginx]
                        [--virtualmin-nginx-ssl]
                        [--acme]
                        [--acme-always]
"""


class ParseFlagsTests(unittest.TestCase):
    def test_ignores_narrative_web_example_on_nginx_host(self):
        flags = parse_create_domain_flags(NS1_HELP)
        self.assertIn("virtualmin-nginx", flags)
        self.assertIn("virtualmin-nginx-ssl", flags)
        self.assertIn("acme", flags)
        self.assertNotIn("web", flags)
        self.assertNotIn("ssl", flags)
        # --webmin is a real optional flag on this host
        self.assertIn("webmin", flags)


class WebStackDetectionTests(unittest.TestCase):
    def test_nginx_only_create_flags(self):
        profile = detect_web_stack(
            create_flags=parse_create_domain_flags(NS1_HELP),
            os_has_nginx=True,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "nginx")
        self.assertIn("virtualmin-nginx", profile.create_features)
        self.assertIn("virtualmin-nginx-ssl", profile.create_features)
        self.assertNotIn("web", profile.create_features)
        self.assertNotIn("mail", profile.create_features)
        self.assertEqual(profile.acme_flag, "acme")

    def test_stale_parent_apache_features_still_use_nginx_when_web_disabled(self):
        # Parent Features may still list legacy "web" after Nginx migration.
        profile = detect_web_stack(
            create_flags=parse_create_domain_flags(NS1_HELP),
            parent_features={"unix", "dir", "dns", "mail", "web", "ssl", "virtualmin-nginx"},
            os_has_nginx=True,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "nginx")
        self.assertNotIn("web", profile.create_features)

    def test_apache_only_create_flags(self):
        help_text = """
virtualmin create-domain --domain x
                        [--dir] [--dns] [--mail] [--logrotate]
                        [--web] [--ssl] [--letsencrypt]
Example uses --virtualmin-nginx in docs but it is not bracketed here.
"""
        profile = detect_web_stack(
            create_flags=parse_create_domain_flags(help_text),
            os_has_nginx=False,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "apache")
        self.assertIn("web", profile.create_features)
        self.assertIn("ssl", profile.create_features)
        self.assertNotIn("virtualmin-nginx", profile.create_features)
        self.assertEqual(profile.acme_flag, "letsencrypt")

    def test_parent_nginx_wins_when_both_creatable(self):
        profile = detect_web_stack(
            create_flags={
                "dir",
                "dns",
                "logrotate",
                "web",
                "ssl",
                "virtualmin-nginx",
                "virtualmin-nginx-ssl",
                "acme",
            },
            parent_features={"unix", "dir", "dns", "mail", "virtualmin-nginx", "virtualmin-nginx-ssl"},
            os_has_nginx=True,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "nginx")
        self.assertIn("virtualmin-nginx", profile.create_features)
        self.assertNotIn("web", profile.create_features)

    def test_parent_apache_wins_when_both_creatable(self):
        profile = detect_web_stack(
            create_flags={
                "dir",
                "dns",
                "logrotate",
                "web",
                "ssl",
                "virtualmin-nginx",
                "virtualmin-nginx-ssl",
            },
            parent_features={"unix", "dir", "dns", "mail", "web", "ssl"},
            os_has_nginx=True,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "apache")
        self.assertIn("web", profile.create_features)
        self.assertNotIn("virtualmin-nginx", profile.create_features)

    def test_flavor_from_features(self):
        self.assertEqual(flavor_from_features(["web", "ssl"]), "apache")
        self.assertEqual(flavor_from_features(["virtualmin-nginx"]), "nginx")
        self.assertEqual(flavor_from_features(["mail"]), "unknown")


if __name__ == "__main__":
    unittest.main()

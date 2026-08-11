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
    enabled_feature_codes,
    flavor_from_features,
    parse_create_domain_flags,
    parse_list_features_multiline,
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

NS1_LIST_FEATURES = """\
web
    Description: Apache website
    Source: Core
    Enabled: No
    Default: No
ssl
    Description: Apache SSL website
    Source: Core
    Enabled: No
virtualmin-nginx
    Description: Nginx website
    Source: Plugin
    Enabled: Yes
virtualmin-nginx-ssl
    Description: Nginx SSL website
    Source: Plugin
    Enabled: Yes
dir
    Description: Home directory
    Enabled: Yes
dns
    Description: DNS domain
    Enabled: Yes
mail
    Description: Mail for domain
    Enabled: Yes
logrotate
    Description: Log file rotation
    Enabled: Yes
"""


class ParseFlagsTests(unittest.TestCase):
    def test_ignores_narrative_web_example_on_nginx_host(self):
        flags = parse_create_domain_flags(NS1_HELP)
        self.assertIn("virtualmin-nginx", flags)
        self.assertNotIn("web", flags)
        self.assertNotIn("ssl", flags)


class ListFeaturesParseTests(unittest.TestCase):
    def test_enabled_codes(self):
        enabled = enabled_feature_codes(NS1_LIST_FEATURES)
        self.assertIn("virtualmin-nginx", enabled)
        self.assertIn("virtualmin-nginx-ssl", enabled)
        self.assertNotIn("web", enabled)
        self.assertNotIn("ssl", enabled)


class WebStackDetectionTests(unittest.TestCase):
    def test_ns1_nginx_with_disabled_apache(self):
        profile = detect_web_stack(
            create_flags=parse_create_domain_flags(NS1_HELP),
            enabled_features=enabled_feature_codes(NS1_LIST_FEATURES),
            parent_features={"unix", "dir", "dns", "mail", "web"},  # stale parent
            os_has_nginx=True,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "nginx")
        self.assertIn("virtualmin-nginx", profile.create_features)
        self.assertIn("virtualmin-nginx-ssl", profile.create_features)
        self.assertNotIn("web", profile.create_features)
        self.assertNotIn("ssl", profile.create_features)
        self.assertEqual(profile.acme_flag, "acme")

    def test_apache_only_enabled(self):
        help_text = """
virtualmin create-domain
                        [--dir] [--dns] [--mail] [--logrotate]
                        [--web] [--ssl] [--letsencrypt]
"""
        enabled = {"dir", "dns", "mail", "logrotate", "web", "ssl"}
        profile = detect_web_stack(
            create_flags=parse_create_domain_flags(help_text),
            enabled_features=enabled,
            os_has_nginx=False,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "apache")
        self.assertIn("web", profile.create_features)
        self.assertNotIn("virtualmin-nginx", profile.create_features)

    def test_parent_nginx_wins_when_both_enabled(self):
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
            enabled_features={
                "dir",
                "dns",
                "logrotate",
                "web",
                "ssl",
                "virtualmin-nginx",
                "virtualmin-nginx-ssl",
            },
            parent_features={"unix", "dir", "dns", "mail", "virtualmin-nginx", "virtualmin-nginx-ssl"},
            os_has_nginx=True,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "nginx")
        self.assertNotIn("web", profile.create_features)

    def test_flavor_from_features(self):
        self.assertEqual(flavor_from_features(["web", "ssl"]), "apache")
        self.assertEqual(flavor_from_features(["virtualmin-nginx"]), "nginx")


if __name__ == "__main__":
    unittest.main()

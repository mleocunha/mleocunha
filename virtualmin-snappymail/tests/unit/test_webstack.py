#!/usr/bin/env python3
"""Unit tests for Apache/Nginx webstack detection."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "src"))

from virtualmin_snappymail.webstack import detect_web_stack, flavor_from_features  # noqa: E402


class WebStackDetectionTests(unittest.TestCase):
    def test_nginx_only_create_flags(self):
        profile = detect_web_stack(
            create_flags={"dir", "dns", "logrotate", "virtualmin-nginx", "virtualmin-nginx-ssl", "acme", "mail"},
            os_has_nginx=True,
            os_has_apache=True,  # apache binary may still exist
        )
        self.assertEqual(profile.flavor, "nginx")
        self.assertIn("virtualmin-nginx", profile.create_features)
        self.assertIn("virtualmin-nginx-ssl", profile.create_features)
        self.assertNotIn("web", profile.create_features)
        self.assertNotIn("mail", profile.create_features)
        self.assertEqual(profile.acme_flag, "acme")

    def test_apache_only_create_flags(self):
        profile = detect_web_stack(
            create_flags={"dir", "dns", "logrotate", "web", "ssl", "letsencrypt", "mail"},
            os_has_nginx=False,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "apache")
        self.assertIn("web", profile.create_features)
        self.assertIn("ssl", profile.create_features)
        self.assertNotIn("virtualmin-nginx", profile.create_features)
        self.assertEqual(profile.acme_flag, "letsencrypt")

    def test_parent_nginx_wins_when_both_flags_exist(self):
        profile = detect_web_stack(
            create_flags={"dir", "dns", "logrotate", "web", "ssl", "virtualmin-nginx", "virtualmin-nginx-ssl", "acme"},
            parent_features={"unix", "dir", "dns", "mail", "virtualmin-nginx", "virtualmin-nginx-ssl"},
            os_has_nginx=True,
            os_has_apache=True,
        )
        self.assertEqual(profile.flavor, "nginx")
        self.assertIn("virtualmin-nginx", profile.create_features)
        self.assertNotIn("web", profile.create_features)

    def test_parent_apache_wins_when_both_flags_exist(self):
        profile = detect_web_stack(
            create_flags={"dir", "dns", "logrotate", "web", "ssl", "virtualmin-nginx", "virtualmin-nginx-ssl"},
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

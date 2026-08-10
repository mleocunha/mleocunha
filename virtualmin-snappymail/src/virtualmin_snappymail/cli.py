"""CLI entrypoint for virtualmin-snappymail."""

from __future__ import annotations

import argparse
import json
import sys
from typing import Any, Callable

from . import get_manager_version
from .domain import normalize_domain
from .environment import audit_environment
from .errors import AlreadyInstalledError, VSMError
from .logging_util import setup_logging
from .ops import (
    adopt_all,
    adopt_domain,
    diagnose_domain,
    discover_installations,
    install_domain,
    remove_domain,
    repair_domain,
    status_all,
    status_for_domain,
    upgrade_domain,
)
from .virtualmin_client import VirtualminClient


def _print_json(data: Any) -> None:
    print(json.dumps(data, indent=2, sort_keys=True, default=str))


def _print_status_table(rows) -> None:
    header = f"{'DOMAIN':<32} {'SNAPPYMAIL':<12} {'HTTPS':<7} {'IMAP':<7} {'SMTP':<7} {'MODE'}"
    print(header)
    for r in rows:
        print(
            f"{r.domain:<32} {r.snappymail:<12} {r.https:<7} {r.imap:<7} {r.smtp:<7} {r.mode}"
        )


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="virtualmin-snappymail",
        description="Manage per-domain SnappyMail web-only subservers on Virtualmin",
    )
    p.add_argument("--version", action="version", version=f"%(prog)s {get_manager_version()}")
    p.add_argument("--json", action="store_true", help="Structured JSON output")
    p.add_argument("--verbose", action="store_true")
    p.add_argument("--debug", action="store_true")
    p.add_argument("--virtualmin-bin", default=None, help="Path to virtualmin CLI")

    sub = p.add_subparsers(dest="command", required=True)

    def add_domain_arg(sp):
        sp.add_argument("domain", help="Parent virtual server domain (e.g. exemplo.com.br)")

    sp = sub.add_parser("audit", help="Audit local Virtualmin/mail/web environment (read-only)")
    sp = sub.add_parser("install", help="Install SnappyMail for a mail-enabled parent domain")
    add_domain_arg(sp)
    sp.add_argument("--snappy-version", default="latest")
    sp.add_argument("--no-letsencrypt", action="store_true")

    sp = sub.add_parser("status", help="Show SnappyMail status")
    sp.add_argument("domain", nargs="?", default=None)
    sp.add_argument("--all", action="store_true")

    sp = sub.add_parser("diagnose", help="Deep diagnostics for a domain")
    add_domain_arg(sp)

    sp = sub.add_parser("repair", help="Repair safe configuration issues")
    add_domain_arg(sp)

    sp = sub.add_parser("upgrade", help="Transactional SnappyMail upgrade")
    add_domain_arg(sp)
    sp.add_argument("--snappy-version", default="latest")

    sp = sub.add_parser("remove", help="Remove SnappyMail application (optionally subserver)")
    add_domain_arg(sp)
    sp.add_argument("--remove-subserver", action="store_true", help="Also delete webmail sub-server")
    sp.add_argument("--yes", action="store_true", help="Confirm removal")

    sp = sub.add_parser("discover", help="Discover existing SnappyMail installations")
    sp = sub.add_parser("adopt", help="Adopt existing installations without destructive reinstall")
    sp.add_argument("domain", nargs="?", default=None)
    sp.add_argument("--all", action="store_true")

    return p


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    logger = setup_logging(verbose=args.verbose, debug=args.debug)
    client = VirtualminClient(binary=args.virtualmin_bin)

    try:
        if args.command == "audit":
            report = audit_environment(client)
            if args.json:
                _print_json(report.to_dict())
            else:
                print(f"OS: {report.os_release.get('PRETTY_NAME', report.os_release)}")
                print(f"Python: {report.python}")
                print(f"Virtualmin available: {report.virtualmin_available}")
                print(f"Virtualmin version: {report.virtualmin_version}")
                print(f"Webmin version: {report.webmin_version}")
                print(f"Web server: {report.web_server}")
                print(f"PHP: {report.php.get('version')}")
                print("Tools:")
                for k, v in report.tools.items():
                    print(f"  {k}: {v or 'NOT FOUND'}")
                print("Mail topology:")
                print(json.dumps(report.mail, indent=2))
                if report.notes:
                    print("Notes:")
                    for n in report.notes:
                        print(f"  - {n}")
            return 0

        if args.command in {
            "install",
            "status",
            "diagnose",
            "repair",
            "upgrade",
            "remove",
            "discover",
            "adopt",
        } and args.command != "audit":
            if not client.available() and args.command not in {"status"}:
                # status may still partially work for mail topology, but Virtualmin needed
                if args.command != "discover":
                    raise VSMError(
                        "Virtualmin CLI not found. Run on a Virtualmin host or pass --virtualmin-bin.",
                        code="VSM-VIRTUALMIN",
                    )

        if args.command == "install":
            result = install_domain(
                client,
                args.domain,
                logger=logger,
                version=None if args.snappy_version == "latest" else args.snappy_version,
                with_letsencrypt=not args.no_letsencrypt,
            )
            if args.json:
                _print_json(result)
            else:
                print(f"Installed SnappyMail {result['version']} for {result['parent_domain']}")
                print(f"URL: {result['url']}")
                print(f"Subserver: {result['webmail_domain']} (web-only)")
                print(f"Document root: {result['document_root']}")
                print(f"Mail identity domain: {result['mail_identity_domain']}")
            return 0

        if args.command == "status":
            if args.all or not args.domain:
                rows = status_all(client)
            else:
                rows = [status_for_domain(client, args.domain)]
            if args.json:
                _print_json([r.to_dict() for r in rows])
            else:
                _print_status_table(rows)
            return 0

        if args.command == "diagnose":
            checks = diagnose_domain(client, args.domain)
            if args.json:
                _print_json([c.to_dict() for c in checks])
            else:
                width = max(len(c.name) for c in checks) if checks else 10
                for c in checks:
                    flag = "OK" if c.ok else "FAIL"
                    print(f"{c.name:<{width}}  {flag:<4}  {c.detail}")
                failed = sum(1 for c in checks if not c.ok)
                return 1 if failed else 0
            return 0 if all(c.ok for c in checks) else 1

        if args.command == "repair":
            result = repair_domain(client, args.domain, logger=logger)
            if args.json:
                _print_json(result)
            else:
                print(f"Repaired {result['parent_domain']}")
                for a in result["actions"]:
                    print(f"  - {a}")
            return 0

        if args.command == "upgrade":
            result = upgrade_domain(
                client,
                args.domain,
                version=None if args.snappy_version == "latest" else args.snappy_version,
                logger=logger,
            )
            if args.json:
                _print_json(result)
            else:
                print(
                    f"Upgraded {result['parent_domain']}: "
                    f"{result['version_before']} -> {result['version_after']}"
                )
                if result.get("backup"):
                    print(f"Backup: {result['backup']}")
            return 0

        if args.command == "remove":
            if not args.yes:
                print("Refusing to remove without --yes", file=sys.stderr)
                return 2
            result = remove_domain(
                client,
                args.domain,
                remove_subserver=args.remove_subserver,
                logger=logger,
            )
            if args.json:
                _print_json(result)
            else:
                print(f"Removed SnappyMail application for {result['parent_domain']}")
                if result["subserver_removed"]:
                    print(f"Deleted subserver {result['webmail_domain']}")
                if result.get("backup"):
                    print(f"Backup: {result['backup']}")
            return 0

        if args.command == "discover":
            hits = discover_installations(client)
            if args.json:
                _print_json([h.to_dict() for h in hits])
            else:
                if not hits:
                    print("No SnappyMail installations discovered.")
                for h in hits:
                    flag = "FOUND"
                    print(f"{flag:<8} {h.webmail_domain}  parent={h.parent_domain}  ver={h.version or '-'}  managed={h.managed}")
            return 0

        if args.command == "adopt":
            if args.all or not args.domain:
                results = adopt_all(client, logger=logger)
            else:
                results = [adopt_domain(client, args.domain, logger=logger)]
            if args.json:
                _print_json(results)
            else:
                for r in results:
                    if "error" in r:
                        print(f"FAIL {r.get('parent_domain')}: {r['error']} {r.get('message')}")
                    else:
                        print(f"ADOPTED {r['webmail_domain']} (v{r.get('version')})")
            return 0

        parser.error(f"Unknown command {args.command}")
        return 2

    except AlreadyInstalledError as exc:
        if args.json:
            _print_json(exc.to_dict())
        else:
            print(str(exc), file=sys.stderr)
            print("SnappyMail already installed. Use upgrade, repair or remove.", file=sys.stderr)
        return 0  # idempotent success-ish; still message clearly
    except VSMError as exc:
        if args.json:
            _print_json(exc.to_dict())
        else:
            print(f"ERROR [{exc.code}] {exc.message}", file=sys.stderr)
        return 1
    except Exception as exc:  # noqa: BLE001
        logger.exception("Unhandled error")
        if args.json:
            _print_json({"error": "VSM-ERROR", "message": str(exc)})
        else:
            print(f"ERROR [VSM-ERROR] {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

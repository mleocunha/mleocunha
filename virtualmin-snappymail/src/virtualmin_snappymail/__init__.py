"""Virtualmin SnappyMail manager package."""

from __future__ import annotations

from importlib.metadata import PackageNotFoundError, version
from pathlib import Path

_ROOT = Path(__file__).resolve().parents[2]
_VERSION_FILE = _ROOT / "VERSION"


def get_manager_version() -> str:
    if _VERSION_FILE.is_file():
        return _VERSION_FILE.read_text(encoding="utf-8").strip()
    try:
        return version("virtualmin-snappymail")
    except PackageNotFoundError:
        return "0.0.0-dev"


__version__ = get_manager_version()

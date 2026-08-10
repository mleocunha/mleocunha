"""Local management manifest for portable discover/adopt."""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any

from . import get_manager_version
from .logging_util import utc_now_iso
from .security import atomic_write_text

MANIFEST_NAME = ".virtualmin-snappymail.json"
SCHEMA_VERSION = 1


@dataclass
class Manifest:
    managed: bool = True
    schema_version: int = SCHEMA_VERSION
    deployment: str = "full-copy"
    manager_version: str = field(default_factory=get_manager_version)
    parent_domain: str = ""
    webmail_domain: str = ""
    version: str = ""
    installed_at: str = ""
    updated_at: str = ""
    document_root: str = "public_html"
    mail_identity_domain: str = ""
    notes: str = ""

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "Manifest":
        known = {f.name for f in cls.__dataclass_fields__.values()}  # type: ignore[attr-defined]
        filtered = {k: v for k, v in data.items() if k in known}
        return cls(**filtered)


def manifest_path_for_home(home: Path) -> Path:
    return Path(home) / MANIFEST_NAME


def load_manifest(path: Path) -> Manifest | None:
    path = Path(path)
    if not path.is_file():
        return None
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"Manifest is not an object: {path}")
    return Manifest.from_dict(data)


def save_manifest(path: Path, manifest: Manifest) -> None:
    manifest.updated_at = utc_now_iso()
    if not manifest.installed_at:
        manifest.installed_at = manifest.updated_at
    manifest.manager_version = get_manager_version()
    atomic_write_text(path, json.dumps(manifest.to_dict(), indent=2, sort_keys=True) + "\n", mode=0o640)


def find_manifest_near(docroot: Path) -> Path | None:
    """Search common locations relative to a SnappyMail document root."""
    docroot = Path(docroot)
    candidates = [
        docroot.parent / MANIFEST_NAME,
        docroot / MANIFEST_NAME,
        docroot.parent.parent / MANIFEST_NAME,
    ]
    for c in candidates:
        if c.is_file():
            return c
    return None

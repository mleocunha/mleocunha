"""SnappyMail download, install, configure, upgrade helpers."""

from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import tarfile
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

from .errors import DownloadError, IntegrityError, UpgradeError
from .mail_discovery import MailTopology, snappymail_domain_ini
from .security import confined_path, secure_tmpdir

GITHUB_API_LATEST = "https://api.github.com/repos/the-djmaze/snappymail/releases/latest"
GITHUB_RELEASE_ASSET = (
    "https://github.com/the-djmaze/snappymail/releases/download/v{version}/snappymail-{version}.tar.gz"
)
DEFAULT_VERSION = "2.38.2"

# Markers that identify a SnappyMail tree.
SNAPPY_MARKERS = ("index.php", "snappymail", "data")


@dataclass
class ReleaseInfo:
    version: str
    tarball_url: str
    size: int | None = None
    digest_sha256: str | None = None


def looks_like_snappymail(docroot: Path) -> bool:
    docroot = Path(docroot)
    if not docroot.is_dir():
        return False
    index = docroot / "index.php"
    if not index.is_file():
        return False
    text = index.read_text(encoding="utf-8", errors="replace")
    if "snappymail" not in text.lower() and "rainloop" not in text.lower():
        # Still accept if snappymail/ dir exists
        if not (docroot / "snappymail").is_dir():
            return False
    return (docroot / "data").exists() or (docroot / "snappymail").is_dir()


def detect_installed_version(docroot: Path) -> str | None:
    docroot = Path(docroot)
    candidates = [
        docroot / "data" / "VERSION",
        docroot / "snappymail" / "v",
    ]
    version_file = docroot / "data" / "VERSION"
    if version_file.is_file():
        return version_file.read_text(encoding="utf-8", errors="replace").strip() or None
    # Fallback: newest snappymail/v/X.Y.Z
    vdir = docroot / "snappymail" / "v"
    if vdir.is_dir():
        versions = sorted(
            [p.name for p in vdir.iterdir() if p.is_dir() and re.match(r"^\d+\.\d+", p.name)],
            key=lambda s: tuple(int(x) for x in re.findall(r"\d+", s)),
        )
        if versions:
            return versions[-1]
    # package.json / APP_VERSION in index
    index = docroot / "index.php"
    if index.is_file():
        m = re.search(r"(\d+\.\d+\.\d+)", index.read_text(encoding="utf-8", errors="replace"))
        if m:
            return m.group(1)
    return None


def fetch_latest_release(opener: Callable[..., object] | None = None) -> ReleaseInfo:
    open_fn = opener or urllib.request.urlopen
    try:
        with open_fn(urllib.request.Request(GITHUB_API_LATEST, headers={"User-Agent": "virtualmin-snappymail"})) as resp:  # type: ignore[arg-type]
            data = json.loads(resp.read().decode("utf-8"))
    except Exception as exc:  # noqa: BLE001
        raise DownloadError(f"Failed to query SnappyMail releases: {exc}") from exc

    tag = str(data.get("tag_name", "")).lstrip("v")
    assets = data.get("assets") or []
    tarball = None
    for asset in assets:
        name = asset.get("name") or ""
        if name == f"snappymail-{tag}.tar.gz":
            tarball = asset
            break
    if not tarball:
        raise DownloadError(f"Release asset snappymail-{tag}.tar.gz not found")
    return ReleaseInfo(
        version=tag,
        tarball_url=tarball["browser_download_url"],
        size=tarball.get("size"),
    )


def resolve_release(version: str | None = None) -> ReleaseInfo:
    if version in (None, "", "latest"):
        try:
            return fetch_latest_release()
        except DownloadError:
            return ReleaseInfo(
                version=DEFAULT_VERSION,
                tarball_url=GITHUB_RELEASE_ASSET.format(version=DEFAULT_VERSION),
            )
    version = version.lstrip("v")
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise DownloadError(f"Invalid SnappyMail version: {version}")
    return ReleaseInfo(version=version, tarball_url=GITHUB_RELEASE_ASSET.format(version=version))


def download_release(release: ReleaseInfo, dest: Path, *, expected_sha256: str | None = None) -> Path:
    dest = Path(dest)
    dest.parent.mkdir(parents=True, exist_ok=True)
    try:
        req = urllib.request.Request(release.tarball_url, headers={"User-Agent": "virtualmin-snappymail"})
        with urllib.request.urlopen(req, timeout=120) as resp, open(dest, "wb") as out:
            hasher = hashlib.sha256()
            while True:
                chunk = resp.read(1024 * 256)
                if not chunk:
                    break
                hasher.update(chunk)
                out.write(chunk)
            digest = hasher.hexdigest()
    except Exception as exc:  # noqa: BLE001
        raise DownloadError(f"Download failed for {release.tarball_url}: {exc}") from exc

    expected = expected_sha256 or release.digest_sha256
    if expected and digest.lower() != expected.lower():
        dest.unlink(missing_ok=True)
        raise IntegrityError(f"SHA256 mismatch for {dest.name}: got {digest}")
    # Record digest sidecar for audit (not a secret)
    dest.with_suffix(dest.suffix + ".sha256").write_text(f"{digest}  {dest.name}\n", encoding="utf-8")
    return dest


def _safe_extract_tar(tar: tarfile.TarFile, path: Path) -> None:
    path = Path(path)
    for member in tar.getmembers():
        member_path = path / member.name
        # Prevent path traversal
        if not str(member_path.resolve()).startswith(str(path.resolve())):
            raise IntegrityError(f"Tar member escapes destination: {member.name}")
        if member.issym() or member.islnk():
            # Skip external links for safety; SnappyMail package shouldn't need them
            continue
    tar.extractall(path=path, filter="data")


def extract_snappymail_tarball(tarball: Path, docroot: Path) -> None:
    docroot = Path(docroot)
    docroot.mkdir(parents=True, exist_ok=True)
    with tarfile.open(tarball, "r:gz") as tar:
        _safe_extract_tar(tar, docroot)


def apply_ownership(path: Path, user: str, group: str | None = None) -> None:
    if os.geteuid() != 0:
        return
    group = group or user
    try:
        shutil.chown(path, user=user, group=group)
        for root, dirs, files in os.walk(path):
            for name in dirs + files:
                shutil.chown(os.path.join(root, name), user=user, group=group)
    except LookupError:
        # User may not exist in test environments
        pass


def apply_permissions(docroot: Path) -> None:
    """Least-privilege defaults: dirs 755, files 644, data writable by owner."""
    docroot = Path(docroot)
    for root, dirs, files in os.walk(docroot):
        for d in dirs:
            os.chmod(os.path.join(root, d), 0o755)
        for f in files:
            os.chmod(os.path.join(root, f), 0o644)
    data = docroot / "data"
    if data.is_dir():
        for root, dirs, files in os.walk(data):
            for d in dirs:
                os.chmod(os.path.join(root, d), 0o770)
            for f in files:
                os.chmod(os.path.join(root, f), 0o660)


def configure_domain(
    docroot: Path,
    *,
    parent_domain: str,
    topo: MailTopology,
) -> Path:
    """Write SnappyMail domain config for parent mail identity."""
    docroot = Path(docroot)
    domains_dir = confined_path(docroot, "data", "_data_", "_default_", "domains")
    domains_dir.mkdir(parents=True, exist_ok=True)
    ini_path = domains_dir / f"{parent_domain}.ini"
    ini_path.write_text(snappymail_domain_ini(parent_domain=parent_domain, topo=topo), encoding="utf-8")
    os.chmod(ini_path, 0o640)

    # Soft hint in application.ini if present / creatable
    cfg_dir = confined_path(docroot, "data", "_data_", "_default_", "configs")
    cfg_dir.mkdir(parents=True, exist_ok=True)
    app_ini = cfg_dir / "application.ini"
    if not app_ini.exists():
        app_ini.write_text(
            "\n".join(
                [
                    '[webmail]',
                    f'language = "pt-BR"',
                    f'title = "Webmail"',
                    "",
                    '[login]',
                    'determine_user_domain = On',
                    f'default_domain = "{parent_domain}"',
                    "",
                ]
            ),
            encoding="utf-8",
        )
        os.chmod(app_ini, 0o640)
    return ini_path


def install_fresh(
    docroot: Path,
    *,
    parent_domain: str,
    topo: MailTopology,
    version: str | None = None,
    user: str | None = None,
    group: str | None = None,
    release: ReleaseInfo | None = None,
) -> str:
    release = release or resolve_release(version)
    with secure_tmpdir("vsm-dl-") as tmp:
        tarball = Path(tmp) / f"snappymail-{release.version}.tar.gz"
        download_release(release, tarball)
        # Clear placeholder Virtualmin index if present and empty-ish
        if docroot.exists():
            for child in list(docroot.iterdir()):
                # Do not wipe an existing SnappyMail data dir accidentally on reinstall paths
                if child.name in {"data"} and looks_like_snappymail(docroot):
                    raise UpgradeError("Existing SnappyMail data present; use upgrade/repair")
                if child.name in {"index.html", "index.php", "nocontent.html"}:
                    child.unlink(missing_ok=True)
        extract_snappymail_tarball(tarball, docroot)
    if not looks_like_snappymail(docroot):
        raise IntegrityError(f"Extracted tree does not look like SnappyMail: {docroot}")
    configure_domain(docroot, parent_domain=parent_domain, topo=topo)
    apply_permissions(docroot)
    if user:
        apply_ownership(docroot, user, group)
    return detect_installed_version(docroot) or release.version


def create_code_backup(docroot: Path, backup_dir: Path) -> Path:
    backup_dir = Path(backup_dir)
    backup_dir.mkdir(parents=True, exist_ok=True)
    from datetime import datetime, timezone

    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    archive = backup_dir / f"snappymail-preupgrade-{stamp}.tar.gz"
    with tarfile.open(archive, "w:gz") as tar:
        tar.add(docroot, arcname="public_html")
    return archive


def upgrade_inplace(
    docroot: Path,
    *,
    parent_domain: str,
    topo: MailTopology,
    version: str | None = None,
    user: str | None = None,
    group: str | None = None,
) -> tuple[str, str, Path]:
    """Transactional upgrade preserving data/. Returns (old, new, backup_path)."""
    docroot = Path(docroot)
    if not looks_like_snappymail(docroot):
        raise UpgradeError(f"No SnappyMail installation at {docroot}")
    old = detect_installed_version(docroot) or "unknown"
    release = resolve_release(version)
    if old == release.version:
        return old, old, Path()

    backup_dir = docroot.parent / ".snappymail-backups"
    backup = create_code_backup(docroot, backup_dir)

    with secure_tmpdir("vsm-up-") as tmp:
        tarball = Path(tmp) / f"snappymail-{release.version}.tar.gz"
        download_release(release, tarball)
        staging = Path(tmp) / "staging"
        staging.mkdir()
        extract_snappymail_tarball(tarball, staging)

        data_src = docroot / "data"
        data_bak = Path(tmp) / "data-preserve"
        if data_src.exists():
            shutil.copytree(data_src, data_bak)

        include_files = []
        for name in ("include.php", "_include.php"):
            p = docroot / name
            if p.is_file():
                include_files.append((name, p.read_bytes()))

        try:
            # Remove code but keep data briefly
            for child in list(docroot.iterdir()):
                if child.name == "data":
                    continue
                if child.is_dir():
                    shutil.rmtree(child)
                else:
                    child.unlink()
            # Copy new code
            for child in staging.iterdir():
                dest = docroot / child.name
                if child.name == "data" and data_bak.exists():
                    continue
                if child.is_dir():
                    shutil.copytree(child, dest, dirs_exist_ok=True)
                else:
                    shutil.copy2(child, dest)
            if data_bak.exists() and not (docroot / "data").exists():
                shutil.copytree(data_bak, docroot / "data")
            for name, content in include_files:
                (docroot / name).write_bytes(content)
            configure_domain(docroot, parent_domain=parent_domain, topo=topo)
            apply_permissions(docroot)
            if user:
                apply_ownership(docroot, user, group)
            new = detect_installed_version(docroot) or release.version
            if not looks_like_snappymail(docroot):
                raise UpgradeError("Upgrade produced invalid tree")
            return old, new, backup
        except Exception as exc:
            # Rollback from backup
            with tarfile.open(backup, "r:gz") as tar:
                # Clear docroot
                for child in list(docroot.iterdir()):
                    if child.is_dir():
                        shutil.rmtree(child)
                    else:
                        child.unlink()
                tar.extractall(path=docroot.parent, filter="data")
            raise UpgradeError(f"Upgrade failed and was rolled back: {exc}") from exc

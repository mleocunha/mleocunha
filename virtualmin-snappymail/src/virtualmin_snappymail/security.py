"""Security helpers: escaping, path confinement, safe temp dirs."""

from __future__ import annotations

import os
import re
import tempfile
from pathlib import Path

_SAFE_RELATIVE = re.compile(r"^[A-Za-z0-9._@+-]+(?:/[A-Za-z0-9._@+-]+)*$")


def assert_safe_argv_token(token: str) -> str:
    """Reject tokens that could break argv or look like flags injection."""
    if token is None or token == "":
        raise ValueError("Empty argv token")
    if "\x00" in token:
        raise ValueError("NUL byte in argv token")
    if any(ch in token for ch in "\n\r"):
        raise ValueError("Newline in argv token")
    return token


def confined_path(base: Path, *parts: str) -> Path:
    """Join path parts under base and ensure result stays inside base."""
    base_resolved = base.resolve()
    candidate = base_resolved.joinpath(*parts).resolve()
    try:
        candidate.relative_to(base_resolved)
    except ValueError as exc:
        raise ValueError(f"Path escapes base directory: {candidate}") from exc
    return candidate


def secure_tmpdir(prefix: str = "vsm-") -> tempfile.TemporaryDirectory[str]:
    return tempfile.TemporaryDirectory(prefix=prefix)


def atomic_write_text(path: Path, content: str, mode: int = 0o640) -> None:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(prefix=".vsm-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(content)
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(tmp_name, mode)
        os.replace(tmp_name, path)
    finally:
        if os.path.exists(tmp_name):
            os.unlink(tmp_name)


def redact_secrets(text: str) -> str:
    patterns = [
        (re.compile(r"(password\s*[=:]\s*)\S+", re.I), r"\1***"),
        (re.compile(r"(pass\s*[=:]\s*)\S+", re.I), r"\1***"),
        (re.compile(r"(Authorization:\s*Basic\s+)\S+", re.I), r"\1***"),
        (re.compile(r"(--pass(?:word)?\s+)\S+", re.I), r"\1***"),
    ]
    out = text
    for cre, repl in patterns:
        out = cre.sub(repl, out)
    return out

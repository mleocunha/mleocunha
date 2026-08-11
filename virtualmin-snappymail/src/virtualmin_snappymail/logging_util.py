"""Administrative logging without secrets."""

from __future__ import annotations

import json
import logging
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from .security import redact_secrets

DEFAULT_LOG_PATH = Path("/var/log/virtualmin-snappymail.log")


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def setup_logging(*, verbose: bool = False, debug: bool = False, log_file: Path | None = None) -> logging.Logger:
    logger = logging.getLogger("virtualmin_snappymail")
    logger.handlers.clear()
    logger.setLevel(logging.DEBUG if debug else logging.INFO if verbose else logging.WARNING)

    fmt = logging.Formatter("%(asctime)s %(levelname)s %(message)s")
    sh = logging.StreamHandler(sys.stderr)
    sh.setFormatter(fmt)
    sh.setLevel(logging.DEBUG if debug else logging.INFO if verbose else logging.WARNING)
    logger.addHandler(sh)

    target = log_file or DEFAULT_LOG_PATH
    try:
        target.parent.mkdir(parents=True, exist_ok=True)
        fh = logging.FileHandler(target)
        fh.setFormatter(fmt)
        fh.setLevel(logging.INFO)
        logger.addHandler(fh)
    except OSError:
        # Non-root / missing path — stderr only.
        pass

    return logger


def audit_event(logger: logging.Logger, **fields: Any) -> None:
    payload = {"timestamp": utc_now_iso(), **fields}
    line = redact_secrets(json.dumps(payload, ensure_ascii=False, sort_keys=True))
    logger.info("AUDIT %s", line)

#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PYTHONPATH="$ROOT/src:${PYTHONPATH:-}"
python3 -m unittest discover -s "$ROOT/tests/unit" -v
python3 -m unittest discover -s "$ROOT/tests/integration" -v

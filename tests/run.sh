#!/usr/bin/env bash
# Slate test runner (Phase 0). Runs the dependency-free smoke suite.
# Usage: bash tests/run.sh   (exit 0 = pass, non-zero = fail)
set -euo pipefail
cd "$(dirname "$0")/.."
echo "== Slate unit tests =="
php tests/unit/run.php
echo "== Slate smoke tests =="
php tests/smoke.php

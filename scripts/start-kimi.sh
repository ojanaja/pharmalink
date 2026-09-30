#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

if ! command -v kimi >/dev/null 2>&1; then
  echo "Kimi Code CLI ('kimi') tidak ditemukan di PATH." >&2
  exit 1
fi

exec kimi --agent pharmalink-lead "$@"

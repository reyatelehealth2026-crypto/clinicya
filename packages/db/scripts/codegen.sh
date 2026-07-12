#!/usr/bin/env bash
# Thin ops/CI wrapper around src/codegen.ts — see packages/db/README.md for
# exact run instructions against a live DB. Prefers the built dist/ output if
# present (production), falls back to tsx against source (dev / this repo
# doesn't have dist/ committed).
#
# Usage:
#   scripts/codegen.sh master [--dry-run]
#   scripts/codegen.sh tenant --db=reya_tenant_0001 [--dry-run]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ -f dist/bin/codegen.js ]; then
  exec node dist/bin/codegen.js "$@"
else
  exec npx --no-install tsx src/bin/codegen.ts "$@"
fi

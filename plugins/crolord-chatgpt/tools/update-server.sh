#!/usr/bin/env bash
set -Eeuo pipefail
CROLORD_SOURCE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
exec bash "$CROLORD_SOURCE/enable-auto-update.sh"

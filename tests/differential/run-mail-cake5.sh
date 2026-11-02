#!/usr/bin/env bash
# Usage: run-mail-cake5.sh <out-dir>   (needs the volume calc5m-vendor or set VENDOR_VOLUME, image calendar-cake5:base)
set -euo pipefail
export MSYS_NO_PATHCONV=1
TREE="$(cd "$(dirname "$0")/../.." && pwd -W)"
OUT="$1"; mkdir -p "$OUT"; OUTW="$(cd "$OUT" && pwd -W)"
docker run --rm --network none -v "$TREE:/app" -v "${VENDOR_VOLUME:-calc5m-vendor}:/app/vendor" -v "$OUTW:/out" -w /app \
  "${BASE_IMAGE:-calendar-cake5:base}" php tests/differential/mail-cake5.php /out/mail-cake5.json

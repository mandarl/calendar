#!/usr/bin/env bash
# Usage: run-webhook.sh <out-dir>   (runs both versions; the sink and the script share one container each)
set -euo pipefail
export MSYS_NO_PATHCONV=1
HERE="$(cd "$(dirname "$0")" && pwd -W)"
TREE="$(cd "$(dirname "$0")/../.." && pwd -W)"
OUT="$1"; mkdir -p "$OUT"; OUTW="$(cd "$OUT" && pwd -W)"
START='php -S 127.0.0.1:4010 /diff/sink.php >/tmp/sink.out 2>&1 & sleep 1;'
docker run --rm --network none -v "$HERE:/diff:ro" -v "$OUTW:/out" calendar-baseline:1a086bead5 \
  sh -c "$START php /diff/webhook-cake3.php /out/webhook-cake3.json"
docker run --rm --network none -v "$TREE:/app" -v "${VENDOR_VOLUME:-calc5m-vendor}:/app/vendor" -v "$OUTW:/out" -w /app "${BASE_IMAGE:-calendar-cake5:base}" \
  sh -c "$(echo "$START" | sed 's#/diff/#/app/tests/differential/#') php tests/differential/webhook-cake5.php /out/webhook-cake5.json"

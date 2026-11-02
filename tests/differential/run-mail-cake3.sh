#!/usr/bin/env bash
# Usage: run-mail-cake3.sh <out-dir>   (Git Bash; needs docker and the image calendar-baseline:1a086bead5)
set -euo pipefail
export MSYS_NO_PATHCONV=1
HERE="$(cd "$(dirname "$0")" && pwd -W)"
OUT="$1"; mkdir -p "$OUT"; OUTW="$(cd "$OUT" && pwd -W)"
IMG=calendar-baseline:1a086bead5
# Overlay the plugin transport: identical except the API call is replaced by a file write.
docker run --rm --network none "$IMG" cat /var/www/vendor/dallasmakerspace/cakephp-sparkpost-plugin/src/Mailer/Transport/SparkPostTransport.php > "$OUT/SparkPostTransport.capture.php"
perl -0pi -e 's/\$promise = \$sparkpost->transmissions->post\(\$message\);\s*\$promise->wait\(\);/file_put_contents(getenv("CAPTURE_FILE"), json_encode(\$message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\x1e\n", FILE_APPEND);/' "$OUT/SparkPostTransport.capture.php"
grep -q CAPTURE_FILE "$OUT/SparkPostTransport.capture.php" || { echo "overlay patch did not apply"; exit 1; }
docker run --rm --network none \
  -v "$OUTW/SparkPostTransport.capture.php:/var/www/vendor/dallasmakerspace/cakephp-sparkpost-plugin/src/Mailer/Transport/SparkPostTransport.php:ro" \
  -v "$HERE:/diff:ro" -v "$OUTW:/out" \
  -e CAPTURE_FILE=/tmp/capture.jsonl \
  "$IMG" php /diff/mail-cake3.php /out/mail-cake3.json

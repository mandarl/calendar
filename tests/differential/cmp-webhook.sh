#!/usr/bin/env bash
export MSYS_NO_PATHCONV=1
docker run --rm --network none -v "$(cd "$(dirname "$0")" && pwd -W):/d:ro" -v "$(cd "$1" && pwd -W):/s:ro" "${BASE_IMAGE:-calendar-cake5:base}" php /d/compare-webhook.php /s/webhook-cake3.json /s/webhook-cake5.json

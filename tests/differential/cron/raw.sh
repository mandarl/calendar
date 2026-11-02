#!/bin/bash
# Raw-fixture probe for slice X cron(): same reset as run-diff.sh but with
# NO transaction-id reshaping and NO targeted probes. Documents the known
# divergence class (see CHANGES): Cake 3's Braintree 3.x validates the
# hyphenated fixture ids locally and throws, so Cake 3 cron 500s mid-loop
# (1 mail sent, event/registration untouched, later branches never run);
# the Cake 5 kit (Braintree 6.40) accepts those ids and completes the run.
# Usage: bash raw.sh <project> <prefix>
set -u
PROJECT=${1:?usage: raw.sh <project> <prefix>}
PREFIX=${2:?usage: raw.sh <project> <prefix>}
cd "$(dirname "$0")/../../../.." || exit 1
OUT=calendar-cake5-x/tests/differential/cron/out
mkdir -p "$OUT"
BASE=calendar-baselines/cake3-1a086bead5-synthetic-v1
docker exec -i "$PROJECT-db-1" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar' < "$BASE/00-schema-lookups.sql"
docker exec -i "$PROJECT-db-1" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar' < "$BASE/10-synthetic.sql"
docker exec "$PROJECT-app-1" curl -s -X DELETE http://mail:8025/api/v1/messages > /dev/null
docker exec "$PROJECT-app-1" curl -s -X POST http://localhost:3000/__reset > /dev/null
docker exec "$PROJECT-app-1" curl -s -o /dev/null -w "%{http_code}" http://localhost/events/cron > "$OUT/$PREFIX-raw-status.txt"
docker exec "$PROJECT-db-1" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -N -e "SELECT id, status FROM events WHERE id IN (1019,1033,1034); SELECT id, status FROM registrations WHERE id IN (3012,3013);"' > "$OUT/$PREFIX-raw-after.txt"
docker exec "$PROJECT-app-1" curl -s http://localhost:3000/__log > "$OUT/$PREFIX-raw-fakes.json"
echo "RAW-DONE $PREFIX: status=$(cat "$OUT/$PREFIX-raw-status.txt")"

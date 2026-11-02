#!/bin/bash
# Differential capture for slice X cron() (reshaped + targeted-probe run).
#
# Usage, from the DMS root on the home host, with the named stack already up:
#   bash calendar-cake5-x/tests/differential/cron/run-diff.sh calc5x cake5
#   bash calendar-cake5-x/tests/differential/cron/run-diff.sh calc5x3 cake3
# Writes <prefix>-*.txt/json into tests/differential/cron/out/ of the X copy.
# Compare two runs with: python3 tests/differential/cron/compare.py <outdir>
#
# What it does: resets the stack DB to the pristine synthetic dataset
# (00-schema-lookups.sql + 10-synthetic.sql), applies the W6 transaction-id
# reshaping (lib-valid ids preserving the sfs signal), then two targeted
# probes that the W6 spec does not cover on a fresh anchor: event 1031's
# attendee_cancellation is moved inside the +24h window so its cancellation
# reminder fires, and a part-of probe event 1099 (+ pending reg 3099) is
# inserted to prove the completion/cancel branches skip parts while the
# start-reminder branch still flips the part's reminder flag. Then it runs
# GET /events/cron twice (the second call proves idempotence), capturing
# full events/registrations dumps, MailHog messages and the fakes log each
# time. The raw-fixture variant (no reshaping, no probes) is raw.sh.
set -u
PROJECT=${1:?usage: run-diff.sh <project> <prefix>}
PREFIX=${2:?usage: run-diff.sh <project> <prefix>}
cd "$(dirname "$0")/../../../.." || exit 1
OUT=calendar-cake5-x/tests/differential/cron/out
mkdir -p "$OUT"
BASE=calendar-baselines/cake3-1a086bead5-synthetic-v1
APP="$PROJECT-app-1"
DB="$PROJECT-db-1"
SQL="docker exec $DB sh -c 'mysql -uroot -p\"\$MARIADB_ROOT_PASSWORD\" dms_calendar'"
echo "step: reset db"
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar' < "$BASE/00-schema-lookups.sql"
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar' < "$BASE/10-synthetic.sql"
echo "step: reshape + probes"
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -e "UPDATE registrations SET transaction_id='"'"'fxsfs0002'"'"' WHERE id=3012; UPDATE registrations SET transaction_id='"'"'fxsettled0004'"'"' WHERE id=3013;"'
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -e "UPDATE events SET attendee_cancellation='"'"'2031-01-16 04:00:00'"'"' WHERE id=1031;"'
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -e "INSERT INTO events (id, name, short_description, long_description, advisories, event_start, event_end, booking_start, booking_end, cost, eventbrite_link, free_spaces, paid_spaces, members_only, age_restriction, attendees_require_approval, attendee_cancellation, extend_registration, class_number, sponsored, status, room_id, contact_id, fulfills_prerequisite_id, requires_prerequisite_id, part_of_id, copy_of_id, rejected_by, rejection_reason, created_by, cancel_notification, reminder_notification, created, modified, notifyInstructorRegistrations, notifyInstructorCancellations) SELECT 1099, '"'"'Fixture: Cron Part Probe'"'"', short_description, long_description, advisories, '"'"'2031-01-12 00:00:00'"'"', '"'"'2031-01-12 02:00:00'"'"', '"'"'2031-01-11 23:30:00'"'"', '"'"'2031-01-12 02:30:00'"'"', cost, eventbrite_link, free_spaces, paid_spaces, members_only, age_restriction, attendees_require_approval, '"'"'2031-01-11 00:00:00'"'"', extend_registration, class_number, sponsored, '"'"'approved'"'"', room_id, contact_id, fulfills_prerequisite_id, requires_prerequisite_id, 1019, copy_of_id, rejected_by, rejection_reason, created_by, 0, 0, created, modified, notifyInstructorRegistrations, notifyInstructorCancellations FROM events WHERE id=1019;"'
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -e "INSERT INTO registrations (id, event_id, type, name, email, phone, ad_username, send_text, edit_key, status, attended, ad_assigned, transaction_id, created, modified) VALUES (3099, 1099, '"'"'paid'"'"', '"'"'Test Attendee 99'"'"', '"'"'attendee.3099@example.test'"'"', '"'"'555-0299'"'"', NULL, 0, '"'"'edit-key-3099'"'"', '"'"'pending'"'"', 0, 0, '"'"'fxsfs0099'"'"', '"'"'2031-01-14 16:00:00'"'"', '"'"'2031-01-14 16:00:00'"'"');"'
echo "step: before dumps"
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -N -e "SELECT * FROM events ORDER BY id;"' > "$OUT/$PREFIX-before-events.txt"
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -N -e "SELECT * FROM registrations ORDER BY id;"' > "$OUT/$PREFIX-before-regs.txt"
echo "step: clear + cron1"
docker exec "$APP" curl -s -X DELETE http://mail:8025/api/v1/messages > /dev/null
docker exec "$APP" curl -s -X POST http://localhost:3000/__reset > /dev/null
docker exec "$APP" curl -s -o /dev/null -w "%{http_code}" http://localhost/events/cron > "$OUT/$PREFIX-cron1-status.txt"
echo "step: after1"
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -N -e "SELECT * FROM events ORDER BY id;"' > "$OUT/$PREFIX-after1-events.txt"
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -N -e "SELECT * FROM registrations ORDER BY id;"' > "$OUT/$PREFIX-after1-regs.txt"
docker exec "$APP" curl -s "http://mail:8025/api/v2/messages?limit=100" > "$OUT/$PREFIX-mail1.json"
docker exec "$APP" curl -s http://localhost:3000/__log > "$OUT/$PREFIX-fakes1.json"
docker exec "$APP" curl -s http://localhost:4010/__log > "$OUT/$PREFIX-sink1.json"
echo "step: clear + cron2"
docker exec "$APP" curl -s -X DELETE http://mail:8025/api/v1/messages > /dev/null
docker exec "$APP" curl -s -X POST http://localhost:3000/__reset > /dev/null
docker exec "$APP" curl -s -o /dev/null -w "%{http_code}" http://localhost/events/cron > "$OUT/$PREFIX-cron2-status.txt"
echo "step: after2"
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -N -e "SELECT * FROM events ORDER BY id;"' > "$OUT/$PREFIX-after2-events.txt"
docker exec "$DB" sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" dms_calendar -N -e "SELECT * FROM registrations ORDER BY id;"' > "$OUT/$PREFIX-after2-regs.txt"
docker exec "$APP" curl -s "http://mail:8025/api/v2/messages?limit=100" > "$OUT/$PREFIX-mail2.json"
docker exec "$APP" curl -s http://localhost:3000/__log > "$OUT/$PREFIX-fakes2.json"
echo "CAPTURE-DONE $PREFIX: cron1=$(cat "$OUT/$PREFIX-cron1-status.txt") cron2=$(cat "$OUT/$PREFIX-cron2-status.txt")"

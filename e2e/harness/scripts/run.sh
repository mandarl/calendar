#!/usr/bin/env bash
# Run the baseline suite in the harness.
#   run.sh [playwright args...]          e.g. run.sh --project=smoke --project=readonly
# Env: UPDATE=1 (update snapshots), RECORD_HAR=1 (use the egress runner), SKIP_UP=1 (stack already up),
#      CALENDAR_IMAGE / ADAPTER / BASELINE_ID / ANCHOR (see env.sh).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
. "$HERE/env.sh"
cd "$REPO"

BASE_SERVICES="db ldap mail faketime-init app fakes"

# The stored goldens are the CAKE 3 BASELINE. A different adapter (e.g. cake5) is compared against them and must
# never overwrite them.
if [ -n "${UPDATE:-}" ] && [ "$ADAPTER" != "cake3" ]; then
  echo "refusing UPDATE=1 with ADAPTER=$ADAPTER: that would overwrite the Cake 3 baseline goldens" >&2
  exit 2
fi

if [ -z "${SKIP_UP:-}" ]; then
  $C up -d $BASE_SERVICES
  # Re-anchor the faked clock ("now" = anchor + a few minutes) and re-join the app's network namespace.
  $C restart app
  $C restart fakes
fi

# Wait for the app to answer.
for i in $(seq 1 60); do
  if $C exec -T app curl -s -o /dev/null http://localhost/users/login; then break; fi
  sleep 2
  [ "$i" = 60 ] && { echo "app did not become ready" >&2; exit 1; }
done
# Uploads volume must be writable by www-data.
$C exec -T app sh -c 'mkdir -p /var/www/webroot/files && chmod 777 /var/www/webroot/files'

RUNNER=pw
PROFILE=test
[ -n "${RECORD_HAR:-}" ] && { RUNNER=pw-har; PROFILE=har; }

ARGS=("$@")
[ -n "${UPDATE:-}" ] && ARGS+=(--update-snapshots)

# node_modules lives in a named volume; install once (needs egress), then runs are offline.
$C --profile har run --rm -T pw-har sh -c '[ -x node_modules/.bin/playwright ] || npm install --no-audit --no-fund'

# The synthetic dataset is generated, never hand-edited: regenerate it so the loaded SQL cannot lag the catalog.
#
# Several harness projects share one baseline dir, so never rewrite a file another project may be reading: generate to
# a private temp name, replace the real file ONLY if the content changed (it is deterministic, so normally it does not),
# and replace atomically.
if [ "$DATASET" = "synthetic" ]; then
  $C --profile test run --rm -T -e COMPOSE_TMP="$COMPOSE_PROJECT" pw sh -c 'node_modules/.bin/tsx fixtures/synthetic/build-sql.ts "$BASELINE_DIR/.10-synthetic.$COMPOSE_TMP.tmp"'
  TMPF="$BASEROOT/$BASELINE_ID/.10-synthetic.$COMPOSE_PROJECT.tmp"
  REAL="$BASEROOT/$BASELINE_ID/10-synthetic.sql"
  if [ -f "$REAL" ] && cmp -s "$TMPF" "$REAL"; then
    rm -f "$TMPF"            # unchanged: leave the real file alone
  else
    mv -f "$TMPF" "$REAL"    # new or changed: atomic replace
  fi
fi

$C --profile "$PROFILE" run --rm -T "$RUNNER" npx playwright test "${ARGS[@]}"

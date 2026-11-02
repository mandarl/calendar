#!/usr/bin/env bash
# Source this: `. e2e/harness/scripts/env.sh`. Exports the variables compose.baseline.yml needs and
# defines $C, the docker compose command line for the harness project.
#
# Several people/agents can run the harness at the same time by choosing different COMPOSE_PROJECT names
# (e.g. COMPOSE_PROJECT=calcake5). Each project gets its own containers, database, LDAP, volumes, saved logins
# (.auth-<project>) and Playwright output (test-results-<project>, playwright-report-<project>). Never run two
# runs against the SAME project at once: each run resets that project's database.
export MSYS_NO_PATHCONV=1

_HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && { pwd -W 2>/dev/null || pwd; })"
export REPO="$(cd "$_HERE/../../.." && { pwd -W 2>/dev/null || pwd; })"
export SHA="${SHA:-$(git -C "$REPO" rev-parse --short=10 HEAD)}"
export BASEROOT="${BASEROOT:-$(cd "$REPO/.." && { pwd -W 2>/dev/null || pwd; })/calendar-baselines}"
export CALENDAR_IMAGE="${CALENDAR_IMAGE:-calendar-baseline:$SHA}"
export ADAPTER="${ADAPTER:-cake3}"
export DATASET="${DATASET:-synthetic}"
export BASELINE_ID="${BASELINE_ID:-cake3-$SHA-$DATASET-v1}"
export ANCHOR="${ANCHOR:-2031-01-15T16:00:00Z}"

export COMPOSE_PROJECT="${COMPOSE_PROJECT:-calbase}"
if [ "$COMPOSE_PROJECT" = "calbase" ]; then _SUFFIX=""; else _SUFFIX="-$COMPOSE_PROJECT"; fi
export AUTH_DIR=".auth$_SUFFIX"
export PW_OUT_DIR="test-results$_SUFFIX"
export PW_REPORT_DIR="playwright-report$_SUFFIX"

C="docker compose -p $COMPOSE_PROJECT --env-file $REPO/e2e/harness/baseline.env -f $REPO/e2e/harness/compose.baseline.yml"
mkdir -p "$BASEROOT/$BASELINE_ID"

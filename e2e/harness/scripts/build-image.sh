#!/usr/bin/env bash
# Build the image under test from a clean, LF-normalised `git archive` of a commit.
# Usage: REF=HEAD ./build-image.sh   (env: BASEROOT, CALENDAR_IMAGE optional)
#
# The archive is taken with core.autocrlf=false so Windows checkouts do not bake CRLF
# into the production image (Dockerfile does `COPY . .`). The Dockerfile itself is read
# from the working tree (-f), so uncommitted Dockerfile fixes are honoured.
set -euo pipefail
export MSYS_NO_PATHCONV=1

# `pwd -W` yields C:/... on Git Bash so native Windows git/docker/tar understand it (needed because
# MSYS_NO_PATHCONV=1 stops automatic conversion); on Linux/macOS it falls back to plain pwd.
HERE="$(cd "$(dirname "$0")" && { pwd -W 2>/dev/null || pwd; })"
REPO="$(cd "$HERE/../../.." && { pwd -W 2>/dev/null || pwd; })"
REF="${REF:-HEAD}"
SHA="$(git -C "$REPO" rev-parse --short=10 "$REF")"
BASEROOT="${BASEROOT:-$REPO/../calendar-baselines}"
CALENDAR_IMAGE="${CALENDAR_IMAGE:-calendar-baseline:$SHA}"

# A fresh, uniquely named context per build: nothing is deleted or reused (old contexts are small, source-only).
CTX="$BASEROOT/_build/ctx-$SHA-$(date +%Y%m%d%H%M%S)"
mkdir -p "$CTX"
git -C "$REPO" -c core.autocrlf=false archive --format=tar "$REF" | tar --force-local -x -C "$CTX"

docker build -f "$REPO/Dockerfile" --target production \
  --label "org.opencontainers.image.revision=$(git -C "$REPO" rev-parse "$REF")" \
  -t "$CALENDAR_IMAGE" "$CTX"

# Prove the dev/CI target builds too.
docker build -f "$REPO/Dockerfile" --target develop -t "calendar-dev-check:$SHA" "$CTX"

echo "built $CALENDAR_IMAGE (ref $REF, $SHA)"

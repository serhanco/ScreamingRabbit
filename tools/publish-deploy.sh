#!/usr/bin/env bash
#
# Commits a tree built by tools/build-deploy.sh onto the `deploy` branch and pushes it.
# History is kept linear (no force push), so the hosting Git screen can always fast-forward.
#
#   bash tools/publish-deploy.sh <built-tree> <remote-url> [branch]

set -euo pipefail

BUILT="${1:?usage: bash tools/publish-deploy.sh <built-tree> <remote-url> [branch]}"
REMOTE="${2:?remote url missing}"
BRANCH="${3:-deploy}"
SOURCE_SHA="$(git rev-parse --short HEAD)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if git ls-remote --exit-code --heads "$REMOTE" "$BRANCH" >/dev/null 2>&1; then
    git clone --quiet --depth 1 --branch "$BRANCH" "$REMOTE" "$WORK"
else
    git init --quiet "$WORK"
    git -C "$WORK" checkout --quiet --orphan "$BRANCH"
    git -C "$WORK" remote add origin "$REMOTE"
fi

find "$WORK" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -a "$BUILT/." "$WORK/"

git -C "$WORK" add -A
if git -C "$WORK" diff --cached --quiet; then
    echo "Değişiklik yok, deploy dalı güncel."
    exit 0
fi

git -C "$WORK" -c user.name="${GIT_AUTHOR_NAME:-ScreamingRabbit build}" -c user.email="${GIT_AUTHOR_EMAIL:-noreply@users.noreply.github.com}" \
    commit --quiet -m "Deploy build of $SOURCE_SHA"
git -C "$WORK" push --quiet origin "$BRANCH"
echo "Yayınlandı: $BRANCH ($SOURCE_SHA)"

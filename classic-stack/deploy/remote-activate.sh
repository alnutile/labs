#!/usr/bin/env bash
set -euo pipefail

app_root="${APP_ROOT:-/home/deploy/apps/classic-stack}"
release_id="${1:?release id is required}"
release_path="$app_root/releases/$release_id"

test -d "$release_path"

# Optional application hook. It must exit non-zero when the candidate is not healthy.
if [[ -x "$release_path/bin/health-check" ]]; then
  "$release_path/bin/health-check"
fi

ln -sfn "$release_path" "$app_root/current.next"
mv -Tf "$app_root/current.next" "$app_root/current"

# Retain two releases for rollback. Shared data is outside these directories.
find "$app_root/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
  | sort -nr | tail -n +3 | cut -d' ' -f2- | xargs -r rm -rf

echo "Activated $release_id"

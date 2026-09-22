#!/usr/bin/env bash
set -euo pipefail

KUBECTL="${KUBECTL:-kubectl}"
NAMESPACE=database
CLUSTER=lab-postgres

old_primary="$("$KUBECTL" get "cluster/$CLUSTER" -n "$NAMESPACE" -o jsonpath='{.status.currentPrimary}')"
if [[ -z "$old_primary" ]]; then
  echo 'CloudNativePG did not report a primary.' >&2
  exit 1
fi

echo "Deleting current primary Pod: $old_primary"
"$KUBECTL" delete pod "$old_primary" -n "$NAMESPACE" --wait=false

new_primary=''
for _ in $(seq 1 120); do
  new_primary="$("$KUBECTL" get "cluster/$CLUSTER" -n "$NAMESPACE" -o jsonpath='{.status.currentPrimary}' 2>/dev/null || true)"
  if [[ -n "$new_primary" && "$new_primary" != "$old_primary" ]]; then
    break
  fi
  sleep 2
done

if [[ -z "$new_primary" || "$new_primary" == "$old_primary" ]]; then
  echo 'A new primary was not promoted within four minutes.' >&2
  exit 1
fi

"$KUBECTL" wait --for=condition=Ready "cluster/$CLUSTER" -n "$NAMESPACE" --timeout=10m
"$KUBECTL" exec -n "$NAMESPACE" "$new_primary" -- psql -U postgres -d homelab -P pager=off -c 'TABLE lab_probe;'
echo "Failover succeeded: $old_primary -> $new_primary"

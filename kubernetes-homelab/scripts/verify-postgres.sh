#!/usr/bin/env bash
set -euo pipefail

KUBECTL="${KUBECTL:-kubectl}"
NAMESPACE=database
CLUSTER=lab-postgres
export K3S_CONFIG_FILE="${K3S_CONFIG_FILE:-/dev/null}"
export KUBECONFIG="${KUBECONFIG:-$HOME/.kube/config}"

"$KUBECTL" wait --for=condition=Ready "cluster/$CLUSTER" -n "$NAMESPACE" --timeout=5m
primary="$("$KUBECTL" get "cluster/$CLUSTER" -n "$NAMESPACE" -o jsonpath='{.status.currentPrimary}')"
if [[ -z "$primary" ]]; then
  echo 'CloudNativePG did not report a primary.' >&2
  exit 1
fi

"$KUBECTL" exec -n "$NAMESPACE" "$primary" -- psql -U postgres -d homelab -v ON_ERROR_STOP=1 \
  -c 'CREATE TABLE IF NOT EXISTS lab_probe (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, checked_at timestamptz NOT NULL DEFAULT now());' \
  -c 'INSERT INTO lab_probe DEFAULT VALUES;' \
  -c 'TABLE lab_probe;'

"$KUBECTL" exec -n "$NAMESPACE" "$primary" -- psql -U postgres -d postgres -P pager=off \
  -c 'SELECT application_name, state, sync_state FROM pg_stat_replication ORDER BY application_name;'

"$KUBECTL" get "cluster/$CLUSTER" -n "$NAMESPACE"
"$KUBECTL" get pods,pvc -n "$NAMESPACE" -o wide

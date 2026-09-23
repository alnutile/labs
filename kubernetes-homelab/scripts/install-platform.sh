#!/usr/bin/env bash
set -euo pipefail

CNPG_VERSION=1.30.0
CNPG_SHA256=f8bede43fe4ee0d478c2355b204a36876b2ae4faac60f2a9452280b293da3b88
CNPG_URL="https://github.com/cloudnative-pg/cloudnative-pg/releases/download/v${CNPG_VERSION}/cnpg-${CNPG_VERSION}.yaml"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LAB_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
KUBECTL="${KUBECTL:-kubectl}"
export K3S_CONFIG_FILE="${K3S_CONFIG_FILE:-/dev/null}"
export KUBECONFIG="${KUBECONFIG:-$HOME/.kube/config}"

command -v "$KUBECTL" >/dev/null 2>&1 || { echo "$KUBECTL is required." >&2; exit 1; }
"$KUBECTL" cluster-info >/dev/null

operator_manifest="$(mktemp /tmp/cnpg-operator.XXXXXX.yaml)"
trap 'rm -f "$operator_manifest"' EXIT
curl -fsSL "$CNPG_URL" -o "$operator_manifest"
printf '%s  %s\n' "$CNPG_SHA256" "$operator_manifest" | sha256sum --check --status

"$KUBECTL" apply --server-side -f "$operator_manifest"
"$KUBECTL" rollout status deployment/cnpg-controller-manager -n cnpg-system --timeout=5m
"$KUBECTL" apply -k "$LAB_DIR/manifests/database"
"$KUBECTL" wait --for=condition=Ready cluster/lab-postgres -n database --timeout=10m
"$KUBECTL" get cluster,pods,services,pvc -n database -o wide

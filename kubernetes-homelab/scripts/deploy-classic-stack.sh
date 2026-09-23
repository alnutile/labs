#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LAB_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
KUBECTL="${KUBECTL:-kubectl}"
NAMESPACE=classic-stack
SECRET=classic-stack-secrets
export K3S_CONFIG_FILE="${K3S_CONFIG_FILE:-/dev/null}"
export KUBECONFIG="${KUBECONFIG:-$HOME/.kube/config}"

for command in "$KUBECTL" jq openssl; do
  command -v "$command" >/dev/null 2>&1 || { echo "$command is required." >&2; exit 1; }
done
"$KUBECTL" cluster-info >/dev/null

"$KUBECTL" apply -f "$LAB_DIR/manifests/classic-stack/namespace.yaml"

existing_value() {
  local key="$1"
  local secret_json
  if ! secret_json="$("$KUBECTL" get secret "$SECRET" -n "$NAMESPACE" -o json 2>/dev/null)"; then
    return 0
  fi
  jq -r --arg key "$key" '.data[$key] // "" | @base64d' <<< "$secret_json"
}

app_key="$(existing_value APP_KEY)"
broker_token="$(existing_value AGENT_BROKER_TOKEN)"
model_api_key="${AGENT_API_KEY:-${OPENAI_API_KEY:-$(existing_value AGENT_API_KEY)}}"
[[ "$app_key" == base64:* ]] || app_key="base64:$(openssl rand -base64 32)"
(( ${#broker_token} >= 32 )) || broker_token="$(openssl rand -hex 32)"

jq -n \
  --arg appKey "$app_key" \
  --arg brokerToken "$broker_token" \
  --arg modelApiKey "$model_api_key" \
  '{apiVersion:"v1",kind:"Secret",metadata:{name:"classic-stack-secrets",namespace:"classic-stack"},type:"Opaque",stringData:{APP_KEY:$appKey,AGENT_BROKER_TOKEN:$brokerToken,AGENT_API_KEY:$modelApiKey}}' \
  | "$KUBECTL" apply -f -

for manifest in config.yaml storage.yaml postgres.yaml redis.yaml agent-rbac.yaml; do
  "$KUBECTL" apply -f "$LAB_DIR/manifests/classic-stack/$manifest"
done

"$KUBECTL" wait --for=condition=Ready cluster/classic-postgres -n "$NAMESPACE" --timeout=10m
"$KUBECTL" rollout status deployment/redis -n "$NAMESPACE" --timeout=5m
"$KUBECTL" delete job/classic-stack-migrate -n "$NAMESPACE" --ignore-not-found --wait=true
"$KUBECTL" apply -k "$LAB_DIR/manifests/classic-stack"
"$KUBECTL" wait --for=condition=Complete job/classic-stack-migrate -n "$NAMESPACE" --timeout=10m

for deployment in agent-proxy agent-broker classic-stack classic-stack-horizon classic-stack-scheduler; do
  "$KUBECTL" rollout status "deployment/$deployment" -n "$NAMESPACE" --timeout=10m
done

"$SCRIPT_DIR/verify-classic-stack.sh"
"$KUBECTL" get cluster,pods,services,pvc -n "$NAMESPACE" -o wide

if [[ -z "$model_api_key" ]]; then
  echo 'The app and Kubernetes sandbox are ready. Add AGENT_API_KEY to the Secret before running a model-backed task.'
else
  echo 'The app, Kubernetes sandbox, and model configuration are ready.'
fi
echo 'Open the app with: kubectl -n classic-stack port-forward service/classic-stack 8089:80'

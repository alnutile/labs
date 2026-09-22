#!/usr/bin/env bash
set -euo pipefail

SSH_HOST="${SSH_HOST:-omarch}"
TARGET="${KUBECONFIG_TARGET:-$HOME/.kube/omarchy}"

mkdir -p "$(dirname "$TARGET")"
umask 077
temporary="$(mktemp "${TMPDIR:-/tmp}/omarchy-kubeconfig.XXXXXX")"
trap 'rm -f "$temporary"' EXIT
ssh -o BatchMode=yes "$SSH_HOST" 'cat ~/.kube/config' > "$temporary"
install -m 0600 "$temporary" "$TARGET"

echo "Kubeconfig saved to $TARGET"
echo "Run: export KUBECONFIG=$TARGET"

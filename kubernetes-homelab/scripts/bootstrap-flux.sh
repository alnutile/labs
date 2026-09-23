#!/usr/bin/env bash
set -euo pipefail

export KUBECONFIG="${KUBECONFIG:-$HOME/.kube/omarchy}"

for command in flux gh kubectl; do
  command -v "$command" >/dev/null 2>&1 || { echo "$command is required." >&2; exit 1; }
done

kubectl cluster-info >/dev/null
flux check --pre
gh auth status >/dev/null

export GITHUB_TOKEN="$(gh auth token)"
trap 'unset GITHUB_TOKEN' EXIT

flux bootstrap github \
  --owner=alnutile \
  --repository=labs \
  --branch=kubes \
  --path=kubernetes-homelab/clusters/omarchy \
  --personal \
  --private=false \
  --read-write-key \
  --components-extra=image-reflector-controller,image-automation-controller \
  --author-name=Flux \
  --author-email=flux@users.noreply.github.com

flux check
flux get all

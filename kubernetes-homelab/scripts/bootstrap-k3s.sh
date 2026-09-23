#!/usr/bin/env bash
set -euo pipefail

K3S_VERSION="${K3S_VERSION:-v1.36.4+k3s1}"
NODE_NAME="${NODE_NAME:-omarchy}"
LAN_CIDR="${LAN_CIDR:-192.168.0.0/24}"
CONFIG_PATH=/etc/rancher/k3s/config.yaml
CONFIG_MARKER='# Managed by labs/kubernetes-homelab/scripts/bootstrap-k3s.sh'

if (( EUID != 0 )); then
  echo 'Run this script with sudo.' >&2
  exit 1
fi

operator_user="${SUDO_USER:-}"
if [[ -z "$operator_user" || "$operator_user" == root ]]; then
  echo 'Run this script from the normal operator account using sudo.' >&2
  exit 1
fi

operator_home="$(getent passwd "$operator_user" | cut -d: -f6)"
operator_group="$(id -gn "$operator_user")"
node_ip="${NODE_IP:-$(ip -4 route get 1.1.1.1 | awk '{ for (field = 1; field <= NF; field++) if ($field == "src") { print $(field + 1); exit } }')}"
tailscale_ip="$(ip -4 -brief address show tailscale0 2>/dev/null | awk '{ split($3, address, "/"); print address[1] }')"

if [[ ! "$node_ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo 'Could not determine the node IPv4 address. Set NODE_IP and rerun.' >&2
  exit 1
fi

if [[ -f "$CONFIG_PATH" ]] && ! grep -Fxq "$CONFIG_MARKER" "$CONFIG_PATH"; then
  echo "$CONFIG_PATH already exists and is not managed by this lab; refusing to overwrite it." >&2
  exit 1
fi

install -d -m 0755 /etc/rancher/k3s
{
  printf '%s\n' "$CONFIG_MARKER"
  printf 'node-name: %s\n' "$NODE_NAME"
  printf 'node-ip: %s\n' "$node_ip"
  printf 'secrets-encryption: true\n'
  printf 'write-kubeconfig-mode: "0600"\n'
  printf 'disable:\n  - traefik\n  - servicelb\n'
  printf 'tls-san:\n  - %s\n' "$node_ip"
  if [[ -n "$tailscale_ip" ]]; then
    printf '  - %s\n' "$tailscale_ip"
  fi
} > "$CONFIG_PATH"
chmod 0600 "$CONFIG_PATH"

if systemctl is-active --quiet ufw; then
  ufw allow from "$LAN_CIDR" to any port 6443 proto tcp
  ufw allow from 10.42.0.0/16
  ufw allow from 10.43.0.0/16
  if ip link show tailscale0 >/dev/null 2>&1; then
    ufw allow in on tailscale0 to any port 6443 proto tcp
  fi
fi

installer="$(mktemp /tmp/install-k3s.XXXXXX)"
trap 'rm -f "$installer"' EXIT
curl -fsSL https://get.k3s.io -o "$installer"
INSTALL_K3S_VERSION="$K3S_VERSION" sh "$installer"

install -d -m 0700 -o "$operator_user" -g "$operator_group" "$operator_home/.kube"
sed "s#https://127.0.0.1:6443#https://${node_ip}:6443#" /etc/rancher/k3s/k3s.yaml > "$operator_home/.kube/config"
chown "$operator_user:$operator_group" "$operator_home/.kube/config"
chmod 0600 "$operator_home/.kube/config"

for _ in $(seq 1 150); do
  if KUBECONFIG="$operator_home/.kube/config" k3s kubectl get "node/$NODE_NAME" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
KUBECONFIG="$operator_home/.kube/config" k3s kubectl wait --for=condition=Ready "node/$NODE_NAME" --timeout=5m
KUBECONFIG="$operator_home/.kube/config" k3s kubectl get nodes -o wide

echo "K3s $K3S_VERSION is ready. Continue as $operator_user with ./scripts/install-platform.sh"

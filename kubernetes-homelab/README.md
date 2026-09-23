# Omarchy Kubernetes homelab

This lab turns the Omarchy Mac mini into a persistent Kubernetes learning environment and a public portfolio project. The first milestone runs K3s and a three-instance PostgreSQL cluster managed by CloudNativePG.

```text
Mac workstation ──SSH/kubectl──> Omarchy Mac mini (192.168.0.180)
                                  └── K3s 1.36
                                      ├── CloudNativePG operator 1.30
                                      └── PostgreSQL 18.4
                                          ├── primary
                                          ├── replica
                                          └── replica
```

All three database instances initially share one physical machine and one NVMe disk. This setup demonstrates replication, service routing, reconciliation, and automatic failover, but it does not survive loss of the host or disk. Real infrastructure high availability begins when instances and storage span independent failure domains.

## First installation

The host bootstrap pins K3s to `v1.36.4+k3s1`, enables Kubernetes secret encryption, configures the active UFW firewall, and disables bundled Traefik and ServiceLB until ingress and load balancing are designed explicitly.

Run these commands in a terminal on the Omarchy machine:

```bash
git clone --branch kubes https://github.com/alnutile/labs.git ~/labs
cd ~/labs/kubernetes-homelab
sudo ./scripts/bootstrap-k3s.sh
./scripts/install-platform.sh
./scripts/verify-postgres.sh
```

The only interactive administrative step is `sudo ./scripts/bootstrap-k3s.sh`. The script copies a cluster-admin kubeconfig to `~/.kube/config` without printing its credentials.

To administer the cluster from the Mac workstation, run:

```bash
cd /Users/alfrednutile/TerraForm/labs/kubernetes-homelab
./scripts/fetch-kubeconfig.sh
export KUBECONFIG="$HOME/.kube/omarchy"
kubectl get nodes -o wide
```

Treat that kubeconfig as a secret. It contains cluster-admin client credentials and is excluded from Git.

## GitOps delivery

Flux watches the `kubes` branch and reconciles the Omarchy cluster. Bootstrap it once from the Mac workstation after K3s and CloudNativePG are installed:

```bash
export KUBECONFIG="$HOME/.kube/omarchy"
./scripts/bootstrap-flux.sh
```

Application delivery then follows this path:

1. Push application or agent changes to `kubes`.
2. GitHub Actions publishes all four images with one immutable `kubes-<timestamp>-<commit>` tag.
3. Flux image automation records that tag in the Kubernetes manifests on `kubes`.
4. Flux applies the Git commit to Omarchy and waits for the migration and deployments to become healthy.

Changes made only to Kubernetes manifests skip the image build and are reconciled directly. Check the current state with:

```bash
flux get all
flux get images all
kubectl get pods -n classic-stack
```

## PostgreSQL access

CloudNativePG creates separate read/write and read-only services. Keep PostgreSQL private and use a port forward while learning:

```bash
kubectl -n database port-forward service/lab-postgres-rw 5432:5432
```

The generated application credentials are stored in the `lab-postgres-app` Secret. Inspect the Secret's keys without printing their values:

```bash
kubectl -n database describe secret lab-postgres-app
```

## Certification practice

This first workload covers useful parts of the current CKA domains:

- Cluster architecture: install and operate a Kubernetes distribution, a CRD, and an operator.
- Workloads and scheduling: inspect resources, affinity, requests, limits, and operator reconciliation.
- Services and networking: compare the `-rw`, `-ro`, and `-r` ClusterIP services and trace their endpoints.
- Storage: inspect the `local-path` StorageClass, PVCs, PVs, access modes, and reclaim policies.
- Troubleshooting: use events, logs, status conditions, and the failover exercise.

After `verify-postgres.sh` succeeds, run the controlled failover drill:

```bash
./scripts/failover-postgres.sh
```

The script deletes the current primary Pod, waits for CloudNativePG to promote a replica, and confirms that the test data is still readable.

## Next milestones

1. Add object-storage backups and perform a documented restore. Backups on the same disk do not count as disaster recovery.
2. Install Prometheus, Grafana, and alerting, then build PostgreSQL and node dashboards.
3. Add ingress, certificates, internal DNS, and a deliberate load-balancer design.
4. Add NetworkPolicies and a restricted workload namespace.
5. Add two more physical nodes, then move database replicas into separate failure domains.

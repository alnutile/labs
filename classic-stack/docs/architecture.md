# Classic stack architecture

```text
Browser → Cloudflare DNS (optional proxy) → Elastic IP
    → default VPC / public subnet / web security group → EC2
        → Caddy :80/:443 (automatic origin HTTPS)
            → active Laravel/Apache container (no published port)
                → PostgreSQL
                → Redis → Horizon worker
                → private report volume
        → scheduler (Horizon metrics every five minutes)
```

PostgreSQL, Redis, and file data stay on this server. Named Docker volumes outlive releases but reside on the EC2 root disk. The proxy certificate volume persists too. None of the database, Redis, Caddy admin, or application-container ports are published publicly.

The deploy user's public keys are installed by cloud-init. Operator SSH ingress is restricted to configured CIDRs. An optional second security group allows a GitHub runner's temporary `/32`; the OIDC role is scoped to that security group and the repository's production environment subject.

The default VPC/subnets/internet gateway already exist in AWS and are read by Terraform. The managed resources are EC2, its encrypted root volume, security groups, Elastic IP/association, and optional GitHub IAM/OIDC resources.

## Releases

Each release is an immutable Docker image tagged with the workflow run ID and commit SHA. A release-specific Compose project gives its app a unique DNS alias on the shared Docker network. Persistent services use a separate stable Compose project.

Migrations run before activation. The candidate health endpoint checks PostgreSQL and Redis. Old consumers stop gracefully, new Horizon/scheduler processes start, and Caddy validates then reloads its new upstream configuration. The previous web process drains before stopping. On activation failure, the script restores the previous proxy configuration and workers; database migrations remain applied.

`current` and `previous` symlinks record release locations. Rollback reruns activation for a retained release/image. It cannot be accomplished by just changing a symlink.

## Failure boundary

One server means one failure domain. This design provides a controlled deployment transition, not high availability. Database/schema changes must support overlapping app versions. Host failure/reboot, exhausted disk, broken infrastructure updates, and lost volumes require recovery. Backups must leave the host and have a tested restore procedure.

The complete runbook and recording commands are in [the stack README](../README.md).

# classic-stack architecture

## Video goal

Show a predictable, understandable deployment for a small application: one AWS EC2 host, one public IP, one monthly infrastructure bill, and one GitHub Actions workflow that can deploy staging or production.

This stack intentionally keeps Postgres and file storage on the host. That makes the data path visible and the cost easy to reason about. It also makes backups, disk growth, security updates, and recovery the operator's responsibility.

## Request path

```text
Browser
  ↓ HTTPS
Cloudflare DNS/proxy
  ↓ 80/443
Nginx or Caddy on the EC2 host
  ├── current frontend release
  ├── application API/WebSocket process
  └── internal Docker services
        ├── PostgreSQL (private bind)
        ├── object/file storage (private bind)
        └── worker/webhook services
```

SSH is restricted to the operator's IP range. The application never needs a public database port. Cloudflare points to the Elastic IP; the origin firewall permits only web traffic and the approved administration path.

## Release layout

```text
/home/deploy/apps/classic-stack/
├── current -> releases/20260914T120000Z-abc1234
├── releases/
│   ├── 20260914T120000Z-abc1234/
│   └── 20260913T180000Z-def5678/
└── shared/
    ├── env/production.env
    ├── env/staging.env
    ├── storage/
    ├── postgres/
    └── logs/
```

The release is built before activation. Health checks and migrations run against the candidate. The final activation is a symlink replacement, which is atomic for readers. Rollback points `current` at the previous release. Persistent directories are never part of a release and are never deleted by cleanup.

For a stateful application, this is near-zero-downtime rather than magic zero downtime: incompatible database migrations, process restarts, WebSocket reconnects, or a single-host failure still need an explicit strategy. A true no-downtime exercise can add two application containers and switch the reverse proxy only after the new one is healthy.

## Why not begin with a VM image or Kubernetes?

The EC2 host makes the operating system, firewall, storage, process supervision, and deployment mechanics visible. Managed containers and databases belong in `cloud-stack` once the application contract is stable. Kubernetes would add a platform lesson before the application deployment lesson.

## Required safety controls

- Encrypted EBS volume and automatic snapshots or an off-host backup target.
- No AWS keys on the instance; use an instance role for AWS APIs.
- No database or storage ports in the security group.
- Separate staging and production environment files and domains.
- GitHub Actions deploy key limited to this host and repository.
- Pin action versions and verify the host key with `known_hosts`.
- Keep at least two releases and test rollback during the video.


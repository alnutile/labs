# shared-stack

A single server hosts multiple applications and environments using a consistent directory and reverse-proxy layout.

## Intended layout

```text
/home/USER/app_name/
├── app-one/
│   ├── production/
│   └── staging/
├── app-two/
│   ├── production/
│   └── staging/
└── shared/
    ├── proxy/
    ├── backups/
    └── scripts/
```

Each environment gets its own project name, ports, volumes, secrets, logs, domain, and deployment lifecycle. A reverse proxy routes hostnames such as `app-one.example.com` and `staging.app-one.example.com` to the correct isolated service.

## Isolation rules

- Use one Unix user or Docker Compose project per application.
- Never share application databases or writable volumes by accident.
- Keep production and staging secrets separate.
- Bind internal services to private interfaces; expose only the reverse proxy.
- Give each app a health endpoint and resource limits.
- Back up data independently of the application directory.

## Planned lessons

1. Provision one host and a predictable `/home/USER/app_name` layout.
2. Deploy two apps with production and staging Compose projects.
3. Route multiple domains through one proxy.
4. Add per-app CI/CD, rollback, logs, backups, and quotas.
5. Compare operational complexity with `classic-stack` and the managed `cloud-stack`.


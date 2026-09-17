# Classic Stack Laravel app

See [the stack runbook](../README.md) for Docker startup, tests, Terraform, Cloudflare, GitHub Actions, and deployment.

Fortify handles authentication; Blade renders the UI. `DemoRunController` dispatches `WriteDemoReport` onto Redis. Horizon consumes it, writes a private file, and records completion in PostgreSQL. Production Horizon access uses `HORIZON_ALLOWED_EMAILS`.

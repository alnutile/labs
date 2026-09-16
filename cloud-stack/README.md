# Building a complete application in the cloud using Terraform

This is a learning lab for moving a real application from a managed Supabase/Railway setup toward cloud-native deployments managed with Terraform.

The reference application is [`alnutile/supabse-vibecoding-starter`](https://github.com/alnutile/supabse-vibecoding-starter). It is a Vite React + TypeScript app with authentication, per-user Postgres data protected by RLS, private file storage, realtime updates, Edge Functions, GitHub Actions, and Railway hosting.

## Learning path

1. Establish the application contract and security boundaries.
2. Build the smallest useful AWS deployment with Terraform.
3. Rebuild the same contract on Azure.
4. Rebuild it on Google Cloud.
5. Add CI/CD with GitHub Actions and short-lived cloud identity.
6. Add an external managed-agent integration inside the private network.

Each cloud directory is an independent Terraform root. Nothing in this folder is applied until its provider, region, credentials, state backend, and cost limits have been reviewed.

## Initial architecture decision

The first deployment should use managed serverless/container services instead of an EC2-style VM. A VM is useful as a later comparison exercise, but it adds patching, scaling, load balancing, and deployment work before the application architecture is understood.

The target shape is:

```text
GitHub Actions → Terraform → cloud infrastructure
React static assets → cloud CDN/static hosting
API and webhooks → managed container or functions
Postgres + RLS → managed PostgreSQL
Files → private object storage
Realtime → managed WebSocket/realtime service
Secrets → cloud secret manager
Agent integration → private service boundary
```

See [`docs/architecture.md`](docs/architecture.md) for the service mapping and trade-offs.

## Directory layout

```text
app/                 Application deployment contract and later app code
docs/                Architecture, decisions, and learning notes
terraform/aws/       AWS root module
terraform/azure/     Azure root module
terraform/gcp/       Google Cloud root module
terraform/modules/   Shared module design, added only when repetition is proven
```

The first implementation task is AWS foundation networking and a minimal application slice. Azure and GCP stay as documented plans until the AWS contract is working.


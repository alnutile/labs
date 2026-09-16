# Architecture and service map

## Application contract

The cloud implementations should preserve these boundaries:

- React clients authenticate without receiving privileged credentials.
- Every user-owned database row is protected by a default-deny policy and an owner check.
- Private files are addressed by user-scoped keys and short-lived signed URLs.
- Realtime events are filtered to the authorized user or tenant.
- Webhooks are authenticated, idempotent, logged, and retried safely.
- Background work is asynchronous and does not block the request path.
- Secrets are injected at runtime from a secret manager.
- CI/CD can plan on pull requests and apply only after an explicit protected-branch decision.

## Provider mapping

| Capability | AWS | Azure | Google Cloud |
|---|---|---|---|
| React hosting | S3 + CloudFront | Static Web Apps or Storage + CDN | Cloud Storage + Cloud CDN or Firebase Hosting |
| API/functions | Lambda + API Gateway, or App Runner | Container Apps or Functions | Cloud Run |
| PostgreSQL | Aurora PostgreSQL Serverless v2 or RDS PostgreSQL | Azure Database for PostgreSQL Flexible Server | Cloud SQL for PostgreSQL |
| Files | S3 private bucket | Blob Storage private container | Cloud Storage private bucket |
| Events/jobs | SQS + EventBridge | Service Bus + Event Grid | Pub/Sub + Eventarc |
| Authentication | Cognito | Entra External ID | Identity Platform |
| WebSockets | API Gateway WebSocket or AppSync | Azure Web PubSub | Cloud Run WebSockets or a managed realtime service |
| Secrets | Secrets Manager | Key Vault | Secret Manager |
| Network boundary | VPC, private subnets, security groups | VNet, private endpoints, NSGs | VPC, private services access, firewall rules |

## Phased implementation

### Phase 1: AWS foundation

Create a VPC, private and public subnets, security boundaries, logging, and a state backend. Add the smallest deployable API and managed PostgreSQL slice. Keep the database private.

### Phase 2: AWS application services

Add static React hosting, private object storage, webhook ingress, an asynchronous queue, realtime delivery, authentication, and observability. Use a managed container or functions before comparing the same workload on EC2.

### Phase 3: CI/CD

GitHub Actions runs formatting, validation, tests, security checks, and Terraform plan on pull requests. A protected branch can apply using OIDC or another short-lived identity mechanism. Long-lived cloud keys should not be stored in GitHub or Terraform variables.

### Phase 4: Azure and GCP

Implement the same application contract in each provider directory. Provider-specific modules are acceptable; application behavior and security requirements should remain consistent.

### Phase 5: Managed-agent integration

Place the agent-facing service behind a private network boundary. Expose only a narrow, authenticated API and asynchronous job interface. Keep agent credentials and tool permissions separate from end-user credentials.

## Decisions to revisit

- Aurora/RDS versus a serverless database based on connection count and cost.
- Functions versus a container for long-lived WebSocket connections.
- Managed realtime service versus application-owned WebSocket infrastructure.
- Single-cloud deployment versus an active/passive multi-cloud exercise.
- HCP Terraform remote state versus a cloud-native backend per provider.


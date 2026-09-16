# Stack comparison

| Stack | Main lesson | Strength | Main limitation |
|---|---|---|---|
| `cloud-stack` | Managed cloud services and Terraform boundaries | Separate scaling and managed operations | More services and cloud-specific concepts |
| `classic-stack` | One-server deployment fundamentals | Simple mental model and low initial cost | One failure domain; manual operations grow quickly |
| `shared-stack` | Multi-tenant server operations | Several apps on one host with repeatable conventions | Isolation, capacity, and noisy-neighbor risks |

The same application contract should be tested on all three. The implementation changes, while authentication, authorization, webhook behavior, data ownership, health checks, and deployment verification remain consistent.


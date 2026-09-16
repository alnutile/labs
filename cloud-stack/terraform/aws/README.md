# AWS lab

This root module will be the first implementation. It is intentionally empty until the AWS foundation design is reviewed.

Planned first steps:

1. Add the AWS provider and an explicit `us-west-2` variable.
2. Add remote state with locking and encryption.
3. Add a small VPC with private database subnets.
4. Add one minimal API runtime and managed PostgreSQL.
5. Add outputs that expose endpoints and identifiers without exposing secrets.

Run locally only after configuration exists:

```bash
terraform fmt -check
terraform init
terraform validate
terraform plan
```


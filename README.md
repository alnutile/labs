# Infrastructure learning labs

This repository is the working area for the **Building a Complete Application in the Cloud with Terraform** video series. We use one application contract and implement it at several infrastructure levels so the trade-offs stay visible.

The progression is:

```text
single predictable server
        ↓
shared server for many apps/environments
        ↓
managed cloud services
        ↓
Kubernetes cluster
```

These labs compare three deployment levels for the same application:

- [`cloud-stack`](cloud-stack/) — managed services across AWS, Azure, and Google Cloud.
- [`classic-stack`](classic-stack/) — one EC2-style server running the complete application.
- [`shared-stack`](shared-stack/) — one server hosting multiple applications and environments under `/home/USER/app_name`.

DigitalOcean and AWS are both valid targets for the first two server-focused episodes. The current Terraform implementation starts with AWS EC2; the deployment layout and GitHub Actions release process are deliberately portable.

All stacks are learning exercises. Review Terraform plans before applying them, and keep credentials in local profiles, CI secret stores, or cloud identity systems rather than Git.

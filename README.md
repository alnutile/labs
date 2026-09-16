# Infrastructure learning labs

These labs compare three deployment levels for the same application:

- [`cloud-stack`](cloud-stack/) — managed services across AWS, Azure, and Google Cloud.
- [`classic-stack`](classic-stack/) — one EC2-style server running the complete application.
- [`shared-stack`](shared-stack/) — one server hosting multiple applications and environments under `/home/USER/app_name`.

All stacks are learning exercises. Review Terraform plans before applying them, and keep credentials in local profiles, CI secret stores, or cloud identity systems rather than Git.

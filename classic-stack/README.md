# classic-stack

This lab builds and deploys a small application on one predictable AWS server. It is intentionally a teaching stack: one EC2 host, one Elastic IP, one encrypted disk, local Docker services, and GitHub Actions deploying releases over SSH.

The final target is:

```text
Cloudflare DNS/proxy
        ↓
Elastic IP → one Ubuntu EC2 host
             ├── reverse proxy and HTTPS origin
             ├── React application and API
             ├── PostgreSQL
             ├── local file storage
             ├── webhook/worker process
             └── WebSocket process
```

The current Terraform milestone provisions the host, security group, encrypted disk, Elastic IP, Docker, and application directory layout. The application containers, database schema, Cloudflare record, and GitHub repository are the next milestones.

## Before starting

You need:

- AWS CLI authenticated to the intended training account.
- Terraform 1.6 or newer.
- An SSH key pair. The public key must be installed for the `deploy` user before SSH deployment is enabled.
- A GitHub repository containing the application.
- A domain managed by Cloudflare for the DNS lesson.

Confirm the AWS account before creating anything:

```bash
aws sts get-caller-identity
```

The commands below assume the `sundance` AWS profile. If your active profile is already correct, omit `--profile sundance`.

## Step 1: Configure the Terraform variables

Change into the Terraform root:

```bash
cd /Users/alfrednutile/TerraForm/labs/classic-stack/terraform
```

Create a local variable file from the example:

```bash
cp terraform.tfvars.example terraform.tfvars
```

Edit `terraform.tfvars` and replace the SSH CIDR with your current public IP in `/32` form. Do not use `0.0.0.0/0` for SSH.

```hcl
aws_region        = "us-west-2"
instance_type     = "t3.small"
admin_cidr_blocks = ["YOUR.PUBLIC.IP.ADDRESS/32"]
```

`terraform.tfvars` is ignored by Git because it can contain environment-specific values.

## Step 2: Initialize and review the plan

```bash
terraform init
terraform fmt -check
terraform validate
terraform plan -out classic-stack.tfplan
```

The plan should contain one instance, one security group, one Elastic IP and its association. It should not contain access keys, a public database port, or resources in an unintended account or region.

## Step 3: Create the server

Apply the saved plan only after reviewing it:

```bash
terraform apply classic-stack.tfplan
```

Capture the connection details without hard-coding them into source control:

```bash
terraform output
terraform output -raw elastic_ip
terraform output -raw ssh_command
```

The default instance type is `t3.small`, the region is `us-west-2`, and the root disk is encrypted gp3. The Elastic IP is the address to use as the Cloudflare origin.

## Step 4: Verify the host

Cloud-init installs Docker and creates this layout:

```text
/home/deploy/apps/classic-stack/
├── current -> releases/<release-id>
├── releases/
└── shared/
    ├── env/
    ├── storage/
    ├── postgres/
    ├── logs/
    └── bin/
```

Connect after cloud-init completes:

```bash
ssh deploy@$(terraform output -raw elastic_ip)
```

Then verify the base system:

```bash
cloud-init status --wait
docker --version
ls -la /home/deploy/apps/classic-stack
```

If SSH fails, check the CIDR in `terraform.tfvars`, the correct key in `~/.ssh/authorized_keys`, and the instance status in the AWS console. Do not open SSH to the entire internet as the first fix.

## Step 5: Add the application services

The application will be added as a Docker Compose project. The Compose file should keep Postgres and file storage private to the Docker network and persist data under `shared/`:

```text
shared/postgres/  → PostgreSQL data
shared/storage/   → user-uploaded files
shared/env/       → production and staging environment files
```

The public reverse proxy exposes only the web application and webhook endpoint. Database credentials belong in the server’s protected environment files or a secret manager; they do not belong in GitHub source or Terraform state.

## Step 6: Configure Cloudflare

Create an A record for the application hostname pointing to:

```bash
terraform output -raw elastic_ip
```

Enable the Cloudflare proxy after the origin responds on HTTP. Configure HTTPS at the origin before enforcing “Full (strict)” TLS. The Cloudflare lesson comes after the application health endpoint works directly on the server.

## Step 7: Configure GitHub Actions

The workflow in `.github/workflows/deploy.yml` is intended to be copied into the application repository. Create separate GitHub Environments named `staging` and `production`, then add these environment secrets:

- `CLASSIC_STACK_HOST` — the Elastic IP or hostname.
- `CLASSIC_STACK_DEPLOY_KEY` — a dedicated private SSH key whose public key is authorized for `deploy`.
- `CLASSIC_STACK_KNOWN_HOSTS` — the pinned output from `ssh-keyscan` reviewed by the operator.

The workflow builds an artifact on GitHub-hosted runners, uploads it to `releases/<release-id>`, runs a health hook, and atomically switches the `current` symlink. It does not send AWS credentials to the server.

## Step 8: Understand the release and rollback flow

```text
push to staging/main
  → tests and build on GitHub
  → upload a new release directory
  → install dependencies and run health checks
  → switch current symlink
  → retain the previous two releases
```

To roll back, point `current` at the previous release after confirming its identifier:

```bash
cd /home/deploy/apps/classic-stack
ls -lt releases
ln -sfn releases/<previous-release-id> current.next
mv -Tf current.next current
```

This is the established atomic-release pattern used by Envoyer and Forge. It prevents an incomplete upload from becoming live. Database migrations must remain backward-compatible if old and new application processes can overlap.

## Step 9: Destroy the lab

When the video is complete, remove the AWS resources managed by this root module:

```bash
terraform destroy
```

Review the destroy plan carefully. This removes the EC2 instance, Elastic IP, security group, and server network resources tracked by this state. Local files in the ignored Terraform state and `shared/` directories are not a backup; copy anything needed before destroying the host.

## Cost and failure boundary

The bill is easier to predict because the first version uses one small instance and one attached disk. It still has costs for compute, EBS, public IPv4/Elastic IP usage, data transfer, and any Cloudflare or domain services. The host is a single failure domain: a disk failure, bad deployment, kernel issue, or accidental deletion affects the application and its local data together. The later `cloud-stack` lab moves these boundaries to managed services.

# classic-stack

This is the first build in the **Building a Complete Application in the Cloud with Terraform** video series. The series starts with the smallest useful deployment model and keeps the application contract constant while the infrastructure becomes more capable.

## Where this episode fits

| Episode | Stack | What it teaches |
|---|---|---|
| 1 | `classic-stack` on AWS or DigitalOcean | One predictable server, local services, Cloudflare, SSH deployment, releases, and rollback |
| 2 | `shared-stack` | Several applications and staging/production environments on one server under `/home/USER/app_name` |
| 3 | `cloud-stack` | Managed databases, object storage, queues, identity, realtime, and cloud-native networking |
| 4 | Kubernetes stack | A cluster, ingress, deployments, services, persistent volumes, secrets, autoscaling, and GitOps |

The provider can change in Episode 1. AWS EC2 is the current implementation; a DigitalOcean Droplet can use the same server contract. The point is to understand the operating model before introducing more managed services.

## What runs here

Laravel 13 / PHP 8.4, Fortify login and registration, PostgreSQL 17, Redis 7.4, Horizon, and a private file volume. Apache serves Laravel inside its container; Caddy is the production HTTPS reverse proxy. No Sail, Kubernetes, frontend build, or host PHP installation is required.

The demo dispatches a report job to Redis, Horizon writes the report to storage and updates PostgreSQL, and the signed-in user can download it. The dashboard only shows that user's reports. Public registration is enabled locally and disabled in production; Horizon requires an authenticated user locally and an email allowlist in production.

The later episodes can add a React client, webhooks, and WebSockets to this application. This first lesson concentrates on login, data, queues, files, and deployment.

## 1. Run locally

Install Docker Desktop (or Docker Engine with Compose on Linux), then from the repo root:

```bash
cd classic-stack
./scripts/dev up
```

Open <http://localhost:8080>, register, and click **Queue a report**. Open **Horizon**, wait five seconds, then refresh the dashboard and download the report.

The helper contains ordinary Docker Compose commands: copy the example environment, build PHP, install Composer dependencies in Docker, generate the app key, migrate, and start the services. Its source is [`scripts/dev`](scripts/dev). Subsequent PHP edits appear through the bind mount; restart Horizon after editing job code.

```bash
./scripts/dev test                 # isolated SQLite PHPUnit tests
./scripts/dev smoke                # real Redis → Horizon → database/file check
./scripts/dev artisan migrate
./scripts/dev restart horizon
./scripts/dev logs -f horizon
./scripts/dev down                 # keeps data
```

Local PostgreSQL/Redis use named volumes with no published database ports. The source and local reports are in `app/`; production reports use a separate persistent volume. `app/.env` is ignored. To change the local web port, set `APP_PORT` and `APP_URL` there. `down -v` deletes the local database and queue volumes.

## 2. Build AWS infrastructure on camera

This remains the classic AWS model:

```text
Default VPC → existing public subnet → security groups → Ubuntu EC2
                                                        ├── Elastic IP
                                                        └── encrypted gp3 root disk
```

The default VPC supplies its subnets, routes, and internet gateway. Terraform reads them; it does not create or delete them. Accounts without a default VPC need a VPC/subnet implementation before using this root module. No NAT gateway, managed database, load balancer, or Route 53 is required.

Use an authenticated AWS CLI profile for the intended training account. Prefer temporary credentials/SSO; the application and GitHub do not need an AdministratorAccess key. The Terraform operator needs permissions to create the declared EC2/network resources and, if enabled, the IAM/OIDC resources.

```bash
export AWS_PROFILE=sundance        # replace with your profile
aws sts get-caller-identity
ssh-keygen -t ed25519 -f ~/.ssh/classic-stack-deploy -C classic-stack-deploy
cd terraform
cp terraform.tfvars.example terraform.tfvars
```

Edit the ignored `terraform.tfvars`: set your current public IP as `admin_cidr_blocks = ["YOUR.IP/32"]` and paste the **public** key from `~/.ssh/classic-stack-deploy.pub` into `ssh_public_keys`. Never put the private key in Terraform. The default is `us-west-2`, `t3.small`, and 30 GB encrypted gp3.

For the recording:

```bash
terraform init
terraform fmt -check
terraform validate
terraform plan -out classic-stack.tfplan
terraform apply classic-stack.tfplan
terraform output
```

The public key is now installed by cloud-init. Changes to bootstrap settings replace the instance; existing training instances without the key need replacement. Review the plan: replacement deletes data on the old root disk. The Elastic IP remains stable unless explicitly destroyed.

```bash
ssh -i ~/.ssh/classic-stack-deploy deploy@$(terraform output -raw elastic_ip)
# On the server:
sudo cloud-init status --wait
sudo tail -n 50 /var/log/cloud-init-output.log
docker version
docker compose version
```

Docker-group access and the deploy user's sudo access are effectively root access. Protect that SSH key accordingly. Cloud-init installs Docker from Docker's installer; the host still needs regular security updates and planned reboots.

## 3. Point Cloudflare at the Elastic IP

Use **classic-stack.dailyai.studio**, an A record pointing to `terraform output -raw elastic_ip`. Begin **DNS only**. Caddy will obtain and renew an HTTPS certificate once this hostname resolves to the host and ports 80/443 are reachable.

The optional helper needs a token scoped to **Zone / DNS / Edit** on **dailyai.studio** and the zone ID from Cloudflare. Set the token using a hidden shell prompt, not a literal command saved in shell history:

```bash
# From classic-stack; the value stays hidden while typing:
printf 'Cloudflare token: '; read -rs CLOUDFLARE_API_TOKEN; echo
export CLOUDFLARE_API_TOKEN
export CLOUDFLARE_ZONE_ID=YOUR_ZONE_ID
./scripts/cloudflare-dns "$(terraform -chdir=terraform output -raw elastic_ip)"
unset CLOUDFLARE_API_TOKEN
```

The helper only creates/updates this hostname's A record. It refuses conflicting records and preserves an existing proxy setting. After the first deployment and a successful `https://classic-stack.dailyai.studio/ready` check, Cloudflare proxying is optional. Use **Full (strict)** SSL/TLS when proxying; do not use Flexible. TLS settings may affect other domains in the zone, so review their scope. The public origin is still reachable directly: this is not a Cloudflare-only firewall configuration or private network.

## 4. Prepare production configuration

From `classic-stack`, create a protected, ignored local environment file:

```bash
cp deploy/production.env.example .env.production
chmod 600 .env.production
./scripts/dev artisan key:generate --show
openssl rand -hex 32
```

Put the generated key into `APP_KEY` and the random password into `DB_PASSWORD` in `.env.production`; set `HORIZON_ALLOWED_EMAILS` to the email you'll create. Keep the same key across deployments. It is separate from the local app key. Keep `APP_DEBUG=false`, `ALLOW_REGISTRATION=false`, and secure cookies enabled. The default hostname is already configured.

```bash
STACK_HOST=$(terraform -chdir=terraform output -raw elastic_ip)
scp -i ~/.ssh/classic-stack-deploy .env.production \
  "deploy@$STACK_HOST:/home/deploy/apps/classic-stack/shared/env/production.env"
ssh -i ~/.ssh/classic-stack-deploy "deploy@$STACK_HOST" \
  'chmod 600 /home/deploy/apps/classic-stack/shared/env/production.env'
```

The server file is used by Compose. Secrets stay outside the image and Terraform state. PostgreSQL initializes its password only on an empty volume; changing `DB_PASSWORD` later also requires updating the database role's password.

## 5. Enable GitHub Actions

The actual workflow is at the **repository root**, [`../.github/workflows/classic-stack.yml`](../.github/workflows/classic-stack.yml). Pull requests and pushes to `main` run PHPUnit against PostgreSQL, a real Redis/Horizon smoke test, formatting, and a production Docker build. Deployment is gated until setup is complete.

GitHub-hosted runners have changing IP addresses. Terraform can create a separate security group and a narrowly scoped OIDC role so a runner opens SSH for its own `/32`, deploys, then removes that rule in an `always()` cleanup step. The role can edit ingress only on that dedicated security group, but AWS does not constrain those edits to port 22; protect access to the workflow/environment. No static AWS access key is needed.

Enable this in `terraform.tfvars`:

```hcl
github_oidc_subject = "repo:alnutile@365385/labs@1370401167:environment:production"
# Reuse this if your AWS account already has a GitHub OIDC provider:
# github_oidc_provider_arn = "arn:aws:iam::ACCOUNT_ID:oidc-provider/token.actions.githubusercontent.com"
```

That subject was verified against this repository's GitHub API. Forks need their own exact subject. Check with `gh api repos/OWNER/REPO/actions/oidc/customization/sub`; older repositories can use the name-only format. Apply the reviewed Terraform plan to create the role and attach the runner security group.

Create a GitHub Environment called **production**, restricted to the `main` branch. Add:

| Kind | Name | Value |
|---|---|---|
| Secret | `CLASSIC_STACK_HOST` | Terraform Elastic IP; use the IP, not Cloudflare's proxied hostname |
| Secret | `CLASSIC_STACK_DEPLOY_KEY` | Dedicated private key matching `ssh_public_keys` |
| Secret | `CLASSIC_STACK_KNOWN_HOSTS` | Verified host-key entry for that IP |
| Variable | `CLASSIC_STACK_DEPLOY_ROLE_ARN` | Terraform output `github_deploy_role_arn` |
| Variable | `CLASSIC_STACK_RUNNER_SECURITY_GROUP_ID` | Terraform output `github_runner_security_group_id` |
| Variable | `AWS_REGION` | `us-west-2`, or your selected region |
| Variable | `CLASSIC_STACK_DEPLOY_USER` | `deploy` (optional default) |

Pin the host key: collect `ssh-keyscan -H "$STACK_HOST"`, and compare its fingerprint with `sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` via a trusted host session/EC2 console. After replacing the instance, verify and update the pinned key.

The same environment and secret setup can be done with GitHub CLI. These commands send the private deploy key to GitHub's encrypted environment secret store; never commit that key:

```bash
gh api --method PUT repos/alnutile/labs/environments/production

gh variable set CLASSIC_STACK_DEPLOY_ROLE_ARN --repo alnutile/labs --env production \
  --body "$(terraform -chdir=terraform output -raw github_deploy_role_arn)"
gh variable set CLASSIC_STACK_RUNNER_SECURITY_GROUP_ID --repo alnutile/labs --env production \
  --body "$(terraform -chdir=terraform output -raw github_runner_security_group_id)"
gh variable set AWS_REGION --repo alnutile/labs --env production --body us-west-2
gh variable set CLASSIC_STACK_DEPLOY_USER --repo alnutile/labs --env production --body deploy

gh secret set CLASSIC_STACK_HOST --repo alnutile/labs --env production \
  --body "$(terraform -chdir=terraform output -raw elastic_ip)"
gh secret set CLASSIC_STACK_DEPLOY_KEY --repo alnutile/labs --env production \
  < ~/.ssh/classic-stack-deploy
ssh-keyscan -H "$(terraform -chdir=terraform output -raw elastic_ip)" \
  | gh secret set CLASSIC_STACK_KNOWN_HOSTS --repo alnutile/labs --env production

# Keep deployment disabled for the first test run.
gh variable set CLASSIC_STACK_DEPLOY_ENABLED --repo alnutile/labs --body false
```

Push the workflow and application to `main`, confirm the test job succeeds, then enable deployment and rerun the workflow:

```bash
gh variable set CLASSIC_STACK_DEPLOY_ENABLED --repo alnutile/labs --body true
gh workflow run "Classic stack — test and deploy" --repo alnutile/labs --ref main
```

`CLASSIC_STACK_DEPLOY_ENABLED` must be a repository variable because the job-level condition is evaluated before environment variables are loaded. A killed runner can leave its temporary `/32` rule behind; inspect the runner security group after interrupted deployments and remove stale rules.

## 6. First login and demo

After the first successful deployment, SSH to the host:

```bash
export APP_ROOT=/home/deploy/apps/classic-stack
export RELEASE_ID=$(basename "$(readlink "$APP_ROOT/current")")
cd "$APP_ROOT/current"
docker compose --env-file "$APP_ROOT/shared/env/production.env" \
  -p "release-$RELEASE_ID" -f deploy/compose.release.yaml \
  exec --user www-data app php artisan stack:user YOUR_EMAIL --name='Your Name'
```

Enter a password at the hidden prompt, then sign in at <https://classic-stack.dailyai.studio>. Use the same email in `HORIZON_ALLOWED_EMAILS` for Horizon access. This lab implements login/logout and optional registration; password reset/email delivery, MFA, invitations, and organization authorization are later application work.

## 7. Understand deployment and rollback

```text
tests pass → build image → upload release → run migrations
 → start candidate app → check database + Redis → replace workers
 → gracefully reload Caddy → drain previous web container → retain previous release
```

PostgreSQL, Redis, certificates, and reports have stable volumes. The web container has no host port in production. Caddy alone exposes 80/443. The deployment script serializes deployments with `flock`, checks the candidate before changing traffic, and restores the proxy/previous workers if candidate activation fails. It does not roll back schema changes.

Releases live at `/home/deploy/apps/classic-stack/releases/<id>`. The `current` symlink records the active release; Caddy's upstream actually controls traffic. **Changing only the symlink does not deploy or roll back a container.**

To roll back application code, use the same activation procedure on the retained release:

```bash
export APP_ROOT=/home/deploy/apps/classic-stack
PREVIOUS_ID=$(basename "$(readlink "$APP_ROOT/previous")")
bash "$APP_ROOT/releases/$PREVIOUS_ID/deploy/remote-activate.sh" "$PREVIOUS_ID"
```

Migrations must be backward compatible with both versions: add a nullable column first, deploy code, backfill, and remove the old column in a later release. No automatic `migrate:rollback`. The first deployment, infrastructure updates/reboots, database failures, long requests, and incompatible schema changes are outside the no-planned-web-outage deployment pattern. Workers briefly pause while changing versions; Redis keeps pending jobs. A single EC2 host is not high availability.

Images and releases are retained deliberately. Check `docker system df` and `df -h`; after confirming current/previous IDs, remove only older release containers/images/directories. Never use indiscriminate volume pruning. Docker logs rotate, but database/Redis/report storage still needs monitoring.

## Backups and teardown

```bash
# On the host:
bash /home/deploy/apps/classic-stack/current/deploy/backup.sh /home/deploy/backups
```

The helper exports PostgreSQL with `pg_dump -Fc` and archives the report volume. Copy backups **off the instance**, and securely preserve the production environment/app key. This is an on-demand helper, not scheduled off-host backup automation. Database and files are separate snapshots; pause writes/workers for a consistent pair. Redis queue persistence is not included in this backup; drain jobs first if they must be preserved.

Test restoration into an isolated stack: use `pg_restore --clean --if-exists --no-owner -U "$POSTGRES_USER" -d "$POSTGRES_DB"` inside its PostgreSQL container, restore the archive into its report volume, then verify account login/report downloads. Never test a restore against the live production database.

```bash
# Local Terraform root; review carefully before confirming:
terraform destroy
```

Destroy removes the EC2/root disk, attached local Docker data, Elastic IP and managed security/IAM resources. The default VPC is not managed here. The Cloudflare DNS helper is outside Terraform, so remove its A record separately after teardown. State files and Docker volumes on the same root disk are not off-host backups.

## Costs and scope for the first post

The model is understandable, not a flat-rate guarantee: EC2, EBS, public IPv4, transfer, backups, and any domain/Cloudflare charges still apply. T3 defaults can also incur surplus CPU-credit charges. Docker isolates application dependencies; the host OS/kernel still needs patching. A volume persists through container replacement, not through loss of the host/disk.

Reference docs: [AWS VPC internet gateways](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_Internet_Gateway.html), [Fortify](https://laravel.com/docs/13.x/fortify), [Horizon](https://laravel.com/docs/13.x/horizon), [Caddy reload](https://caddyserver.com/docs/command-line#caddy-reload), and [GitHub AWS OIDC](https://docs.github.com/en/actions/how-tos/secure-your-work/security-harden-deployments/oidc-in-aws).

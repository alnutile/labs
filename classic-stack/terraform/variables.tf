variable "aws_region" {
  description = "AWS region for the learning server."
  type        = string
  default     = "us-west-2"
}

variable "name" {
  description = "Resource name prefix."
  type        = string
  default     = "classic-stack"
}

variable "instance_type" {
  description = "Small x86 instance for the first video."
  type        = string
  default     = "t3.small"
}

variable "root_volume_size_gb" {
  description = "Encrypted root disk size; increase only after checking storage needs."
  type        = number
  default     = 30
}

variable "app_user" {
  description = "Deployment user with Docker and sudo access; treat its SSH key as root-equivalent."
  type        = string
  default     = "deploy"
}

variable "admin_cidr_blocks" {
  description = "CIDR blocks allowed to SSH. Set this explicitly before applying."
  type        = list(string)
  default     = []
}

variable "ssh_public_keys" {
  description = "Public SSH keys installed for the deploy user by cloud-init. Never put private keys here."
  type        = list(string)
  validation {
    condition     = length(var.ssh_public_keys) > 0 && alltrue([for key in var.ssh_public_keys : can(regex("^ssh-(ed25519|rsa) ", key))])
    error_message = "Supply at least one ssh-ed25519 or ssh-rsa public key."
  }
}

variable "github_oidc_subject" {
  description = "Exact GitHub OIDC sub for the production environment. Empty disables Actions access. New repos may include immutable owner/repo IDs; see README."
  type        = string
  default     = ""
}

variable "github_oidc_provider_arn" {
  description = "Existing account-wide GitHub OIDC provider ARN, or empty to create one when Actions is enabled."
  type        = string
  default     = ""
}

variable "cloudflare_api_token" {
  description = "Cloudflare API token with Zone/DNS/Edit for the zone. Prefer TF_VAR_cloudflare_api_token or CLOUDFLARE_API_TOKEN; never commit it."
  type        = string
  sensitive   = true
  default     = ""
}

variable "cloudflare_zone_name" {
  description = "Cloudflare zone that owns the stack hostname."
  type        = string
  default     = "dailyai.studio"
}

variable "cloudflare_record_name" {
  description = "A-record name relative to the Cloudflare zone."
  type        = string
  default     = "classic-stack"
}

variable "cloudflare_record_proxied" {
  description = "Whether Cloudflare proxies the origin. Keep false until origin HTTPS is working."
  type        = bool
  default     = false
}

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
  description = "Unprivileged user that owns application releases and data."
  type        = string
  default     = "deploy"
}

variable "admin_cidr_blocks" {
  description = "CIDR blocks allowed to SSH. Set this explicitly before applying."
  type        = list(string)
  default     = []
}

output "elastic_ip" {
  description = "Cloudflare origin address and deployment host."
  value       = aws_eip.classic_stack.public_ip
}

output "instance_id" {
  value = aws_instance.classic_stack.id
}

output "ssh_command" {
  value = "ssh ${var.app_user}@${aws_eip.classic_stack.public_ip}"
}

output "github_deploy_role_arn" {
  value = try(aws_iam_role.github_deploy[0].arn, null)
}
output "github_runner_security_group_id" {
  value = try(aws_security_group.github_runner[0].id, null)
}

output "cloudflare_hostname" {
  value = "${var.cloudflare_record_name}.${var.cloudflare_zone_name}"
}

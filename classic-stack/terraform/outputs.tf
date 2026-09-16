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

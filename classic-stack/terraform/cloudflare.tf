locals {
  cloudflare_enabled = trimspace(var.cloudflare_api_token) != ""
}

data "cloudflare_zone" "classic_stack" {
  count = local.cloudflare_enabled ? 1 : 0

  name = var.cloudflare_zone_name
}

resource "cloudflare_record" "classic_stack" {
  count = local.cloudflare_enabled ? 1 : 0

  zone_id = data.cloudflare_zone.classic_stack[0].id
  name    = var.cloudflare_record_name
  type    = "A"
  value   = aws_eip.classic_stack.public_ip
  ttl     = 1
  proxied = var.cloudflare_record_proxied
}

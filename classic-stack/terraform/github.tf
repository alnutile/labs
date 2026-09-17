# A separate SG avoids Terraform reconciling the runner's temporary /32 into
# the operator group's inline rules. No ingress exists here between deployments.
resource "aws_security_group" "github_runner" {
  count       = var.github_oidc_subject != "" ? 1 : 0
  name        = "${var.name}-github-runner"
  description = "Temporary SSH access for a GitHub Actions deployment"
  vpc_id      = data.aws_vpc.default.id
  tags        = { Name = "${var.name}-github-runner" }
}

resource "aws_iam_openid_connect_provider" "github" {
  count          = var.github_oidc_subject != "" && var.github_oidc_provider_arn == "" ? 1 : 0
  url            = "https://token.actions.githubusercontent.com"
  client_id_list = ["sts.amazonaws.com"]
}

resource "aws_iam_role" "github_deploy" {
  count = var.github_oidc_subject != "" ? 1 : 0
  name  = "${var.name}-github-deploy"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow"
      Action = "sts:AssumeRoleWithWebIdentity"
      Principal = {
        Federated = var.github_oidc_provider_arn != "" ? var.github_oidc_provider_arn : aws_iam_openid_connect_provider.github[0].arn
      }
      Condition = { StringEquals = {
        "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com"
        "token.actions.githubusercontent.com:sub" = var.github_oidc_subject
      } }
    }]
  })
}

resource "aws_iam_role_policy" "github_deploy" {
  count = var.github_oidc_subject != "" ? 1 : 0
  role  = aws_iam_role.github_deploy[0].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["ec2:AuthorizeSecurityGroupIngress", "ec2:RevokeSecurityGroupIngress"]
      Resource = aws_security_group.github_runner[0].arn
    }]
  })
}

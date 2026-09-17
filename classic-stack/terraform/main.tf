terraform {
  required_version = ">= 1.6.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = var.aws_region
}

data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"]

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
}

resource "aws_security_group" "classic_stack" {
  name        = "${var.name}-sg"
  description = "Web access and restricted administration for classic-stack"
  vpc_id      = data.aws_vpc.default.id

  ingress {
    description = "HTTP"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTPS"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  dynamic "ingress" {
    for_each = var.admin_cidr_blocks
    content {
      description = "SSH from the operator network"
      from_port   = 22
      to_port     = 22
      protocol    = "tcp"
      cidr_blocks = [ingress.value]
    }
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = { Name = "${var.name}-sg" }
}

resource "aws_instance" "classic_stack" {
  ami                         = data.aws_ami.ubuntu.id
  instance_type               = var.instance_type
  subnet_id                   = data.aws_subnets.default.ids[0]
  vpc_security_group_ids      = concat([aws_security_group.classic_stack.id], aws_security_group.github_runner[*].id)
  associate_public_ip_address = true
  user_data = templatefile("${path.module}/cloud-init.yaml", {
    app_user        = var.app_user
    ssh_public_keys = var.ssh_public_keys
  })
  user_data_replace_on_change = true

  root_block_device {
    encrypted   = true
    volume_type = "gp3"
    volume_size = var.root_volume_size_gb
  }

  metadata_options {
    http_tokens = "required"
  }

  tags = { Name = var.name }
}

resource "aws_eip" "classic_stack" {
  domain = "vpc"
  tags   = { Name = "${var.name}-eip" }
}

resource "aws_eip_association" "classic_stack" {
  allocation_id = aws_eip.classic_stack.id
  instance_id   = aws_instance.classic_stack.id
}

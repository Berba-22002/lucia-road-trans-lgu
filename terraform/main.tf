terraform {
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

# ── Variables ────────────────────────────────────────
variable "aws_region"    { default = "ap-southeast-1" } # Singapore (closest to PH)
variable "instance_type" { default = "t3.small" }
variable "key_name"      { description = "EC2 Key Pair name" }
variable "db_password"   { sensitive = true }

# ── Security Group ───────────────────────────────────
resource "aws_security_group" "rtim_sg" {
  name        = "rtim-sg"
  description = "LGU RTIM - allow HTTP, HTTPS, SSH"

  ingress {
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"] # restrict to your IP in production
  }

  ingress {
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

# ── EC2 Instance (Web + App Server) ─────────────────
resource "aws_instance" "rtim_server" {
  ami                    = "ami-0df7a207adb9748c7" # Ubuntu 22.04 ap-southeast-1
  instance_type          = var.instance_type
  key_name               = var.key_name
  vpc_security_group_ids = [aws_security_group.rtim_sg.id]

  root_block_device {
    volume_size = 30  # GB — accounts for /uploads/* media files
    volume_type = "gp3"
  }

  tags = { Name = "rtim-web-server", Project = "LGU-RTIM" }
}

# ── RDS MySQL (road_rtim database) ──────────────────
resource "aws_db_instance" "rtim_db" {
  identifier                = "rtim-mysql"
  engine                    = "mysql"
  engine_version            = "8.0"
  instance_class            = "db.t3.micro"
  allocated_storage         = 20
  storage_type              = "gp2"
  db_name                   = "road_rtim"
  username                  = "road_rtim"
  password                  = var.db_password
  skip_final_snapshot       = false
  final_snapshot_identifier = "rtim-final-snapshot"
  publicly_accessible       = false
  vpc_security_group_ids    = [aws_security_group.rtim_sg.id]

  tags = { Project = "LGU-RTIM" }
}

# ── S3 Bucket (hazard_reports, ovr_evidence, project-requests, etc.) ──
resource "aws_s3_bucket" "rtim_uploads" {
  bucket = "rtim-lgu-uploads"
  tags   = { Project = "LGU-RTIM" }
}

resource "aws_s3_bucket_versioning" "rtim_uploads_versioning" {
  bucket = aws_s3_bucket.rtim_uploads.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_public_access_block" "rtim_uploads_block" {
  bucket                  = aws_s3_bucket.rtim_uploads.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# ── Outputs ──────────────────────────────────────────
output "server_public_ip" { value = aws_instance.rtim_server.public_ip }
output "rds_endpoint"     { value = aws_db_instance.rtim_db.endpoint }
output "s3_bucket_name"   { value = aws_s3_bucket.rtim_uploads.bucket }

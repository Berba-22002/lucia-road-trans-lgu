#!/bin/bash

set -e

ENVIRONMENT=${1:-staging}
VERSION=${2:-latest}

echo "Deploying to $ENVIRONMENT environment..."

# Build Docker image
docker build -t lucia-rtim:$VERSION .

# Tag for registry
docker tag lucia-rtim:$VERSION your-registry/lucia-rtim:$VERSION

# Push to registry
docker push your-registry/lucia-rtim:$VERSION

# Deploy with Ansible
ansible-playbook -i inventory/$ENVIRONMENT/hosts ansible/playbook.yml \
  --extra-vars "app_version=$VERSION environment=$ENVIRONMENT"

echo "Deployment to $ENVIRONMENT completed successfully!"
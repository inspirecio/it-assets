#!/bin/bash
# Server setup script for first-time deployment
# Run this before your first deployment

set -e

SERVER="root@lamp4.inspirecio.com"
DEPLOY_PATH="/var/www/sites/assets.inspirecxo.com"

echo "========================================="
echo "Server Setup for First Deployment"
echo "========================================="
echo ""
echo "Server: $SERVER"
echo "Deploy Path: $DEPLOY_PATH"
echo ""

# Create deployment directory structure
echo "1. Creating deployment directory structure..."
ssh $SERVER "mkdir -p $DEPLOY_PATH/shared/storage/certs"
ssh $SERVER "mkdir -p $DEPLOY_PATH/shared/storage/app/public"
ssh $SERVER "mkdir -p $DEPLOY_PATH/shared/storage/framework/cache/data"
ssh $SERVER "mkdir -p $DEPLOY_PATH/shared/storage/framework/sessions"
ssh $SERVER "mkdir -p $DEPLOY_PATH/shared/storage/framework/views"
ssh $SERVER "mkdir -p $DEPLOY_PATH/shared/storage/logs"
ssh $SERVER "mkdir -p $DEPLOY_PATH/shared/public/uploads"
echo "✓ Directory structure created"
echo ""

# Upload .env file
echo "2. Uploading .env file..."
if [ -f .env.prod ]; then
    scp .env.prod $SERVER:$DEPLOY_PATH/shared/.env
    echo "✓ .env file uploaded from .env.prod"
else
    echo "⚠ Warning: .env.prod not found. You'll need to create .env manually on the server."
    echo "  Location: $DEPLOY_PATH/shared/.env"
fi
echo ""

# Upload SSL certificate
echo "3. Uploading SSL certificate..."
if [ -f storage/certs/ca-certificate.crt ]; then
    scp storage/certs/ca-certificate.crt $SERVER:$DEPLOY_PATH/shared/storage/certs/
    echo "✓ SSL certificate uploaded"
else
    echo "⚠ Warning: SSL certificate not found at storage/certs/ca-certificate.crt"
    echo "  You'll need to upload it manually to: $DEPLOY_PATH/shared/storage/certs/"
fi
echo ""

# Set permissions
echo "4. Setting permissions..."
ssh $SERVER "chmod -R 775 $DEPLOY_PATH/shared/storage"
ssh $SERVER "chmod -R 775 $DEPLOY_PATH/shared/public/uploads"
echo "✓ Permissions set"
echo ""

# Show .env file location on server
echo "========================================="
echo "Setup Complete!"
echo "========================================="
echo ""
echo "Next steps:"
echo "1. Verify .env file on server: $DEPLOY_PATH/shared/.env"
echo "2. Update database credentials and APP_KEY in .env if needed"
echo "3. Run deployment: dep deploy"
echo ""

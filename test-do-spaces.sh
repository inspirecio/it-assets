#!/bin/bash

# Digital Ocean Spaces Test Script
# This script tests connectivity to Digital Ocean Spaces by explicitly setting environment variables
# This is useful for testing production storage configuration

echo "==================================================================="
echo "Digital Ocean Spaces Connection Test"
echo "==================================================================="
echo ""
echo "This script will test your Digital Ocean Spaces configuration"
echo "by uploading a test file and verifying it exists."
echo ""

# Load configuration from .env file
source .env

# Export the necessary environment variables
export PRIVATE_FILESYSTEM_DISK=s3_private
export PUBLIC_FILESYSTEM_DISK=s3_public

export PRIVATE_AWS_ACCESS_KEY_ID="$PRIVATE_AWS_ACCESS_KEY_ID"
export PRIVATE_AWS_SECRET_ACCESS_KEY="$PRIVATE_AWS_SECRET_ACCESS_KEY"
export PRIVATE_AWS_DEFAULT_REGION="$PRIVATE_AWS_DEFAULT_REGION"
export PRIVATE_AWS_BUCKET="$PRIVATE_AWS_BUCKET"
export PRIVATE_AWS_ENDPOINT="$PRIVATE_AWS_ENDPOINT"
export PRIVATE_AWS_URL="$PRIVATE_AWS_URL"
export PRIVATE_AWS_BUCKET_ROOT="$PRIVATE_AWS_BUCKET_ROOT"
export PRIVATE_AWS_USE_PATH_STYLE_ENDPOINT="$PRIVATE_AWS_USE_PATH_STYLE_ENDPOINT"

export PUBLIC_AWS_ACCESS_KEY_ID="$PUBLIC_AWS_ACCESS_KEY_ID"
export PUBLIC_AWS_SECRET_ACCESS_KEY="$PUBLIC_AWS_SECRET_ACCESS_KEY"
export PUBLIC_AWS_DEFAULT_REGION="$PUBLIC_AWS_DEFAULT_REGION"
export PUBLIC_AWS_BUCKET="$PUBLIC_AWS_BUCKET"
export PUBLIC_AWS_ENDPOINT="$PUBLIC_AWS_ENDPOINT"
export PUBLIC_AWS_URL="$PUBLIC_AWS_URL"
export PUBLIC_AWS_BUCKET_ROOT="$PUBLIC_AWS_BUCKET_ROOT"
export PUBLIC_AWS_USE_PATH_STYLE_ENDPOINT="$PUBLIC_AWS_USE_PATH_STYLE_ENDPOINT"

# Clear Laravel config cache
php artisan config:clear > /dev/null 2>&1

# Run the storage test
php artisan snipeit:test-storage --cleanup

echo ""
echo "==================================================================="
echo "Test Complete!"
echo "==================================================================="

#!/bin/bash
# Test deployment configuration and server readiness
# Run this before deploying to catch issues early

set -e

SERVER="root@lamp4.inspirecio.com"
DEPLOY_PATH="/var/www/sites/assets.inspirecxo.com"
REPO="git@github.com:inspirecio/it-assets.git"

echo "========================================="
echo "Deployment Pre-flight Check"
echo "========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0
WARNINGS=0

test_check() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓${NC} $2"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "${RED}✗${NC} $2"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

warning_check() {
    echo -e "${YELLOW}⚠${NC} $1"
    WARNINGS=$((WARNINGS + 1))
}

# Test 1: SSH Connection
echo "Testing SSH connection..."
ssh -o ConnectTimeout=5 $SERVER "echo 'Connected'" > /dev/null 2>&1
test_check $? "SSH connection to $SERVER"
echo ""

# Test 2: Git repository access
echo "Testing Git repository access..."
ssh -T git@github.com 2>&1 | grep -q "successfully authenticated"
test_check $? "Git authentication (GitHub)"
echo ""

# Test 3: PHP version on server
echo "Checking PHP version on server..."
PHP_VERSION=$(ssh $SERVER "php -r 'echo PHP_VERSION;'")
echo "  Server PHP version: $PHP_VERSION"
php -r "exit(version_compare('$PHP_VERSION', '8.1', '>=') ? 0 : 1);"
test_check $? "PHP version >= 8.1"
echo ""

# Test 4: Composer on server
echo "Checking Composer on server..."
ssh $SERVER "composer --version" > /dev/null 2>&1
test_check $? "Composer installed on server"
echo ""

# Test 5: Required PHP extensions
echo "Checking required PHP extensions on server..."
REQUIRED_EXTS="mbstring openssl pdo pdo_mysql tokenizer xml ctype json bcmath"
for ext in $REQUIRED_EXTS; do
    ssh $SERVER "php -m | grep -i '^$ext$'" > /dev/null 2>&1
    test_check $? "PHP extension: $ext"
done
echo ""

# Test 6: Deploy directory
echo "Checking deployment directory..."
if ssh $SERVER "[ -d $DEPLOY_PATH ]"; then
    echo -e "${GREEN}✓${NC} Deployment directory exists: $DEPLOY_PATH"
    TESTS_PASSED=$((TESTS_PASSED + 1))

    # Check if it's been initialized
    if ssh $SERVER "[ -d $DEPLOY_PATH/shared ]"; then
        echo -e "${GREEN}✓${NC} Deployment initialized (shared folder exists)"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        warning_check "Deployment not initialized yet (run deploy-setup.sh first)"
    fi
else
    warning_check "Deployment directory doesn't exist (will be created on first deploy)"
fi
echo ""

# Test 7: .env file
echo "Checking .env file..."
if ssh $SERVER "[ -f $DEPLOY_PATH/shared/.env ]"; then
    echo -e "${GREEN}✓${NC} .env file exists on server"
    TESTS_PASSED=$((TESTS_PASSED + 1))

    # Check for critical settings
    ENV_CHECKS=("APP_KEY" "DB_DATABASE" "DB_USERNAME" "DB_PASSWORD")
    for key in "${ENV_CHECKS[@]}"; do
        if ssh $SERVER "grep -q '^$key=' $DEPLOY_PATH/shared/.env"; then
            echo -e "${GREEN}✓${NC}   $key is set"
            TESTS_PASSED=$((TESTS_PASSED + 1))
        else
            warning_check "  $key not found in .env"
        fi
    done
else
    if [ -f .env.prod ]; then
        warning_check ".env file not on server (run deploy-setup.sh to upload)"
    else
        echo -e "${RED}✗${NC} .env file not found locally or on server"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
fi
echo ""

# Test 8: SSL Certificate
echo "Checking SSL certificate..."
if [ -f storage/certs/ca-certificate.crt ]; then
    echo -e "${GREEN}✓${NC} SSL certificate found locally"
    TESTS_PASSED=$((TESTS_PASSED + 1))

    if ssh $SERVER "[ -f $DEPLOY_PATH/shared/storage/certs/ca-certificate.crt ]"; then
        echo -e "${GREEN}✓${NC} SSL certificate exists on server"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        warning_check "SSL certificate not on server yet"
    fi
else
    warning_check "SSL certificate not found locally"
fi
echo ""

# Test 9: Deployer configuration
echo "Checking Deployer configuration..."
if [ -f deploy.php ]; then
    echo -e "${GREEN}✓${NC} deploy.php exists"
    TESTS_PASSED=$((TESTS_PASSED + 1))

    # Check for shared configuration
    if grep -q "add('shared_files'" deploy.php; then
        echo -e "${GREEN}✓${NC} Shared files configured"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        warning_check "No shared files configured"
    fi

    if grep -q "add('shared_dirs'" deploy.php; then
        echo -e "${GREEN}✓${NC} Shared directories configured"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        warning_check "No shared directories configured"
    fi
else
    echo -e "${RED}✗${NC} deploy.php not found"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi
echo ""

# Test 10: Local Git status
echo "Checking local Git status..."
if git diff --quiet && git diff --cached --quiet; then
    echo -e "${GREEN}✓${NC} Working directory is clean"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    warning_check "Uncommitted changes in working directory"
fi

CURRENT_BRANCH=$(git branch --show-current)
echo "  Current branch: $CURRENT_BRANCH"
echo ""

# Summary
echo "========================================="
echo "Test Summary"
echo "========================================="
echo -e "${GREEN}Passed:${NC} $TESTS_PASSED"
echo -e "${RED}Failed:${NC} $TESTS_FAILED"
echo -e "${YELLOW}Warnings:${NC} $WARNINGS"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ Ready to deploy!${NC}"
    echo ""
    if [ $WARNINGS -gt 0 ]; then
        echo "Warnings detected. Review them before deploying:"
        echo "  - If this is your first deployment, run: ./deploy-setup.sh"
        echo "  - Otherwise, you can proceed with: dep deploy"
    else
        echo "Run: dep deploy"
    fi
    exit 0
else
    echo -e "${RED}✗ Not ready to deploy. Fix the failed tests first.${NC}"
    exit 1
fi

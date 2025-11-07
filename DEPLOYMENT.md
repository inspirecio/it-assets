# Deployment Guide for IT Assets

## Overview
This project uses [Deployer](https://deployer.org/) for automated deployments to the production server.

**Server:** lamp4.inspirecio.com
**Deploy Path:** /var/www/sites/assets.inspirecxo.com
**Repository:** git@github.com:inspirecio/it-assets.git

## Prerequisites

1. **SSH Access:** Ensure you can SSH into the server as root
2. **Git Access:** Your SSH key must have access to the GitHub repository
3. **Deployer Installed:** `dep` command should be available (already installed globally)

## Deployment Files

- **deploy.php** - Main deployment configuration
- **deploy-setup.sh** - First-time server setup script
- **deploy-test.sh** - Pre-deployment validation script
- **.env.prod** - Production environment configuration

## First-Time Setup

Before your first deployment, you need to set up the server:

```bash
# 1. Run the setup script to create directory structure and upload files
./deploy-setup.sh

# 2. Verify the .env file on the server
ssh root@lamp4.inspirecio.com
cat /var/www/sites/assets.inspirecxo.com/shared/.env

# 3. Verify SSL certificate was uploaded
ls -la /var/www/sites/assets.inspirecxo.com/shared/storage/certs/
```

## Deployment Process

### Standard Deployment

```bash
# Deploy to production
dep deploy
```

### What Happens During Deployment

1. **Preparation**
   - Creates new release directory
   - Clones code from GitHub
   - Checks .env file exists
   - Links shared files and directories
   - Checks SSL certificates

2. **Build**
   - Installs Composer dependencies (`composer install --no-dev`)
   - Creates storage symlink
   - Caches config, routes, views, and events

3. **Database**
   - Runs migrations (`php artisan migrate --force`)
   - Optimizes application (`php artisan optimize`)

4. **Finalize**
   - Symlinks new release to `current`
   - Cleans up old releases (keeps last 5)

## Shared Files & Directories

These persist across deployments:

**Shared Files:**
- `.env` - Environment configuration

**Shared Directories:**
- `storage/` - Application storage (logs, cache, sessions)
- `public/uploads/` - User uploads

## Deployment Tasks

### Custom Tasks

- `deploy:check_env` - Validates .env file exists before deployment
- `deploy:check_certs` - Validates SSL certificates after shared setup
- `deploy:optimize` - Optimizes Laravel application after migrations

### Useful Commands

```bash
# List all available tasks
dep list

# Show deployment task tree
dep tree deploy

# SSH into the server
dep ssh

# Show releases list
dep releases

# Rollback to previous release
dep rollback

# Run a custom artisan command
dep artisan:cache:clear
```

## Testing Before Deployment

Always run the pre-flight test before deploying:

```bash
./deploy-test.sh
```

This checks:
- SSH connection
- Git repository access
- PHP version and extensions
- Composer availability
- .env file presence
- SSL certificates
- Git status

## Troubleshooting

### Deployment Fails at "deploy:check_env"

**Problem:** .env file not found on server
**Solution:** Run `./deploy-setup.sh` or manually create the file

### Deployment Fails at "deploy:vendors"

**Problem:** Composer dependency issues
**Solution:** Check composer.json and ensure all dependencies are valid

### Database Migration Fails

**Problem:** Database connection issues or migration errors
**Solution:**
- Verify database credentials in .env
- Check SSL certificate path
- Ensure DB_SSL_CA_PATH points to the correct location on server

### Application Shows 500 Error After Deployment

**Solutions:**
1. Check storage permissions: `dep run 'chmod -R 775 storage'`
2. Clear cache: `dep artisan:cache:clear`
3. Check logs: `dep run 'tail -50 storage/logs/laravel.log'`

## Important Notes

### Environment Variables

The `.env.prod` file is uploaded to the server as `.env` during first-time setup. Key settings:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://assets.inspirecxo.com`
- `DB_SSL_CA_PATH=/var/www/sites/assets.inspirecxo.com/shared/storage/certs/ca-certificate.crt`

### Database SSL Certificate

The SSL certificate path in `.env.prod` has been set to the deployed location:
```
DB_SSL_CA_PATH=/var/www/sites/assets.inspirecxo.com/shared/storage/certs/ca-certificate.crt
```

This ensures the application can connect to the DigitalOcean MySQL database with SSL.

### Deployment Structure

After deployment, the server structure will be:

```
/var/www/sites/assets.inspirecxo.com/
├── current -> releases/1
├── releases/
│   └── 1/
│       ├── app/
│       ├── public/
│       ├── storage -> ../../shared/storage
│       ├── .env -> ../../shared/.env
│       └── ...
└── shared/
    ├── .env
    ├── storage/
    │   └── certs/
    │       └── ca-certificate.crt
    └── public/
        └── uploads/
```

## Security Checklist

Before deploying to production:

- [ ] APP_KEY is generated (not "ChangeMe")
- [ ] APP_DEBUG is set to false
- [ ] Database credentials are correct
- [ ] SSL certificate is uploaded
- [ ] Mail settings are configured
- [ ] File permissions are secure
- [ ] .env file is not in version control

## Quick Reference

```bash
# First time
./deploy-setup.sh
dep deploy

# Subsequent deployments
./deploy-test.sh  # Optional but recommended
dep deploy

# Rollback if needed
dep rollback
```

## Support

For issues with:
- **Deployer:** See https://deployer.org/docs/7.x/
- **Laravel:** See https://laravel.com/docs
- **Server Issues:** Contact server administrator

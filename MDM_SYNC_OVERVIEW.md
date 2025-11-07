# MDM Sync Overview - Snipe-IT Integration

This Snipe-IT installation includes comprehensive MDM (Mobile Device Management) sync capabilities for both Microsoft Intune and JAMF Pro.

## Available Sync Jobs

### 1. Microsoft Intune Sync

**Files:**
- `app/Jobs/SyncIntuneDevicesToDatabase.php` - Main sync job
- `app/Jobs/ProcessIntuneDeviceBatch.php` - Batch processor
- `INTUNE_SYNC_README.md` - Full documentation

**Use Case:** Sync Windows, Android, iOS, and macOS devices managed by Microsoft Intune

**Device Types Supported:**
- Windows laptops/desktops
- Android phones/tablets
- iOS devices (iPhone/iPad)
- macOS computers

**Manufacturers:** Variable (Microsoft, Dell, HP, Lenovo, Apple, Samsung, etc.)

**Run Command:**
```php
dispatch(new App\Jobs\SyncIntuneDevicesToDatabase);
```

### 2. JAMF Pro Sync

**Files:**
- `app/Jobs/SyncJamfDevicesToDatabase.php` - Main sync job
- `app/Jobs/ProcessJamfDeviceBatch.php` - Batch processor
- `JAMF_SYNC_README.md` - Full documentation

**Use Case:** Sync Apple devices (Mac, iPad, iPhone) managed by JAMF Pro

**Device Types Supported:**
- Mac computers (MacBook, iMac, Mac mini, Mac Pro, Mac Studio)
- iPads (all models)
- iPhones (all models)

**Manufacturer:** Apple (all devices)

**Run Command:**
```php
dispatch(new App\Jobs\SyncJamfDevicesToDatabase);
```

## Feature Comparison

| Feature | Intune Sync | JAMF Sync |
|---------|-------------|-----------|
| **Platforms** | Windows, Android, iOS, macOS | macOS, iOS only |
| **Device Types** | All managed devices | Computers + Mobile Devices (separate categories) |
| **Authentication** | OAuth 2.0 (Microsoft Graph) | Basic Auth (JAMF Classic API) |
| **Manufacturers** | Auto-detected (variable) | Always "Apple" |
| **Categories** | "Intune Devices" (single) | "JAMF Computers" + "JAMF Mobile Devices" |
| **User Assignment** | By email (userPrincipalName) | By email or username |
| **Location Matching** | Not supported | By building name |
| **Purchase Data** | Not available | Fully supported (date, cost, PO) |
| **API Pagination** | OData `@odata.nextLink` | Individual detail calls |
| **Typical Sync Time** | 2-3 min per 1000 devices | 10-15 min per 500 devices |

## Configuration Summary

### Environment Variables

```env
# Microsoft Intune
MICROSOFT_TENANT_ID=your-tenant-id
MICROSOFT_CLIENT_ID=your-client-id
MICROSOFT_CLIENT_SECRET=your-client-secret
INTUNE_DEFAULT_CATEGORY_ID=
INTUNE_DEFAULT_STATUS_ID=
INTUNE_DEFAULT_LOCATION_ID=
INTUNE_SYNC_CHUNK_SIZE=50
INTUNE_AUTO_ASSIGN_USERS=false

# JAMF Pro
JAMF_URL=https://yourcompany.jamfcloud.com
JAMF_USERNAME=api-user
JAMF_PASSWORD=api-password
JAMF_DEFAULT_CATEGORY_ID_COMPUTERS=
JAMF_DEFAULT_CATEGORY_ID_MOBILE=
JAMF_DEFAULT_STATUS_ID=
JAMF_DEFAULT_LOCATION_ID=
JAMF_SYNC_CHUNK_SIZE=50
JAMF_AUTO_ASSIGN_USERS=false
JAMF_SYNC_COMPUTERS=true
JAMF_SYNC_MOBILE_DEVICES=true
```

## Common Architecture

Both sync systems share the same architectural pattern:

### 1. Main Job Responsibilities
- Authenticate with MDM API
- Fetch all devices from MDM
- Clear cache for fresh sync
- Split devices into chunks
- Dispatch batch jobs

### 2. Batch Job Responsibilities
- Process chunk of devices
- Use database transactions
- Create/update manufacturers
- Create/update models
- Create/update assets
- Match users by email
- Cache lookups
- Log operations

### 3. Database Operations
- `Manufacturer::firstOrCreate()` - Auto-create manufacturers
- `AssetModel::firstOrCreate()` - Auto-create models
- `Category::firstOrCreate()` - Auto-create categories
- `Asset::updateOrCreate()` - Upsert assets by serial
- `User::where('email', ...)` - Match users
- `Location::where('name', ...)` - Match locations (JAMF only)

## Usage Patterns

### Run Both Syncs Together

```php
// In tinker or a custom command
dispatch(new App\Jobs\SyncIntuneDevicesToDatabase);
dispatch(new App\Jobs\SyncJamfDevicesToDatabase);
```

### Schedule Both Syncs

In `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Sync Intune devices daily at 2 AM
    $schedule->job(new \App\Jobs\SyncIntuneDevicesToDatabase)
        ->dailyAt('02:00')
        ->name('intune-sync')
        ->withoutOverlapping();

    // Sync JAMF devices daily at 3 AM
    $schedule->job(new \App\Jobs\SyncJamfDevicesToDatabase)
        ->dailyAt('03:00')
        ->name('jamf-sync')
        ->withoutOverlapping();
}
```

### Create Unified Command

Create `app/Console/Commands/SyncAllMdm.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SyncIntuneDevicesToDatabase;
use App\Jobs\SyncJamfDevicesToDatabase;
use Illuminate\Console\Command;

class SyncAllMdm extends Command
{
    protected $signature = 'mdm:sync-all';
    protected $description = 'Sync devices from all MDM systems (Intune + JAMF)';

    public function handle()
    {
        $this->info('Dispatching Intune sync job...');
        dispatch(new SyncIntuneDevicesToDatabase);

        $this->info('Dispatching JAMF sync job...');
        dispatch(new SyncJamfDevicesToDatabase);

        $this->info('All MDM sync jobs dispatched successfully!');
        $this->info('Use "tail -f storage/logs/laravel.log" to monitor progress.');
    }
}
```

Then run: `php artisan mdm:sync-all`

## Monitoring All Syncs

### View All MDM Logs

```bash
tail -f storage/logs/laravel.log | grep -iE 'intune|jamf'
```

### View Specific Sync

```bash
# Intune only
tail -f storage/logs/laravel.log | grep -i intune

# JAMF only
tail -f storage/logs/laravel.log | grep -i jamf
```

### Check Queue Status

```bash
# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Monitor queue in real-time
php artisan queue:work --verbose
```

## Data Flow

### Asset Creation Flow

```
MDM API
   ↓
Main Sync Job (Fetch devices, chunk them)
   ↓
Batch Jobs (Process chunks in parallel)
   ↓
Database (Create/update via Eloquent)
   ↓
Snipe-IT Assets (Visible in web UI)
```

### Caching Strategy

Both syncs use multi-level caching:

1. **Manufacturer Cache**: `{mdm}_manufacturer_{name}`
2. **Model Cache**: `{mdm}_model_{name}_{manufacturer_id}`
3. **Category Cache**: `{mdm}_category` or `{mdm}_category_{type}`
4. **Status Cache**: `{mdm}_default_status`
5. **Location Cache**: `{mdm}_location_{name}` (JAMF only)

Cache TTL: 1 hour (3600 seconds)

## Troubleshooting Matrix

| Symptom | Intune | JAMF | Solution |
|---------|--------|------|----------|
| No devices syncing | Check Azure AD app permissions | Check JAMF API user permissions | Verify credentials in `.env` |
| Slow sync | Normal (OAuth overhead) | Expected (individual API calls) | Increase chunk size, add queue workers |
| Assets not assigned | Enable `INTUNE_AUTO_ASSIGN_USERS` | Enable `JAMF_AUTO_ASSIGN_USERS` | Ensure users exist with matching emails |
| Wrong category | Set `INTUNE_DEFAULT_CATEGORY_ID` | Set `JAMF_DEFAULT_CATEGORY_ID_*` | Or let it auto-create categories |
| Missing location | Not supported | Check location names match | Create locations in Snipe-IT matching JAMF |
| No purchase data | Not available in Intune API | Populate in JAMF Pro | Add purchase info to JAMF devices |

## Performance Tips

### For Large Deployments (1000+ devices)

1. **Increase Chunk Size**
   ```env
   INTUNE_SYNC_CHUNK_SIZE=100
   JAMF_SYNC_CHUNK_SIZE=100
   ```

2. **Run Multiple Queue Workers**
   ```bash
   # Terminal 1
   php artisan queue:work --queue=default --sleep=3 --tries=3

   # Terminal 2
   php artisan queue:work --queue=default --sleep=3 --tries=3

   # Terminal 3
   php artisan queue:work --queue=default --sleep=3 --tries=3
   ```

3. **Use Redis for Cache**
   ```env
   CACHE_DRIVER=redis
   QUEUE_CONNECTION=redis
   ```

4. **Optimize Database**
   - Add index on `assets.serial`
   - Add index on `users.email`
   - Add index on `locations.name`

5. **Use Horizon (Production)**
   ```bash
   composer require laravel/horizon
   php artisan horizon:install
   php artisan horizon
   ```

## Best Practices

1. **Run Syncs During Off-Hours**: Schedule for overnight (2-3 AM)
2. **Monitor First Runs**: Watch logs closely on initial sync
3. **Test on Staging First**: Verify sync on test instance before production
4. **Backup Before First Sync**: `php artisan backup:run`
5. **Keep Separate Categories**: Don't merge Intune and JAMF categories
6. **Document Custom Mappings**: Note any custom location or category mappings
7. **Review Failed Jobs**: Check `php artisan queue:failed` after each sync
8. **Audit User Assignments**: Verify assigned users are correct

## Which Sync Should I Use?

### Use Intune Sync If:
- ✅ You manage Windows devices
- ✅ You manage Android devices
- ✅ You have a Microsoft 365 / Azure AD environment
- ✅ You use Microsoft Endpoint Manager
- ✅ You need fast syncs (2-3 min per 1000 devices)

### Use JAMF Sync If:
- ✅ You manage Mac computers exclusively
- ✅ You manage iPads and iPhones
- ✅ You need purchase data (cost, date, PO)
- ✅ You need location matching by building
- ✅ You use JAMF Pro for Apple device management

### Use Both Syncs If:
- ✅ You have a mixed environment (Windows + Mac)
- ✅ You use Intune for Windows/Android AND JAMF for Apple
- ✅ You want complete asset inventory from both systems

## Security Considerations

### Intune
- Store credentials in `.env` (not committed to git)
- Use dedicated Azure AD app registration
- Grant minimum required Microsoft Graph permissions
- Rotate client secret regularly (before expiration)
- Review Azure AD sign-in logs

### JAMF
- Use dedicated API user (not admin account)
- Grant Read-only permissions
- Use strong password with rotation
- Enable audit logging in JAMF Pro
- Restrict API user to Snipe-IT server IP (if possible)

## Migration Guide

### From Manual Intune Import
1. Run initial sync: `dispatch(new App\Jobs\SyncIntuneDevicesToDatabase);`
2. Compare asset count in Snipe-IT vs Intune portal
3. Verify sample assets have correct data
4. Schedule daily sync
5. Disable manual import process

### From Manual JAMF Import
1. Run initial sync: `dispatch(new App\Jobs\SyncJamfDevicesToDatabase);`
2. Compare asset count in Snipe-IT vs JAMF Pro
3. Check user assignments are correct
4. Verify locations matched correctly
5. Schedule daily sync
6. Disable manual import process

## Support & Documentation

- **Intune Sync**: See `INTUNE_SYNC_README.md`
- **JAMF Sync**: See `JAMF_SYNC_README.md`
- **Snipe-IT Docs**: https://snipe-it.readme.io/
- **Microsoft Graph API**: https://docs.microsoft.com/en-us/graph/
- **JAMF Pro API**: https://developer.jamf.com/jamf-pro/reference/

## Quick Reference

### Run Syncs
```bash
# Intune
php artisan tinker --execute="dispatch(new App\Jobs\SyncIntuneDevicesToDatabase);"

# JAMF
php artisan tinker --execute="dispatch(new App\Jobs\SyncJamfDevicesToDatabase);"

# Both
php artisan mdm:sync-all
```

### Monitor
```bash
# All logs
tail -f storage/logs/laravel.log

# MDM logs only
tail -f storage/logs/laravel.log | grep -iE 'intune|jamf'

# Queue workers
php artisan queue:work --verbose
```

### Troubleshoot
```bash
# Check failed jobs
php artisan queue:failed

# Retry all failed jobs
php artisan queue:retry all

# Clear cache
php artisan cache:clear

# Test credentials
php artisan tinker
> config('services.microsoft.client_id')
> config('services.jamf.url')
```

---

**Last Updated**: 2025-01-06
**Version**: 1.0
**Compatibility**: Laravel 8+, Snipe-IT 5+

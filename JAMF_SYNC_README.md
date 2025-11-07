# JAMF Pro to Snipe-IT Database Sync

A comprehensive sync system that integrates JAMF Pro with Snipe-IT, working directly with the database using Eloquent models for optimal performance.

## Features

- **Dual Device Type Support**: Syncs both computers (Mac) and mobile devices (iPad, iPhone)
- **Direct Database Access**: Uses Eloquent ORM for maximum performance
- **No Timeouts**: Chunked processing prevents timeout issues
- **Smart Caching**: Manufacturers, models, categories, and locations are cached
- **Auto-Creation**: Automatically creates manufacturers, models, and categories
- **Location Matching**: Matches JAMF locations to Snipe-IT locations by name
- **User Assignment**: Auto-assign assets to users based on email or username
- **Purchase Data**: Syncs purchase date, cost, and PO numbers
- **Idempotent**: Safe to run multiple times (uses `updateOrCreate`)
- **Transaction Safety**: Database transactions ensure data integrity
- **Comprehensive Logging**: Full audit trail of all sync operations
- **Selective Sync**: Choose to sync computers only, mobile devices only, or both

## Prerequisites

### JAMF Pro API Requirements

1. **JAMF Pro Account with API Access**
   - User account with API privileges
   - Recommended: Create a dedicated API user

2. **Required API Permissions**
   - Read Computers
   - Read Mobile Devices
   - Read Users (if syncing user assignments)

3. **API Authentication**
   - Basic Authentication (username + password)
   - Supports JAMF Pro Classic API

## Configuration

### Step 1: Add Environment Variables

Add these variables to your `.env` file:

```env
# JAMF Pro API Credentials (required)
JAMF_URL=https://yourcompany.jamfcloud.com
JAMF_USERNAME=api-user
JAMF_PASSWORD=your-api-password

# JAMF Sync Options (optional - defaults shown)
JAMF_SYNC_COMPUTERS=true                    # Sync Mac computers
JAMF_SYNC_MOBILE_DEVICES=true              # Sync iPads/iPhones
JAMF_AUTO_ASSIGN_USERS=true                # Auto-assign to users by email
JAMF_SYNC_CHUNK_SIZE=50                    # Devices per batch job
JAMF_DEFAULT_CATEGORY_ID_COMPUTERS=        # Category for computers (auto-creates if empty)
JAMF_DEFAULT_CATEGORY_ID_MOBILE=           # Category for mobile devices (auto-creates if empty)
JAMF_DEFAULT_STATUS_ID=                    # Status for synced devices (uses "Ready to Deploy" if empty)
JAMF_DEFAULT_LOCATION_ID=                  # Default location (matches by name if empty)
```

### Step 2: JAMF Pro API User Setup

1. Log into JAMF Pro web console
2. Navigate to **Settings** → **System** → **User Accounts & Groups**
3. Click **New**
4. Create user with these settings:
   - **Username**: `snipeit-api`
   - **Access Level**: Custom
   - **Privilege Set**: Create new with:
     - Computers: Read
     - Mobile Devices: Read
     - Users: Read (optional, for user assignment)
5. Set a strong password
6. Save and use these credentials in `.env`

## Usage

### Manual Sync via Tinker

```bash
php artisan tinker
```

Then run:
```php
dispatch(new App\Jobs\SyncJamfDevicesToDatabase);
```

### Create an Artisan Command (Recommended)

1. Create the command:
```bash
php artisan make:command SyncJamfDevices
```

2. Edit `app/Console/Commands/SyncJamfDevices.php`:
```php
<?php

namespace App\Console\Commands;

use App\Jobs\SyncJamfDevicesToDatabase;
use Illuminate\Console\Command;

class SyncJamfDevices extends Command
{
    protected $signature = 'jamf:sync';
    protected $description = 'Sync devices from JAMF Pro to Snipe-IT';

    public function handle()
    {
        $this->info('Dispatching JAMF sync job...');
        dispatch(new SyncJamfDevicesToDatabase);
        $this->info('JAMF sync job dispatched successfully!');
    }
}
```

3. Run the command:
```bash
php artisan jamf:sync
```

### Schedule Automatic Syncs

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Sync JAMF devices daily at 3 AM
    $schedule->job(new \App\Jobs\SyncJamfDevicesToDatabase)
        ->dailyAt('03:00')
        ->name('jamf-sync')
        ->withoutOverlapping();
}
```

## How It Works

### Architecture

**1. Main Job** (`SyncJamfDevicesToDatabase`):
   - Connects to JAMF Pro API
   - Fetches list of all computers (if enabled)
   - Fetches list of all mobile devices (if enabled)
   - Retrieves detailed information for each device
   - Splits devices into chunks (default: 50 per chunk)
   - Dispatches child jobs to process each chunk
   - Clears cache for fresh sync

**2. Batch Job** (`ProcessJamfDeviceBatch`):
   - Processes one chunk of devices
   - For each device:
     - Creates/finds manufacturer ("Apple")
     - Creates/finds asset model in database
     - Creates/finds appropriate category (computers vs mobile)
     - Matches location by name (if available)
     - Creates or updates asset using `updateOrCreate`
     - Optionally assigns to user by email/username
     - Syncs purchase data (date, cost, PO number)
     - Restores soft-deleted assets if found
   - Uses database transactions for safety
   - Caches lookups to reduce queries

### Device Data Mapping

#### Computers (Mac)

| JAMF Field | Snipe-IT Field | Notes |
|------------|---------------|-------|
| `general.serial_number` | `serial` | Primary identifier |
| `general.name` | `name` | Computer name |
| `hardware.model` | Model → `name` | e.g., "MacBook Pro (16-inch, 2021)" |
| Hardcoded "Apple" | Manufacturer → `name` | All JAMF devices are Apple |
| `hardware.os_version` | `notes` | Stored in notes field |
| `location.email_address` | User assignment | Matched to users table |
| `location.username` | User assignment | Fallback if email missing |
| `location.building` | `location_id` | Matched by name |
| `purchasing.purchase_date` | `purchase_date` | Date purchased |
| `purchasing.purchase_price` | `purchase_cost` | Purchase cost |
| `purchasing.po_number` | `order_number` | PO number |

#### Mobile Devices (iPad/iPhone)

| JAMF Field | Snipe-IT Field | Notes |
|------------|---------------|-------|
| `general.serial_number` | `serial` | Primary identifier |
| `general.name` or `display_name` | `name` | Device name |
| `general.model` | Model → `name` | e.g., "iPad Pro 12.9-inch (5th generation)" |
| Hardcoded "Apple" | Manufacturer → `name` | All JAMF devices are Apple |
| `general.os_version` | `notes` | iOS version stored in notes |
| `general.capacity` | `notes` | Storage capacity |
| `location.email_address` | User assignment | Matched to users table |
| `location.username` | User assignment | Fallback if email missing |
| `location.building` | `location_id` | Matched by name |
| `purchasing.purchase_date` | `purchase_date` | Date purchased |
| `purchasing.purchase_price` | `purchase_cost` | Purchase cost |
| `purchasing.po_number` | `order_number` | PO number |

### Categories

The sync automatically creates or uses these categories:

- **JAMF Computers**: For Mac computers
- **JAMF Mobile Devices**: For iPads and iPhones

You can override these by setting category IDs in `.env`.

## Monitoring

### View Logs

Monitor sync progress in real-time:

```bash
tail -f storage/logs/laravel.log | grep -i jamf
```

### Log Messages

You'll see entries like:

```
[INFO] Starting JAMF Pro to Snipe-IT database sync
[INFO] Found 247 computers in JAMF Pro
[INFO] Fetching detailed information for 247 computers...
[INFO] Found 89 mobile devices in JAMF Pro
[INFO] Total devices to sync: 336
[INFO] Dispatching 7 batch jobs (50 devices per batch)
[INFO] Processing JAMF batch #1 with 50 devices
[INFO] Created computer: Johns-MacBook-Pro (Serial: C02ABC123DEF)
[INFO] Updated mobile device: Johns-iPad (Serial: DMPABC123DEF)
[INFO] JAMF Batch #1 completed: 50 synced (12 created, 38 updated), 0 errors
```

### Queue Workers

Ensure queue workers are running to process batch jobs:

```bash
php artisan queue:work --queue=default
```

For production, use Laravel Horizon or Supervisor to manage workers.

## Performance

### Benchmarks

For 500 devices (mix of computers and mobile devices):

- **API Fetch Time**: ~8-12 minutes (depends on JAMF server response)
- **Database Sync Time**: ~2-3 minutes
- **Total Time**: ~10-15 minutes

### Optimization Tips

1. **Increase Chunk Size**: For faster syncs, increase `JAMF_SYNC_CHUNK_SIZE` to 100
2. **Run Multiple Workers**: Process batches in parallel with multiple queue workers
3. **Cache Configuration**: Ensure cache driver is set to `redis` or `memcached` (not `file`)
4. **Database Indexes**: Ensure indexes exist on `assets.serial`, `users.email`, `locations.name`

## Troubleshooting

### No devices syncing

**Cause**: Missing or incorrect JAMF credentials

**Solution**:
- Verify `JAMF_URL`, `JAMF_USERNAME`, `JAMF_PASSWORD` in `.env`
- Test API access: `curl -u username:password https://yourcompany.jamfcloud.com/JSSResource/computers`
- Check JAMF user has correct permissions

### API timeout errors

**Cause**: Large JAMF instance with slow API responses

**Solution**:
- Increase timeout in job: Edit `SyncJamfDevicesToDatabase.php`, line 23: `public $timeout = 1200;`
- Contact JAMF support about API performance

### Assets not assigned to users

**Cause**: User matching not enabled or emails don't match

**Solution**:
- Set `JAMF_AUTO_ASSIGN_USERS=true` in `.env`
- Ensure users exist in Snipe-IT with matching email addresses
- Check JAMF user records have email addresses populated
- Review logs for "No user found for email" messages

### Location not set

**Cause**: JAMF location name doesn't match Snipe-IT location

**Solution**:
- Ensure location names in JAMF match exactly with Snipe-IT (case-sensitive)
- Or set `JAMF_DEFAULT_LOCATION_ID` in `.env` as fallback
- Create locations in Snipe-IT that match JAMF building names

### Duplicate assets created

**Cause**: Serial number changed or not unique

**Solution**:
- Assets are matched by serial number (primary key)
- If device has no serial, it's skipped
- Check logs for "has no serial number, skipping" messages

### Purchase data not syncing

**Cause**: Purchase data not populated in JAMF

**Solution**:
- Verify purchasing data exists in JAMF Pro for devices
- Navigate to device → Purchasing in JAMF web console
- Populate: Purchase Date, Purchase Price, PO Number

## Differences from Intune Sync

| Feature | JAMF Sync | Intune Sync |
|---------|-----------|-------------|
| **Device Types** | Computers + Mobile Devices | All managed devices |
| **Manufacturer** | Always "Apple" | Variable (Dell, HP, etc.) |
| **Categories** | Separate for computers/mobile | Single "Intune Devices" |
| **API Auth** | Basic Auth | OAuth 2.0 Bearer Token |
| **Location Matching** | By name (building) | Not supported |
| **Purchase Data** | Fully supported | Not available |
| **API Pagination** | Individual detail calls | OData pagination |

## API Reference

### JAMF Pro Classic API Endpoints Used

- **Computers List**: `GET /JSSResource/computers`
- **Computer Detail**: `GET /JSSResource/computers/id/{id}`
- **Mobile Devices List**: `GET /JSSResource/mobiledevices`
- **Mobile Device Detail**: `GET /JSSResource/mobiledevices/id/{id}`

### Authentication

```bash
# Example API call
curl -u username:password \
  -H "Accept: application/json" \
  https://yourcompany.jamfcloud.com/JSSResource/computers
```

## Advanced Configuration

### Sync Only Computers

```env
JAMF_SYNC_COMPUTERS=true
JAMF_SYNC_MOBILE_DEVICES=false
```

### Sync Only Mobile Devices

```env
JAMF_SYNC_COMPUTERS=false
JAMF_SYNC_MOBILE_DEVICES=true
```

### Use Specific Categories

```env
JAMF_DEFAULT_CATEGORY_ID_COMPUTERS=5
JAMF_DEFAULT_CATEGORY_ID_MOBILE=6
```

### Custom Status Label

```env
JAMF_DEFAULT_STATUS_ID=3  # Use status ID 3 instead of default
```

## Security Best Practices

1. **Dedicated API User**: Create a separate JAMF user only for API access
2. **Least Privilege**: Grant only Read permissions (no Create/Update/Delete)
3. **Strong Password**: Use a complex password and rotate regularly
4. **Environment Variables**: Never commit `.env` file to version control
5. **HTTPS Only**: Ensure `JAMF_URL` uses `https://`
6. **Audit Logs**: Regularly review JAMF API access logs

## Support

### Common Issues

- **401 Unauthorized**: Check username/password in `.env`
- **403 Forbidden**: Verify API user has required permissions
- **404 Not Found**: Check `JAMF_URL` is correct (include full URL, no trailing slash)
- **500 Server Error**: JAMF server issue, contact JAMF support
- **Timeout**: Increase job timeout or reduce chunk size

### Getting Help

1. Check Laravel logs: `storage/logs/laravel.log`
2. Enable debug mode: `APP_DEBUG=true` in `.env` (temporarily)
3. Test API manually with curl
4. Review JAMF Pro logs in web console
5. Check queue status: `php artisan queue:failed`

## Migration from Manual Sync

If you're currently manually importing JAMF devices:

1. **Test First**: Run sync on a test/staging Snipe-IT instance
2. **Backup Database**: `php artisan backup:run`
3. **Run Initial Sync**: `dispatch(new App\Jobs\SyncJamfDevicesToDatabase);`
4. **Verify Data**: Check assets in Snipe-IT web interface
5. **Schedule Regular Syncs**: Add to `Kernel.php` schedule
6. **Disable Manual Process**: Once confident, stop manual imports

## License

This JAMF sync is part of your Snipe-IT installation and follows the same license terms.

## Version History

- **v1.0** (2025-01-06): Initial release
  - Support for computers and mobile devices
  - Direct database integration
  - Chunked processing
  - User assignment
  - Location matching
  - Purchase data sync

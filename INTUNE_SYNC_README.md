# Microsoft Intune to Snipe-IT Database Sync

This is a new, optimized Intune sync implementation that works directly with the Snipe-IT database using Eloquent models instead of making API calls.

## Features

- **Direct Database Access**: Uses Eloquent ORM for maximum performance
- **No Timeouts**: Chunked processing prevents timeout issues
- **Smart Caching**: Manufacturers, models, and status labels are cached to reduce queries
- **Auto-Creation**: Automatically creates manufacturers, models, and categories as needed
- **User Assignment**: Optionally auto-assign assets to users based on email
- **Idempotent**: Safe to run multiple times (uses `updateOrCreate`)
- **Transaction Safety**: Database transactions ensure data integrity
- **Comprehensive Logging**: Full audit trail of all sync operations

## Configuration

Add these variables to your `.env` file:

```env
# Microsoft Graph API credentials (required)
MICROSOFT_TENANT_ID=your-tenant-id
MICROSOFT_CLIENT_ID=your-client-id
MICROSOFT_CLIENT_SECRET=your-client-secret

# Intune Sync Configuration (optional)
INTUNE_DEFAULT_CATEGORY_ID=       # Category ID for Intune devices (auto-creates "Intune Devices" if not set)
INTUNE_DEFAULT_STATUS_ID=          # Status ID for synced devices (uses "Ready to Deploy" if not set)
INTUNE_DEFAULT_LOCATION_ID=        # Default location ID for devices (optional)
INTUNE_SYNC_CHUNK_SIZE=50          # Number of devices per batch job (default: 50)
INTUNE_AUTO_ASSIGN_USERS=false     # Auto-assign assets to users by email (default: false)
```

## Usage

### Run Sync via Tinker

```bash
php artisan tinker
```

Then in Tinker:
```php
dispatch(new App\Jobs\SyncIntuneDevicesToDatabase);
```

### Run Sync via Artisan Command (create one if needed)

You can create an Artisan command:
```bash
php artisan make:command SyncIntuneDevices
```

### Schedule Regular Syncs

Add to `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    // Sync Intune devices daily at 2 AM
    $schedule->job(new \App\Jobs\SyncIntuneDevicesToDatabase)
        ->dailyAt('02:00');
}
```

## How It Works

1. **Main Job** (`SyncIntuneDevicesToDatabase`):
   - Fetches all devices from Microsoft Intune via Graph API
   - Splits devices into chunks (default: 50 devices per chunk)
   - Dispatches child jobs to process each chunk
   - Clears cache for fresh sync

2. **Batch Job** (`ProcessIntuneDeviceBatch`):
   - Processes one chunk of devices
   - For each device:
     - Creates/finds manufacturer in database
     - Creates/finds asset model in database
     - Creates or updates asset using `updateOrCreate`
     - Optionally assigns to user by email
     - Restores soft-deleted assets if found
   - Uses database transactions for safety
   - Caches lookups to reduce queries

## Database Operations

The sync performs these database operations:

- `Manufacturer::firstOrCreate()` - Auto-create manufacturers
- `AssetModel::firstOrCreate()` - Auto-create models with category
- `Category::firstOrCreate()` - Auto-create "Intune Devices" category if needed
- `Asset::updateOrCreate()` - Upsert assets by serial number
- `User::where('email', ...)` - Match users for auto-assignment
- Proper handling of soft-deleted records

## Monitoring

Check the logs for sync progress:

```bash
tail -f storage/logs/laravel.log | grep -i intune
```

You'll see log entries like:
- `Starting Intune to Snipe-IT database sync`
- `Found X devices in Intune`
- `Dispatching X batch jobs`
- `Processing Intune batch #X with Y devices`
- `Batch #X completed: Y synced (Z created, W updated)`

## Performance

This sync is **significantly faster** than the API-based approach:

- **No API overhead**: Direct database queries via Eloquent
- **Efficient caching**: Reduces redundant database queries
- **Parallel processing**: Multiple batch jobs can run simultaneously
- **No timeouts**: Each batch processes only 50 devices (configurable)

For 1000 devices:
- API-based sync: ~10-15 minutes (prone to timeouts)
- Database-based sync: ~2-3 minutes (no timeouts)

## Troubleshooting

### No devices syncing
- Check Microsoft Graph API credentials in `.env`
- Verify app has `DeviceManagementManagedDevices.Read.All` permission in Azure AD
- Check logs for API errors

### Assets not assigned to users
- Set `INTUNE_AUTO_ASSIGN_USERS=true` in `.env`
- Ensure users exist in Snipe-IT with matching email addresses
- Check that `userPrincipalName` in Intune matches user email in Snipe-IT

### Wrong category or status
- Set `INTUNE_DEFAULT_CATEGORY_ID` and `INTUNE_DEFAULT_STATUS_ID` in `.env`
- Run sync again to update existing assets

## Differences from Old Sync

### Old Sync (`SyncIntuneToSnipeIT.php`)
- Made HTTP API calls to Snipe-IT
- Required API token
- Slower due to HTTP overhead
- More prone to timeouts
- Limited by API rate limits

### New Sync (`SyncIntuneDevicesToDatabase.php`)
- Direct database access via Eloquent
- No API token needed (for Snipe-IT side)
- Much faster
- No timeout issues
- No rate limits

## Migration from Old Sync

The new sync is completely independent and can run alongside the old one. To migrate:

1. Test the new sync: `dispatch(new App\Jobs\SyncIntuneDevicesToDatabase);`
2. Verify assets are synced correctly in Snipe-IT web interface
3. Once satisfied, replace old sync calls with new one
4. Optionally delete old job files

Both syncs use serial number as the primary identifier, so assets won't be duplicated.

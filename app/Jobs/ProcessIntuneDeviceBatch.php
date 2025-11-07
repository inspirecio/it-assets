<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessIntuneDeviceBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes per batch

    protected $devices;
    protected $batchNumber;

    public function __construct($devices, $batchNumber = 0)
    {
        $this->devices = $devices;
        $this->batchNumber = $batchNumber;
    }

    public function handle()
    {
        Log::info("Processing Intune batch #{$this->batchNumber} with " . count($this->devices) . " devices");

        $synced = 0;
        $updated = 0;
        $created = 0;
        $errors = 0;

        foreach ($this->devices as $device) {
            try {
                $result = $this->syncDeviceToDatabase($device);

                if ($result) {
                    $synced++;
                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                } else {
                    $errors++;
                }
            } catch (\Exception $e) {
                Log::error("Error syncing device {$device['deviceName']}: " . $e->getMessage());
                $errors++;
            }
        }

        Log::info("Batch #{$this->batchNumber} completed: {$synced} synced ({$created} created, {$updated} updated), {$errors} errors");
    }

    /**
     * Sync a single device to Snipe-IT database
     *
     * @return string|false 'created', 'updated', or false on failure
     */
    private function syncDeviceToDatabase($device)
    {
        // Extract device information
        $serialNumber = $device['serialNumber'] ?? null;
        $deviceName = $device['deviceName'] ?? 'Unknown Device';
        $modelName = $device['model'] ?? 'Unknown Model';
        $manufacturerName = $device['manufacturer'] ?? 'Unknown';
        $operatingSystem = $device['operatingSystem'] ?? '';
        $osVersion = $device['osVersion'] ?? '';
        $managedDeviceOwnerType = $device['managedDeviceOwnerType'] ?? '';
        $enrolledDateTime = $device['enrolledDateTime'] ?? null;
        $lastSyncDateTime = $device['lastSyncDateTime'] ?? null;
        $userPrincipalName = $device['userPrincipalName'] ?? null;

        // Validate serial number
        if (!$serialNumber) {
            Log::warning("Device {$deviceName} has no serial number, skipping");
            return false;
        }

        // Use database transaction for data integrity
        return DB::transaction(function () use (
            $serialNumber, $deviceName, $modelName, $manufacturerName,
            $operatingSystem, $osVersion, $managedDeviceOwnerType,
            $enrolledDateTime, $lastSyncDateTime, $userPrincipalName
        ) {
            // Get or create manufacturer
            $manufacturer = $this->getOrCreateManufacturer($manufacturerName);

            // Get or create model
            $model = $this->getOrCreateModel($modelName, $manufacturer->id);

            // Get default status
            $statusId = $this->getDefaultStatusId();

            // Get location (if configured)
            $locationId = config('snipeit.intune_default_location_id', null);

            // Find user by email if configured and available
            $assignedTo = null;
            if (config('snipeit.intune_auto_assign_users', false) && $userPrincipalName) {
                $user = User::where('email', $userPrincipalName)->first();
                if ($user) {
                    $assignedTo = $user->id;
                }
            }

            // Prepare notes
            $notes = "Synced from Microsoft Intune\n";
            $notes .= "OS: {$operatingSystem} {$osVersion}\n";
            $notes .= "Owner Type: {$managedDeviceOwnerType}\n";
            $notes .= "Enrolled: {$enrolledDateTime}\n";
            $notes .= "Last Sync: {$lastSyncDateTime}";
            if ($userPrincipalName) {
                $notes .= "\nUser: {$userPrincipalName}";
            }

            // Check if asset exists
            $existingAsset = Asset::where('serial', $serialNumber)
                ->withTrashed()
                ->first();

            $wasCreated = !$existingAsset;

            // Create or update asset
            $asset = Asset::updateOrCreate(
                ['serial' => $serialNumber],
                [
                    'asset_tag' => $serialNumber, // Using serial as asset tag
                    'name' => $deviceName,
                    'model_id' => $model->id,
                    'status_id' => $statusId,
                    'notes' => $notes,
                    'location_id' => $locationId,
                    'assigned_to' => $assignedTo,
                    'assigned_type' => $assignedTo ? User::class : null,
                ]
            );

            // Restore if soft-deleted
            if ($existingAsset && $existingAsset->trashed()) {
                $asset->restore();
                Log::info("Restored soft-deleted asset: {$deviceName} (Serial: {$serialNumber})");
            }

            if ($wasCreated) {
                Log::info("Created asset: {$deviceName} (Serial: {$serialNumber})");
                return 'created';
            } else {
                Log::info("Updated asset: {$deviceName} (Serial: {$serialNumber})");
                return 'updated';
            }
        });
    }

    /**
     * Get or create manufacturer (with caching)
     */
    private function getOrCreateManufacturer($manufacturerName)
    {
        $cacheKey = 'intune_manufacturer_' . strtolower(str_replace(' ', '_', $manufacturerName));

        return Cache::remember($cacheKey, 3600, function () use ($manufacturerName) {
            return Manufacturer::firstOrCreate(
                ['name' => $manufacturerName],
                [
                    'name' => $manufacturerName,
                    'image' => null,
                ]
            );
        });
    }

    /**
     * Get or create asset model (with caching)
     */
    private function getOrCreateModel($modelName, $manufacturerId)
    {
        $cacheKey = 'intune_model_' . strtolower(str_replace(' ', '_', $modelName)) . '_' . $manufacturerId;

        return Cache::remember($cacheKey, 3600, function () use ($modelName, $manufacturerId) {
            // Get default category for Intune devices
            $categoryId = $this->getIntuneCategory();

            return AssetModel::firstOrCreate(
                [
                    'name' => $modelName,
                    'manufacturer_id' => $manufacturerId,
                ],
                [
                    'name' => $modelName,
                    'manufacturer_id' => $manufacturerId,
                    'category_id' => $categoryId,
                    'model_number' => null,
                ]
            );
        });
    }

    /**
     * Get or create the Intune category
     */
    private function getIntuneCategory()
    {
        return Cache::remember('intune_category', 3600, function () {
            $categoryId = config('snipeit.intune_default_category_id');

            // If configured category ID exists, use it
            if ($categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    return $category->id;
                }
            }

            // Otherwise, create or find "Intune Devices" category
            $category = Category::firstOrCreate(
                ['name' => 'Intune Devices'],
                [
                    'name' => 'Intune Devices',
                    'category_type' => 'asset',
                ]
            );

            return $category->id;
        });
    }

    /**
     * Get default status label ID
     */
    private function getDefaultStatusId()
    {
        return Cache::remember('intune_default_status', 3600, function () {
            $statusId = config('snipeit.intune_default_status_id');

            // If configured status ID exists, use it
            if ($statusId) {
                $status = Statuslabel::find($statusId);
                if ($status) {
                    return $status->id;
                }
            }

            // Otherwise, try to find "Ready to Deploy" status
            $status = Statuslabel::where('name', 'Ready to Deploy')->first();

            if ($status) {
                return $status->id;
            }

            // Fallback to first deployable status
            $status = Statuslabel::where('deployable', 1)->first();

            if ($status) {
                return $status->id;
            }

            // Last resort: return ID 1
            Log::warning('No suitable status label found, using default ID 1');
            return 1;
        });
    }
}

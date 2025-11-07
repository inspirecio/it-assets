<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Location;
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

class ProcessJamfDeviceBatch implements ShouldQueue
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
        Log::info("Processing JAMF batch #{$this->batchNumber} with " . count($this->devices) . " devices");

        $synced = 0;
        $updated = 0;
        $created = 0;
        $errors = 0;

        foreach ($this->devices as $device) {
            try {
                $result = $this->syncDeviceToDatabase($device);

                if ($result) {
                    $synced++;
                    $status = $result['status'] ?? null;
                    if ($status === 'created') {
                        $created++;
                    } elseif ($status === 'updated') {
                        $updated++;
                    }
                } else {
                    $errors++;
                }
            } catch (\Exception $e) {
                $deviceName = $this->getDeviceName($device);
                Log::error("Error syncing device {$deviceName}: " . $e->getMessage());
                Log::error($e->getTraceAsString());
                $errors++;
            }
        }

        Log::info("JAMF Batch #{$this->batchNumber} completed: {$synced} synced ({$created} created, {$updated} updated), {$errors} errors");
    }

    /**
     * Sync a single device to Snipe-IT database
     *
     * @return string|false 'created', 'updated', or false on failure
     */
    private function syncDeviceToDatabase($device)
    {
        $deviceType = $device['_device_type'] ?? 'computer';

        return $deviceType === 'computer'
            ? $this->syncComputer($device)
            : $this->syncMobileDevice($device);
    }

    /**
     * Sync a JAMF computer to database
     */
    private function syncComputer($device)
    {
        $general = $device['general'] ?? [];
        $hardware = $device['hardware'] ?? [];
        $location = $device['location'] ?? [];
        $purchasing = $device['purchasing'] ?? [];

        // Extract computer information
        $serialNumber = $general['serial_number'] ?? null;
        $deviceName = $general['name'] ?? 'Unknown Computer';
        $modelName = $hardware['model'] ?? ($hardware['model_identifier'] ?? 'Unknown Model');
        $manufacturerName = 'Apple'; // JAMF only manages Apple devices
        $osVersion = $hardware['os_version'] ?? ($general['platform'] ?? '');
        $userEmail = $location['email_address'] ?? null;
        $userName = $location['username'] ?? null;
        $locationName = $location['building'] ?? null;
        $purchaseDate = $purchasing['po_date'] ?? ($purchasing['purchase_date'] ?? null);
        $purchaseCost = $purchasing['purchase_price'] ?? null;
        $poNumber = $purchasing['po_number'] ?? null;

        // Validate serial number
        if (!$serialNumber) {
            Log::warning("Computer {$deviceName} has no serial number, skipping");
            return false;
        }

        // Use database transaction for data integrity
        return DB::transaction(function () use (
            $serialNumber, $deviceName, $modelName, $manufacturerName,
            $osVersion, $userEmail, $userName, $locationName,
            $purchaseDate, $purchaseCost, $poNumber
        ) {
            // Get or create manufacturer
            $manufacturer = $this->getOrCreateManufacturer($manufacturerName);

            // Get or create model
            $model = $this->getOrCreateModel($modelName, $manufacturer->id, 'computer');

            // Get default status
            $statusId = $this->getDefaultStatusId();

            // Get location by name if available
            $locationId = $this->getLocationByName($locationName) ?? config('snipeit.jamf_default_location_id', null);

            // Find user by email if configured and available
            $assignedTo = null;
            if (config('snipeit.jamf_auto_assign_users', false)) {
                if ($userEmail) {
                    $user = User::where('email', $userEmail)->first();
                    if ($user) {
                        $assignedTo = $user->id;
                    }
                } elseif ($userName) {
                    // Try username if email not available
                    $user = User::where('username', $userName)->first();
                    if ($user) {
                        $assignedTo = $user->id;
                    }
                }
            }

            // Prepare notes
            $notes = "Synced from JAMF Pro\n";
            $notes .= "Device Type: Computer\n";
            $notes .= "OS Version: {$osVersion}\n";
            if ($userEmail) {
                $notes .= "User Email: {$userEmail}\n";
            }
            if ($userName) {
                $notes .= "Username: {$userName}\n";
            }
            if ($locationName) {
                $notes .= "JAMF Location: {$locationName}\n";
            }

            // Check if asset exists
            $existingAsset = Asset::where('serial', $serialNumber)
                ->withTrashed()
                ->first();

            $wasCreated = !$existingAsset;

            // Prepare purchase date
            $formattedPurchaseDate = null;
            if ($purchaseDate) {
                try {
                    $formattedPurchaseDate = date('Y-m-d', strtotime($purchaseDate));
                } catch (\Exception $e) {
                    Log::warning("Invalid purchase date format: {$purchaseDate}");
                }
            }

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
                    'purchase_date' => $formattedPurchaseDate,
                    'purchase_cost' => $purchaseCost ? (float)str_replace(['$', ','], '', $purchaseCost) : null,
                    'order_number' => $poNumber,
                ]
            );

            // Restore if soft-deleted
            if ($existingAsset && $existingAsset->trashed()) {
                $asset->restore();
                Log::info("Restored soft-deleted asset: {$deviceName} (Serial: {$serialNumber})");
            }

            $result = [
                'status' => $wasCreated ? 'created' : 'updated',
                'asset' => $asset->fresh(),
            ];

            if ($wasCreated) {
                Log::info("Created computer: {$deviceName} (Serial: {$serialNumber})");
            } else {
                Log::info("Updated computer: {$deviceName} (Serial: {$serialNumber})");
            }

            return $result;
        });
    }

    /**
     * Sync a JAMF mobile device to database
     */
    private function syncMobileDevice($device)
    {
        $general = $device['general'] ?? [];
        $location = $device['location'] ?? [];
        $purchasing = $device['purchasing'] ?? [];

        // Extract mobile device information
        $serialNumber = $general['serial_number'] ?? null;
        $deviceName = $general['name'] ?? ($general['display_name'] ?? 'Unknown Mobile Device');
        $modelName = $general['model'] ?? ($general['model_identifier'] ?? 'Unknown Model');
        $manufacturerName = 'Apple'; // JAMF only manages Apple devices
        $osVersion = $general['os_version'] ?? '';
        $capacity = $general['capacity'] ?? '';
        $userEmail = $location['email_address'] ?? null;
        $userName = $location['username'] ?? null;
        $locationName = $location['building'] ?? null;
        $purchaseDate = $purchasing['po_date'] ?? ($purchasing['purchase_date'] ?? null);
        $purchaseCost = $purchasing['purchase_price'] ?? null;
        $poNumber = $purchasing['po_number'] ?? null;

        // Validate serial number
        if (!$serialNumber) {
            Log::warning("Mobile device {$deviceName} has no serial number, skipping");
            return false;
        }

        // Use database transaction for data integrity
        return DB::transaction(function () use (
            $serialNumber, $deviceName, $modelName, $manufacturerName,
            $osVersion, $capacity, $userEmail, $userName, $locationName,
            $purchaseDate, $purchaseCost, $poNumber
        ) {
            // Get or create manufacturer
            $manufacturer = $this->getOrCreateManufacturer($manufacturerName);

            // Get or create model
            $model = $this->getOrCreateModel($modelName, $manufacturer->id, 'mobile_device');

            // Get default status
            $statusId = $this->getDefaultStatusId();

            // Get location by name if available
            $locationId = $this->getLocationByName($locationName) ?? config('snipeit.jamf_default_location_id', null);

            // Find user by email if configured and available
            $assignedTo = null;
            if (config('snipeit.jamf_auto_assign_users', false)) {
                if ($userEmail) {
                    $user = User::where('email', $userEmail)->first();
                    if ($user) {
                        $assignedTo = $user->id;
                    }
                } elseif ($userName) {
                    $user = User::where('username', $userName)->first();
                    if ($user) {
                        $assignedTo = $user->id;
                    }
                }
            }

            // Prepare notes
            $notes = "Synced from JAMF Pro\n";
            $notes .= "Device Type: Mobile Device\n";
            $notes .= "OS Version: {$osVersion}\n";
            if ($capacity) {
                $notes .= "Capacity: {$capacity}\n";
            }
            if ($userEmail) {
                $notes .= "User Email: {$userEmail}\n";
            }
            if ($userName) {
                $notes .= "Username: {$userName}\n";
            }
            if ($locationName) {
                $notes .= "JAMF Location: {$locationName}\n";
            }

            // Check if asset exists
            $existingAsset = Asset::where('serial', $serialNumber)
                ->withTrashed()
                ->first();

            $wasCreated = !$existingAsset;

            // Prepare purchase date
            $formattedPurchaseDate = null;
            if ($purchaseDate) {
                try {
                    $formattedPurchaseDate = date('Y-m-d', strtotime($purchaseDate));
                } catch (\Exception $e) {
                    Log::warning("Invalid purchase date format: {$purchaseDate}");
                }
            }

            // Create or update asset
            $asset = Asset::updateOrCreate(
                ['serial' => $serialNumber],
                [
                    'asset_tag' => $serialNumber,
                    'name' => $deviceName,
                    'model_id' => $model->id,
                    'status_id' => $statusId,
                    'notes' => $notes,
                    'location_id' => $locationId,
                    'assigned_to' => $assignedTo,
                    'assigned_type' => $assignedTo ? User::class : null,
                    'purchase_date' => $formattedPurchaseDate,
                    'purchase_cost' => $purchaseCost ? (float)str_replace(['$', ','], '', $purchaseCost) : null,
                    'order_number' => $poNumber,
                ]
            );

            // Restore if soft-deleted
            if ($existingAsset && $existingAsset->trashed()) {
                $asset->restore();
                Log::info("Restored soft-deleted mobile device: {$deviceName} (Serial: {$serialNumber})");
            }

            $result = [
                'status' => $wasCreated ? 'created' : 'updated',
                'asset' => $asset->fresh(),
            ];

            if ($wasCreated) {
                Log::info("Created mobile device: {$deviceName} (Serial: {$serialNumber})");
            } else {
                Log::info("Updated mobile device: {$deviceName} (Serial: {$serialNumber})");
            }

            return $result;
        });
    }


    /**
     * Get device name from device data
     */
    private function getDeviceName($device)
    {
        $general = $device['general'] ?? [];
        return $general['name'] ?? ($general['display_name'] ?? 'Unknown Device');
    }

    /**
     * Get or create manufacturer (with caching)
     */
    private function getOrCreateManufacturer($manufacturerName)
    {
        $cacheKey = 'jamf_manufacturer_' . strtolower(str_replace(' ', '_', $manufacturerName));

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
    private function getOrCreateModel($modelName, $manufacturerId, $deviceType)
    {
        $cacheKey = 'jamf_model_' . strtolower(str_replace(' ', '_', $modelName)) . '_' . $manufacturerId;

        return Cache::remember($cacheKey, 3600, function () use ($modelName, $manufacturerId, $deviceType) {
            // Get default category for JAMF devices
            $categoryId = $this->getJamfCategory($deviceType);

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
     * Get or create the JAMF category based on device type
     */
    private function getJamfCategory($deviceType)
    {
        $cacheKey = 'jamf_category_' . $deviceType;

        return Cache::remember($cacheKey, 3600, function () use ($deviceType) {
            if ($deviceType === 'computer') {
                $categoryId = config('snipeit.jamf_default_category_id_computers');
                $categoryName = 'JAMF Computers';
            } else {
                $categoryId = config('snipeit.jamf_default_category_id_mobile');
                $categoryName = 'JAMF Mobile Devices';
            }

            // If configured category ID exists, use it
            if ($categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    return $category->id;
                }
            }

            // Otherwise, create or find category
            $category = Category::firstOrCreate(
                ['name' => $categoryName],
                [
                    'name' => $categoryName,
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
        return Cache::remember('jamf_default_status', 3600, function () {
            $statusId = config('snipeit.jamf_default_status_id');

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

    /**
     * Get location by name (with caching)
     */
    private function getLocationByName($locationName)
    {
        if (!$locationName) {
            return null;
        }

        $cacheKey = 'jamf_location_' . strtolower(str_replace(' ', '_', $locationName));

        return Cache::remember($cacheKey, 3600, function () use ($locationName) {
            $location = Location::where('name', $locationName)->first();
            return $location ? $location->id : null;
        });
    }
}

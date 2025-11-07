<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessIntuneDeviceChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes per chunk

    protected $devices;
    protected $chunkNumber;

    public function __construct($devices, $chunkNumber = 0)
    {
        $this->devices = $devices;
        $this->chunkNumber = $chunkNumber;
    }

    public function handle()
    {
        Log::info("Processing chunk #{$this->chunkNumber} with " . count($this->devices) . " devices");

        $synced = 0;
        $errors = 0;

        foreach ($this->devices as $device) {
            if ($this->syncDeviceToSnipeIT($device)) {
                $synced++;
            } else {
                $errors++;
            }
        }

        Log::info("Chunk #{$this->chunkNumber} completed: {$synced} synced, {$errors} errors");
    }

    /**
     * Sync a single device to Snipe-IT
     */
    private function syncDeviceToSnipeIT($device)
    {
        try {
            // Extract relevant data from Intune device
            $serialNumber = $device['serialNumber'] ?? null;
            $deviceName = $device['deviceName'] ?? 'Unknown Device';
            $model = $device['model'] ?? 'Unknown Model';
            $manufacturer = $device['manufacturer'] ?? 'Unknown';
            $operatingSystem = $device['operatingSystem'] ?? '';
            $osVersion = $device['osVersion'] ?? '';
            $managedDeviceOwnerType = $device['managedDeviceOwnerType'] ?? '';
            $enrolledDateTime = $device['enrolledDateTime'] ?? null;
            $lastSyncDateTime = $device['lastSyncDateTime'] ?? null;
            $userPrincipalName = $device['userPrincipalName'] ?? null;

            if (!$serialNumber) {
                Log::warning("Device {$deviceName} has no serial number, skipping");
                return false;
            }

            // Check if asset exists in Snipe-IT
            $existingAsset = $this->findSnipeITAssetBySerial($serialNumber);

            // Get or create model ID in Snipe-IT
            $modelId = $this->getOrCreateSnipeITModel($model, $manufacturer);

            // Prepare asset data
            $assetData = [
                'asset_tag' => $serialNumber, // Using serial as asset tag, adjust as needed
                'serial' => $serialNumber,
                'model_id' => $modelId,
                'name' => $deviceName,
                'status_id' => config('snipeit.default_status_id', 2), // 2 is typically "Ready to Deploy"
                'notes' => "Synced from Intune\nOS: {$operatingSystem} {$osVersion}\nOwner Type: {$managedDeviceOwnerType}\nLast Sync: {$lastSyncDateTime}",
            ];

            // Add custom fields if configured
            if ($userPrincipalName) {
                $assetData['_snipeit_intune_user'] = $userPrincipalName;
            }

            if ($existingAsset) {
                // Update existing asset
                return $this->updateSnipeITAsset($existingAsset['id'], $assetData);
            } else {
                // Create new asset
                return $this->createSnipeITAsset($assetData);
            }

        } catch (\Exception $e) {
            Log::error("Failed to sync device {$device['deviceName']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find asset in Snipe-IT by serial number
     */
    private function findSnipeITAssetBySerial($serialNumber)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
            'Accept' => 'application/json',
        ])->get(config('services.snipeit.url') . '/api/v1/hardware/byserial/' . $serialNumber);

        if ($response->successful() && !empty($response->json()['rows'])) {
            return $response->json()['rows'][0];
        }

        return null;
    }

    /**
     * Get or create model in Snipe-IT (with caching)
     */
    private function getOrCreateSnipeITModel($modelName, $manufacturer)
    {
        $cacheKey = 'snipeit_model_' . strtolower($modelName);

        // Check cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Get all models and cache them
        $models = Cache::remember('snipeit_models', 3600, function () {
            $allModels = [];
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
                'Accept' => 'application/json',
            ])->get(config('services.snipeit.url') . '/api/v1/models', [
                'limit' => 500,
            ]);

            if ($response->successful()) {
                $allModels = $response->json()['rows'] ?? [];
            }
            return $allModels;
        });

        // Search in cached models
        foreach ($models as $model) {
            if (strtolower($model['name']) === strtolower($modelName)) {
                Cache::put($cacheKey, $model['id'], 3600);
                return $model['id'];
            }
        }

        // Model not found, create it
        $manufacturerId = $this->getOrCreateManufacturer($manufacturer);
        $categoryId = config('snipeit.default_category_id', 1);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
            'Accept' => 'application/json',
        ])->post(config('services.snipeit.url') . '/api/v1/models', [
            'name' => $modelName,
            'manufacturer_id' => $manufacturerId,
            'category_id' => $categoryId,
        ]);

        if ($response->successful()) {
            $modelId = $response->json()['payload']['id'];
            Cache::put($cacheKey, $modelId, 3600);
            Cache::forget('snipeit_models'); // Invalidate the models cache
            return $modelId;
        }

        // Fallback to default model ID if creation fails
        Log::warning("Failed to create model {$modelName}, using default");
        return config('snipeit.default_model_id', 1);
    }

    /**
     * Get or create manufacturer in Snipe-IT (with caching)
     */
    private function getOrCreateManufacturer($manufacturerName)
    {
        $cacheKey = 'snipeit_manufacturer_' . strtolower($manufacturerName);

        // Check cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Get all manufacturers and cache them
        $manufacturers = Cache::remember('snipeit_manufacturers', 3600, function () {
            $allManufacturers = [];
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
                'Accept' => 'application/json',
            ])->get(config('services.snipeit.url') . '/api/v1/manufacturers', [
                'limit' => 500,
            ]);

            if ($response->successful()) {
                $allManufacturers = $response->json()['rows'] ?? [];
            }
            return $allManufacturers;
        });

        // Search in cached manufacturers
        foreach ($manufacturers as $manufacturer) {
            if (strtolower($manufacturer['name']) === strtolower($manufacturerName)) {
                Cache::put($cacheKey, $manufacturer['id'], 3600);
                return $manufacturer['id'];
            }
        }

        // Create manufacturer
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
            'Accept' => 'application/json',
        ])->post(config('services.snipeit.url') . '/api/v1/manufacturers', [
            'name' => $manufacturerName,
        ]);

        if ($response->successful()) {
            $manufacturerId = $response->json()['payload']['id'];
            Cache::put($cacheKey, $manufacturerId, 3600);
            Cache::forget('snipeit_manufacturers'); // Invalidate the manufacturers cache
            return $manufacturerId;
        }

        // Fallback to default
        return config('snipeit.default_manufacturer_id', 1);
    }

    /**
     * Create new asset in Snipe-IT
     */
    private function createSnipeITAsset($assetData)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
            'Accept' => 'application/json',
        ])->post(config('services.snipeit.url') . '/api/v1/hardware', $assetData);

        if ($response->successful()) {
            Log::info("Created asset: {$assetData['name']} (Serial: {$assetData['serial']})");
            return true;
        }

        Log::error("Failed to create asset: " . $response->body());
        return false;
    }

    /**
     * Update existing asset in Snipe-IT
     */
    private function updateSnipeITAsset($assetId, $assetData)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
            'Accept' => 'application/json',
        ])->patch(config('services.snipeit.url') . "/api/v1/hardware/{$assetId}", $assetData);

        if ($response->successful()) {
            Log::info("Updated asset ID {$assetId}: {$assetData['name']}");
            return true;
        }

        Log::error("Failed to update asset {$assetId}: " . $response->body());
        return false;
    }
}

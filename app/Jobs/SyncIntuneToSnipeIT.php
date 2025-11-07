<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncIntuneToSnipeIT implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout

    public function __construct()
    {
        //
    }

    public function handle()
    {
        Log::info('Starting Intune to Snipe-IT sync');

        try {
            // Step 1: Get Microsoft Graph access token
            $graphToken = $this->getGraphAccessToken();
            
            if (!$graphToken) {
                Log::error('Failed to get Microsoft Graph access token');
                return;
            }

            // Step 2: Fetch devices from Intune
            $intuneDevices = $this->getIntuneDevices($graphToken);
            
            if (empty($intuneDevices)) {
                Log::warning('No devices found in Intune');
                return;
            }

            Log::info('Found ' . count($intuneDevices) . ' devices in Intune');

            // Step 3: Sync each device to Snipe-IT
            $synced = 0;
            $errors = 0;

            foreach ($intuneDevices as $device) {
                if ($this->syncDeviceToSnipeIT($device)) {
                    $synced++;
                } else {
                    $errors++;
                }
            }

            Log::info("Sync completed: {$synced} synced, {$errors} errors");

        } catch (\Exception $e) {
            Log::error('Intune sync failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get Microsoft Graph API access token
     */
    private function getGraphAccessToken()
    {
        $response = Http::asForm()->post('https://login.microsoftonline.com/' . config('services.microsoft.tenant_id') . '/oauth2/v2.0/token', [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if ($response->successful()) {
            return $response->json()['access_token'];
        }

        Log::error('Graph token request failed: ' . $response->body());
        return null;
    }

    /**
     * Fetch managed devices from Intune via Microsoft Graph
     */
    private function getIntuneDevices($token)
    {
        $devices = [];
        $url = 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices';

        do {
            $response = Http::withToken($token)
                ->get($url);

            if (!$response->successful()) {
                Log::error('Failed to fetch Intune devices: ' . $response->body());
                break;
            }

            $data = $response->json();
            $devices = array_merge($devices, $data['value'] ?? []);

            // Handle pagination
            $url = $data['@odata.nextLink'] ?? null;

        } while ($url);

        return $devices;
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
     * Get or create model in Snipe-IT
     */
    private function getOrCreateSnipeITModel($modelName, $manufacturer)
    {
        // First, try to find existing model
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
            'Accept' => 'application/json',
        ])->get(config('services.snipeit.url') . '/api/v1/models', [
            'search' => $modelName,
        ]);

        if ($response->successful()) {
            $models = $response->json()['rows'] ?? [];
            foreach ($models as $model) {
                if (strtolower($model['name']) === strtolower($modelName)) {
                    return $model['id'];
                }
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
            return $response->json()['payload']['id'];
        }

        // Fallback to default model ID if creation fails
        Log::warning("Failed to create model {$modelName}, using default");
        return config('snipeit.default_model_id', 1);
    }

    /**
     * Get or create manufacturer in Snipe-IT
     */
    private function getOrCreateManufacturer($manufacturerName)
    {
        // Try to find existing manufacturer
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.snipeit.api_token'),
            'Accept' => 'application/json',
        ])->get(config('services.snipeit.url') . '/api/v1/manufacturers', [
            'search' => $manufacturerName,
        ]);

        if ($response->successful()) {
            $manufacturers = $response->json()['rows'] ?? [];
            foreach ($manufacturers as $manufacturer) {
                if (strtolower($manufacturer['name']) === strtolower($manufacturerName)) {
                    return $manufacturer['id'];
                }
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
            return $response->json()['payload']['id'];
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
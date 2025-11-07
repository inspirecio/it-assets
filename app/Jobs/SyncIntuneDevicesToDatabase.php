<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Manufacturer;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncIntuneDevicesToDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout

    public function __construct()
    {
        //
    }

    public function handle()
    {
        Log::info('Starting Intune to Snipe-IT database sync');

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

            // Step 3: Clear cache for fresh sync
            Cache::forget('intune_sync_manufacturers');
            Cache::forget('intune_sync_models');
            Cache::forget('intune_sync_statuslabels');

            // Step 4: Chunk devices and dispatch child jobs
            $chunkSize = config('snipeit.intune_sync_chunk_size', 50);
            $chunks = array_chunk($intuneDevices, $chunkSize);

            Log::info('Dispatching ' . count($chunks) . ' batch jobs (' . $chunkSize . ' devices per batch)');

            foreach ($chunks as $index => $chunk) {
                ProcessIntuneDeviceBatch::dispatch($chunk, $index + 1);
            }

            Log::info('All batch jobs dispatched successfully');

        } catch (\Exception $e) {
            Log::error('Intune database sync failed: ' . $e->getMessage());
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
}

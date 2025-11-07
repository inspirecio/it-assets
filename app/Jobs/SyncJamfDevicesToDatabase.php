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

class SyncJamfDevicesToDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout

    public function __construct()
    {
        //
    }

    public function handle()
    {
        Log::info('Starting JAMF Pro to Snipe-IT database sync');

        try {
            // Verify JAMF configuration
            if (!$this->verifyJamfConfig()) {
                Log::error('JAMF configuration missing. Please check JAMF_URL, JAMF_USERNAME, and JAMF_PASSWORD in .env');
                return;
            }

            $allDevices = [];

            // Step 1: Fetch computers from JAMF Pro
            if (config('snipeit.jamf_sync_computers', true)) {
                $computers = $this->getJamfComputers();
                if (!empty($computers)) {
                    Log::info('Found ' . count($computers) . ' computers in JAMF Pro');
                    $allDevices = array_merge($allDevices, $computers);
                }
            }

            // Step 2: Fetch mobile devices from JAMF Pro
            if (config('snipeit.jamf_sync_mobile_devices', true)) {
                $mobileDevices = $this->getJamfMobileDevices();
                if (!empty($mobileDevices)) {
                    Log::info('Found ' . count($mobileDevices) . ' mobile devices in JAMF Pro');
                    $allDevices = array_merge($allDevices, $mobileDevices);
                }
            }

            if (empty($allDevices)) {
                Log::warning('No devices found in JAMF Pro');
                return;
            }

            Log::info('Total devices to sync: ' . count($allDevices));

            // Step 3: Clear cache for fresh sync
            Cache::forget('jamf_sync_manufacturers');
            Cache::forget('jamf_sync_models');
            Cache::forget('jamf_sync_statuslabels');
            Cache::forget('jamf_sync_categories');

            // Step 4: Chunk devices and dispatch child jobs
            $chunkSize = config('snipeit.jamf_sync_chunk_size', 50);
            $chunks = array_chunk($allDevices, $chunkSize);

            Log::info('Dispatching ' . count($chunks) . ' batch jobs (' . $chunkSize . ' devices per batch)');

            foreach ($chunks as $index => $chunk) {
                ProcessJamfDeviceBatch::dispatch($chunk, $index + 1);
            }

            Log::info('All batch jobs dispatched successfully');

        } catch (\Exception $e) {
            Log::error('JAMF database sync failed: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Verify JAMF configuration is present
     */
    private function verifyJamfConfig()
    {
        return config('services.jamf.url') &&
               config('services.jamf.username') &&
               config('services.jamf.password');
    }

    /**
     * Get JAMF API credentials for HTTP requests
     */
    private function getJamfAuth()
    {
        return [
            config('services.jamf.username'),
            config('services.jamf.password')
        ];
    }

    /**
     * Fetch computers from JAMF Pro
     */
    private function getJamfComputers()
    {
        try {
            $baseUrl = rtrim(config('services.jamf.url'), '/');
            $url = $baseUrl . '/JSSResource/computers';

            // Get basic list of computers
            $response = Http::withBasicAuth(...$this->getJamfAuth())
                ->accept('application/json')
                ->get($url);

            if (!$response->successful()) {
                Log::error('Failed to fetch JAMF computers: ' . $response->status() . ' - ' . $response->body());
                return [];
            }

            $data = $response->json();
            $computersList = $data['computers'] ?? [];

            if (empty($computersList)) {
                return [];
            }

            Log::info('Fetching detailed information for ' . count($computersList) . ' computers...');

            $computers = [];

            // Fetch detailed information for each computer
            foreach ($computersList as $computer) {
                $computerId = $computer['id'];
                $detailResponse = Http::withBasicAuth(...$this->getJamfAuth())
                    ->accept('application/json')
                    ->timeout(30)
                    ->get($baseUrl . '/JSSResource/computers/id/' . $computerId);

                if ($detailResponse->successful()) {
                    $detailData = $detailResponse->json();
                    $computerDetail = $detailData['computer'] ?? null;

                    if ($computerDetail) {
                        // Add device type marker
                        $computerDetail['_device_type'] = 'computer';
                        $computers[] = $computerDetail;
                    }
                } else {
                    Log::warning("Failed to fetch details for computer ID {$computerId}");
                }

                // Small delay to avoid rate limiting
                usleep(100000); // 100ms
            }

            return $computers;

        } catch (\Exception $e) {
            Log::error('Error fetching JAMF computers: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch mobile devices from JAMF Pro
     */
    private function getJamfMobileDevices()
    {
        try {
            $baseUrl = rtrim(config('services.jamf.url'), '/');
            $url = $baseUrl . '/JSSResource/mobiledevices';

            // Get basic list of mobile devices
            $response = Http::withBasicAuth(...$this->getJamfAuth())
                ->accept('application/json')
                ->get($url);

            if (!$response->successful()) {
                Log::error('Failed to fetch JAMF mobile devices: ' . $response->status() . ' - ' . $response->body());
                return [];
            }

            $data = $response->json();
            $mobileDevicesList = $data['mobile_devices'] ?? [];

            if (empty($mobileDevicesList)) {
                return [];
            }

            Log::info('Fetching detailed information for ' . count($mobileDevicesList) . ' mobile devices...');

            $mobileDevices = [];

            // Fetch detailed information for each mobile device
            foreach ($mobileDevicesList as $device) {
                $deviceId = $device['id'];
                $detailResponse = Http::withBasicAuth(...$this->getJamfAuth())
                    ->accept('application/json')
                    ->timeout(30)
                    ->get($baseUrl . '/JSSResource/mobiledevices/id/' . $deviceId);

                if ($detailResponse->successful()) {
                    $detailData = $detailResponse->json();
                    $deviceDetail = $detailData['mobile_device'] ?? null;

                    if ($deviceDetail) {
                        // Add device type marker
                        $deviceDetail['_device_type'] = 'mobile_device';
                        $mobileDevices[] = $deviceDetail;
                    }
                } else {
                    Log::warning("Failed to fetch details for mobile device ID {$deviceId}");
                }

                // Small delay to avoid rate limiting
                usleep(100000); // 100ms
            }

            return $mobileDevices;

        } catch (\Exception $e) {
            Log::error('Error fetching JAMF mobile devices: ' . $e->getMessage());
            return [];
        }
    }
}

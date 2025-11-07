<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessIntuneDeviceBatch;
use App\Jobs\SyncIntuneDevicesToDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\IntuneApiResponses;
use Tests\TestCase;

class SyncIntuneDevicesToDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set test config values
        config([
            'services.microsoft.tenant_id' => 'test-tenant-id',
            'services.microsoft.client_id' => 'test-client-id',
            'services.microsoft.client_secret' => 'test-client-secret',
            'snipeit.intune_sync_chunk_size' => 2,
        ]);
    }

    /** @test */
    public function it_retrieves_oauth_token_from_microsoft_graph()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*' => Http::response(IntuneApiResponses::emptyDevicesResponse(), 200),
        ]);

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://login.microsoftonline.com/test-tenant-id/oauth2/v2.0/token'
                && $request['client_id'] === 'test-client-id'
                && $request['client_secret'] === 'test-client-secret'
                && $request['scope'] === 'https://graph.microsoft.com/.default'
                && $request['grant_type'] === 'client_credentials';
        });
    }

    /** @test */
    public function it_handles_oauth_token_failure()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenErrorResponse(), 401),
        ]);

        Log::shouldReceive('info')->with('Starting Intune to Snipe-IT database sync');
        Log::shouldReceive('error')->with('Graph token request failed: ' . json_encode(IntuneApiResponses::tokenErrorResponse()));
        Log::shouldReceive('error')->with('Failed to get Microsoft Graph access token');

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        // Should not attempt to fetch devices
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'graph.microsoft.com');
        });
    }

    /** @test */
    public function it_fetches_devices_from_intune()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*/deviceManagement/managedDevices*' => Http::response(IntuneApiResponses::devicesResponse(), 200),
        ]);

        Queue::fake();

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices'
                && $request->hasHeader('Authorization', 'Bearer ' . IntuneApiResponses::tokenResponse()['access_token']);
        });
    }

    /** @test */
    public function it_handles_pagination_correctly()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/v1.0/deviceManagement/managedDevices' => Http::response(IntuneApiResponses::devicesPageOne(), 200),
            'graph.microsoft.com/v1.0/deviceManagement/managedDevices?$skip=1' => Http::response(IntuneApiResponses::devicesPageTwo(), 200),
        ]);

        Queue::fake();

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        // Should fetch both pages
        Http::assertSentCount(3); // 1 for token, 2 for device pages

        // Should dispatch jobs for all devices (3 devices, chunk size 2 = 2 batches)
        Queue::assertPushed(ProcessIntuneDeviceBatch::class, 2);
    }

    /** @test */
    public function it_handles_empty_device_list()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*' => Http::response(IntuneApiResponses::emptyDevicesResponse(), 200),
        ]);

        Log::shouldReceive('info')->with('Starting Intune to Snipe-IT database sync');
        Log::shouldReceive('warning')->with('No devices found in Intune');

        Queue::fake();

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        // Should not dispatch any batch jobs
        Queue::assertNotPushed(ProcessIntuneDeviceBatch::class);
    }

    /** @test */
    public function it_chunks_devices_correctly()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*' => Http::response(IntuneApiResponses::devicesResponse(), 200),
        ]);

        Queue::fake();

        config(['snipeit.intune_sync_chunk_size' => 2]);

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        // 3 devices with chunk size 2 should create 2 batches
        Queue::assertPushed(ProcessIntuneDeviceBatch::class, 2);

        // Verify first batch has 2 devices
        Queue::assertPushed(ProcessIntuneDeviceBatch::class, function ($job) {
            return count($job->devices) === 2 && $job->batchNumber === 1;
        });

        // Verify second batch has 1 device
        Queue::assertPushed(ProcessIntuneDeviceBatch::class, function ($job) {
            return count($job->devices) === 1 && $job->batchNumber === 2;
        });
    }

    /** @test */
    public function it_clears_cache_before_sync()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*' => Http::response(IntuneApiResponses::devicesResponse(), 200),
        ]);

        Queue::fake();

        // Set some cache values
        Cache::put('intune_sync_manufacturers', ['test'], 60);
        Cache::put('intune_sync_models', ['test'], 60);
        Cache::put('intune_sync_statuslabels', ['test'], 60);

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        // Verify cache was cleared
        $this->assertFalse(Cache::has('intune_sync_manufacturers'));
        $this->assertFalse(Cache::has('intune_sync_models'));
        $this->assertFalse(Cache::has('intune_sync_statuslabels'));
    }

    /** @test */
    public function it_handles_api_failures_gracefully()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*' => Http::response(IntuneApiResponses::unauthorizedResponse(), 401),
        ]);

        Log::shouldReceive('info')->with('Starting Intune to Snipe-IT database sync');
        Log::shouldReceive('error')->with('Failed to fetch Intune devices: ' . json_encode(IntuneApiResponses::unauthorizedResponse()));
        Log::shouldReceive('warning')->with('No devices found in Intune');

        Queue::fake();

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        // Should not dispatch any jobs when API fails
        Queue::assertNotPushed(ProcessIntuneDeviceBatch::class);
    }

    /** @test */
    public function it_logs_progress_appropriately()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*' => Http::response(IntuneApiResponses::devicesResponse(), 200),
        ]);

        Queue::fake();

        Log::shouldReceive('info')->with('Starting Intune to Snipe-IT database sync');
        Log::shouldReceive('info')->with('Found 3 devices in Intune');
        Log::shouldReceive('info')->with('Dispatching 2 batch jobs (2 devices per batch)');
        Log::shouldReceive('info')->with('All batch jobs dispatched successfully');

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();
    }

    /** @test */
    public function it_verifies_microsoft_graph_payload_structure()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*' => Http::response(IntuneApiResponses::devicesResponse(), 200),
        ]);

        Queue::fake();

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        // Verify the dispatched jobs received correctly structured devices
        Queue::assertPushed(ProcessIntuneDeviceBatch::class, function ($job) {
            $device = $job->devices[0];

            // Verify required fields exist
            return isset($device['deviceName'])
                && isset($device['serialNumber'])
                && isset($device['model'])
                && isset($device['manufacturer'])
                && isset($device['operatingSystem'])
                && isset($device['osVersion'])
                && isset($device['userPrincipalName'])
                && isset($device['enrolledDateTime'])
                && isset($device['lastSyncDateTime']);
        });
    }

    /** @test */
    public function it_handles_devices_with_missing_fields()
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(IntuneApiResponses::tokenResponse(), 200),
            'graph.microsoft.com/*' => Http::response([
                'value' => [IntuneApiResponses::minimalDevice()],
            ], 200),
        ]);

        Queue::fake();

        $job = new SyncIntuneDevicesToDatabase();
        $job->handle();

        // Should still dispatch the job - the batch processor will handle validation
        Queue::assertPushed(ProcessIntuneDeviceBatch::class, 1);
    }
}

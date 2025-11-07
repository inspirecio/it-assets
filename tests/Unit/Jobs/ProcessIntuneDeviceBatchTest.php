<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessIntuneDeviceBatch;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\IntuneApiResponses;
use Tests\TestCase;

class ProcessIntuneDeviceBatchTest extends TestCase
{
    use RefreshDatabase;

    protected $devices;
    protected $statusLabel;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test to avoid stale IDs
        Cache::flush();

        $this->devices = IntuneApiResponses::devices();

        // Create a status label for testing (following AssetFactory pattern)
        $this->statusLabel = Statuslabel::where('name', 'Ready to Deploy')->first()
            ?? Statuslabel::factory()->rtd()->create(['name' => 'Ready to Deploy']);

        config([
            'snipeit.intune_auto_assign_users' => false,
            'snipeit.intune_default_location_id' => null,
        ]);
    }

    /** @test */
    public function it_creates_new_assets_from_intune_devices()
    {
        // Process the device
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        // Verify asset was created
        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertNotNull($asset, 'Asset was not created');
        $this->assertEquals('LAPTOP-JDOE', $asset->name);
        $this->assertEquals('DELLSER123456', $asset->asset_tag);
    }

    /** @test */
    public function it_creates_manufacturer_for_device()
    {
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        // Verify manufacturer was created
        $manufacturer = Manufacturer::where('name', 'Dell Inc.')->first();
        $this->assertNotNull($manufacturer);

        // Verify asset is linked to manufacturer via model
        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertEquals('Dell Inc.', $asset->model->manufacturer->name);
    }

    /** @test */
    public function it_creates_asset_model_for_device()
    {
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        // Verify model was created
        $model = AssetModel::where('name', 'Latitude 5420')->first();
        $this->assertNotNull($model);

        // Verify asset is linked to model
        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertEquals('Latitude 5420', $asset->model->name);
    }

    /** @test */
    public function it_creates_intune_devices_category()
    {
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        // Verify category was created
        $category = Category::where('name', 'Intune Devices')->first();
        $this->assertNotNull($category);
        $this->assertEquals('asset', $category->category_type);

        // Verify model is in correct category
        $model = AssetModel::where('name', 'Latitude 5420')->first();
        $this->assertEquals($category->id, $model->category_id);
    }

    /** @test */
    public function it_updates_existing_assets()
    {
        // Create existing asset
        $manufacturer = Manufacturer::factory()->create(['name' => 'Dell Inc.']);
        $category = Category::factory()->create(['name' => 'Intune Devices']);
        $model = AssetModel::factory()->create([
            'name' => 'Old Model',
            'manufacturer_id' => $manufacturer->id,
            'category_id' => $category->id,
        ]);
        $asset = Asset::factory()->create([
            'serial' => 'DELLSER123456',
            'name' => 'Old Name',
            'model_id' => $model->id,
            'status_id' => $this->statusLabel->id,
        ]);

        // Run sync
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        // Verify asset was updated
        $asset->refresh();
        $this->assertEquals('LAPTOP-JDOE', $asset->name);

        // Verify model was updated
        $newModel = AssetModel::where('name', 'Latitude 5420')->first();
        $this->assertEquals($newModel->id, $asset->model_id);

        // Should only have one asset with this serial
        $this->assertEquals(1, Asset::where('serial', 'DELLSER123456')->count());
    }

    /** @test */
    public function it_stores_device_information_in_notes()
    {
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        $asset = Asset::where('serial', 'DELLSER123456')->first();

        // Verify notes contain Intune info
        $this->assertStringContainsString('Synced from Microsoft Intune', $asset->notes);
        $this->assertStringContainsString('OS: Windows 10.0.19044.1234', $asset->notes);
        $this->assertStringContainsString('Owner Type: company', $asset->notes);
        $this->assertStringContainsString('Enrolled: 2024-01-15T10:30:00Z', $asset->notes);
        $this->assertStringContainsString('User: john.doe@company.com', $asset->notes);
    }

    /** @test */
    public function it_skips_devices_without_serial_number()
    {
        // Device with null serial number
        $job = new ProcessIntuneDeviceBatch([$this->devices[2]], 1);

        Log::shouldReceive('info')->with('Processing Intune batch #1 with 1 devices');
        Log::shouldReceive('warning')->with('Device Janes iPhone has no serial number, skipping');
        Log::shouldReceive('info')->with('Batch #1 completed: 0 synced (0 created, 0 updated), 1 errors');

        $job->handle();

        // Verify no asset was created
        $this->assertEquals(0, Asset::count());
    }

    /** @test */
    public function it_assigns_users_when_enabled()
    {
        config(['snipeit.intune_auto_assign_users' => true]);

        // Create user with matching email
        $user = User::factory()->create([
            'email' => 'john.doe@company.com',
        ]);

        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertEquals($user->id, $asset->assigned_to);
        $this->assertEquals(User::class, $asset->assigned_type);
    }

    /** @test */
    public function it_does_not_assign_users_when_disabled()
    {
        config(['snipeit.intune_auto_assign_users' => false]);

        // Create user with matching email
        User::factory()->create([
            'email' => 'john.doe@company.com',
        ]);

        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertNull($asset->assigned_to);
        $this->assertNull($asset->assigned_type);
    }

    /** @test */
    public function it_handles_user_not_found()
    {
        config(['snipeit.intune_auto_assign_users' => true]);

        // Don't create the user
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertNull($asset->assigned_to);
    }

    /** @test */
    public function it_restores_soft_deleted_assets()
    {
        // Create and soft delete an asset
        $manufacturer = Manufacturer::factory()->create(['name' => 'Dell Inc.']);
        $category = Category::factory()->create(['name' => 'Intune Devices']);
        $model = AssetModel::factory()->create([
            'name' => 'Latitude 5420',
            'manufacturer_id' => $manufacturer->id,
            'category_id' => $category->id,
        ]);
        $asset = Asset::factory()->create([
            'serial' => 'DELLSER123456',
            'name' => 'Old Name',
            'model_id' => $model->id,
            'status_id' => $this->statusLabel->id,
        ]);
        $asset->delete();

        $this->assertTrue($asset->trashed());

        Log::shouldReceive('info')->withAnyArgs();
        Log::shouldReceive('info')->with('Restored soft-deleted asset: LAPTOP-JDOE (Serial: DELLSER123456)');

        // Run sync
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        // Verify asset was restored
        $asset->refresh();
        $this->assertFalse($asset->trashed());
        $this->assertEquals('LAPTOP-JDOE', $asset->name);
    }

    /** @test */
    public function it_caches_manufacturer_lookups()
    {
        // First device creates manufacturer
        $job1 = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job1->handle();

        $this->assertEquals(1, Manufacturer::where('name', 'Dell Inc.')->count());

        // Create another device with same manufacturer
        $secondDevice = $this->devices[0];
        $secondDevice['serialNumber'] = 'DELLSER999999';
        $secondDevice['deviceName'] = 'LAPTOP-2';

        $job2 = new ProcessIntuneDeviceBatch([$secondDevice], 2);
        $job2->handle();

        // Should still only have one manufacturer (reused from cache)
        $this->assertEquals(1, Manufacturer::where('name', 'Dell Inc.')->count());
    }

    /** @test */
    public function it_caches_model_lookups()
    {
        // First device creates model
        $job1 = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job1->handle();

        $this->assertEquals(1, AssetModel::where('name', 'Latitude 5420')->count());

        // Create another device with same model
        $secondDevice = $this->devices[0];
        $secondDevice['serialNumber'] = 'DELLSER999999';
        $secondDevice['deviceName'] = 'LAPTOP-2';

        $job2 = new ProcessIntuneDeviceBatch([$secondDevice], 2);
        $job2->handle();

        // Should still only have one model (reused from cache)
        $this->assertEquals(1, AssetModel::where('name', 'Latitude 5420')->count());
    }

    /** @test */
    public function it_processes_multiple_devices_in_batch()
    {
        // Process first two devices (both have serial numbers)
        $job = new ProcessIntuneDeviceBatch([$this->devices[0], $this->devices[1]], 1);
        $job->handle();

        // Verify both assets were created
        $this->assertEquals(2, Asset::count());
        $this->assertNotNull(Asset::where('serial', 'DELLSER123456')->first());
        $this->assertNotNull(Asset::where('serial', 'C02ABC123DEF')->first());
    }

    /** @test */
    public function it_handles_apple_devices_correctly()
    {
        // MacBook (second device)
        $job = new ProcessIntuneDeviceBatch([$this->devices[1]], 1);
        $job->handle();

        $asset = Asset::where('serial', 'C02ABC123DEF')->first();
        $this->assertNotNull($asset);
        $this->assertEquals('Johns-MacBook-Pro', $asset->name);

        // Verify Apple manufacturer
        $manufacturer = Manufacturer::where('name', 'Apple')->first();
        $this->assertNotNull($manufacturer);
        $this->assertEquals('Apple', $asset->model->manufacturer->name);
    }

    /** @test */
    public function it_logs_sync_progress()
    {
        Log::shouldReceive('info')->with('Processing Intune batch #1 with 2 devices');
        Log::shouldReceive('info')->withAnyArgs()->times(2); // For each device creation
        Log::shouldReceive('info')->with('Batch #1 completed: 2 synced (2 created, 0 updated), 0 errors');

        $job = new ProcessIntuneDeviceBatch([$this->devices[0], $this->devices[1]], 1);
        $job->handle();
    }

    /** @test */
    public function it_counts_created_and_updated_correctly()
    {
        // Create one existing asset
        $manufacturer = Manufacturer::factory()->create(['name' => 'Dell Inc.']);
        $category = Category::factory()->create(['name' => 'Intune Devices']);
        $model = AssetModel::factory()->create([
            'name' => 'Latitude 5420',
            'manufacturer_id' => $manufacturer->id,
            'category_id' => $category->id,
        ]);
        Asset::factory()->create([
            'serial' => 'DELLSER123456',
            'model_id' => $model->id,
            'status_id' => $this->statusLabel->id,
        ]);

        Log::shouldReceive('info')->with('Processing Intune batch #1 with 2 devices');
        Log::shouldReceive('info')->withAnyArgs();
        Log::shouldReceive('info')->with('Batch #1 completed: 2 synced (1 created, 1 updated), 0 errors');

        // Process two devices (one exists, one new)
        $job = new ProcessIntuneDeviceBatch([$this->devices[0], $this->devices[1]], 1);
        $job->handle();
    }

    /** @test */
    public function it_uses_configured_status_label()
    {
        $customStatus = Statuslabel::factory()->create([
            'name' => 'Custom Status',
            'deployable' => 1,
        ]);

        config(['snipeit.intune_default_status_id' => $customStatus->id]);

        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertEquals($customStatus->id, $asset->status_id);
    }

    /** @test */
    public function it_falls_back_to_ready_to_deploy_status()
    {
        config(['snipeit.intune_default_status_id' => null]);

        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertEquals($this->statusLabel->id, $asset->status_id);
    }

    /** @test */
    public function it_uses_serial_as_asset_tag()
    {
        $job = new ProcessIntuneDeviceBatch([$this->devices[0]], 1);
        $job->handle();

        $asset = Asset::where('serial', 'DELLSER123456')->first();
        $this->assertEquals('DELLSER123456', $asset->asset_tag);
    }

    /** @test */
    public function it_handles_minimal_device_data()
    {
        $minimalDevice = IntuneApiResponses::minimalDevice();

        $job = new ProcessIntuneDeviceBatch([$minimalDevice], 1);
        $job->handle();

        $asset = Asset::where('serial', 'MINIMAL123')->first();
        $this->assertNotNull($asset);
        $this->assertEquals('Unknown Device', $asset->name);
    }
}

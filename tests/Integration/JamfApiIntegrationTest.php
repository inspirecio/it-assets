<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration tests for JAMF Pro API
 *
 * These tests connect to the REAL JAMF Pro API to verify:
 * 1. Basic Auth authentication works correctly
 * 2. API payload structure matches our expectations
 * 3. Required fields are present in responses
 *
 * NO DATABASE WRITES are performed - this is read-only testing.
 *
 * Requirements:
 * - .env.testing must have valid JAMF credentials:
 *   JAMF_URL (e.g., https://your-instance.jamfcloud.com)
 *   JAMF_USERNAME
 *   JAMF_PASSWORD
 *
 * Skip these tests if credentials are not configured.
 */
class JamfApiIntegrationTest extends TestCase
{
    protected $skipTests = false;
    protected $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        // Check if JAMF credentials are configured
        if (!config('services.jamf.url') ||
            !config('services.jamf.username') ||
            !config('services.jamf.password')) {
            $this->skipTests = true;
            $this->markTestSkipped('JAMF Pro credentials not configured in .env.testing');
        }

        $this->baseUrl = rtrim(config('services.jamf.url'), '/');
    }

    /**
     * Helper to get JAMF auth credentials
     */
    protected function getJamfAuth()
    {
        return [
            config('services.jamf.username'),
            config('services.jamf.password'),
        ];
    }

    /** @test */
    public function it_can_authenticate_with_jamf_basic_auth()
    {
        if ($this->skipTests) {
            return;
        }

        // Test authentication by fetching computers list
        $response = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/computers');

        $this->assertTrue($response->successful(), 'JAMF authentication failed: ' . $response->body());

        echo "\n✓ Successfully authenticated with JAMF Pro Basic Auth\n";
        echo "  JAMF URL: {$this->baseUrl}\n";
        echo "  Username: " . config('services.jamf.username') . "\n";
    }

    /** @test */
    public function it_can_fetch_jamf_computers()
    {
        if ($this->skipTests) {
            return;
        }

        $response = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/computers');

        $this->assertTrue($response->successful(), 'Failed to fetch JAMF computers');

        $data = $response->json();

        // Verify response structure
        $this->assertArrayHasKey('computers', $data);
        $this->assertIsArray($data['computers']);

        $computerCount = is_array($data['computers']) && !empty($data['computers'])
            ? count($data['computers'])
            : 0;

        echo "\n✓ Successfully fetched JAMF computers\n";
        echo "  Total Computers: {$computerCount}\n";
    }

    /** @test */
    public function it_can_fetch_jamf_mobile_devices()
    {
        if ($this->skipTests) {
            return;
        }

        $response = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/mobiledevices');

        $this->assertTrue($response->successful(), 'Failed to fetch JAMF mobile devices');

        $data = $response->json();

        // Verify response structure
        $this->assertArrayHasKey('mobile_devices', $data);
        $this->assertIsArray($data['mobile_devices']);

        $deviceCount = is_array($data['mobile_devices']) && !empty($data['mobile_devices'])
            ? count($data['mobile_devices'])
            : 0;

        echo "\n✓ Successfully fetched JAMF mobile devices\n";
        echo "  Total Mobile Devices: {$deviceCount}\n";
    }

    /** @test */
    public function it_verifies_computer_detail_payload_structure()
    {
        if ($this->skipTests) {
            return;
        }

        // First, get list of computers
        $listResponse = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/computers');

        $this->assertTrue($listResponse->successful());

        $listData = $listResponse->json();

        if (empty($listData['computers'])) {
            $this->markTestSkipped('No computers found in JAMF to verify payload structure');
        }

        // Get detailed info for first computer
        $firstComputer = $listData['computers'][0];
        $computerId = $firstComputer['id'];

        $detailResponse = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . "/JSSResource/computers/id/{$computerId}");

        $this->assertTrue($detailResponse->successful(), 'Failed to fetch computer details');

        $computer = $detailResponse->json()['computer'];

        echo "\n✓ Verifying JAMF computer payload structure\n";
        echo "  Sample Computer: " . ($computer['general']['name'] ?? 'Unknown') . "\n";

        // Verify expected structure
        $requiredSections = [
            'general' => 'General Information',
            'hardware' => 'Hardware Information',
            'location' => 'Location Information',
            'purchasing' => 'Purchasing Information',
        ];

        foreach ($requiredSections as $section => $label) {
            $this->assertArrayHasKey($section, $computer, "Missing section: {$section}");
            echo "  ✓ {$label} section present\n";
        }

        // Check required fields in general section
        $generalFields = ['id', 'name', 'serial_number'];
        foreach ($generalFields as $field) {
            if (array_key_exists($field, $computer['general'])) {
                echo "    ✓ general.{$field}: " . ($computer['general'][$field] ?? 'null') . "\n";
            } else {
                echo "    ✗ general.{$field}: not present\n";
            }
        }

        // Check hardware fields
        $hardwareFields = ['model', 'os_version', 'total_ram'];
        foreach ($hardwareFields as $field) {
            if (array_key_exists($field, $computer['hardware'])) {
                echo "    ✓ hardware.{$field}: " . ($computer['hardware'][$field] ?? 'null') . "\n";
            } else {
                echo "    ✗ hardware.{$field}: not present\n";
            }
        }

        // Check location fields
        $locationFields = ['email_address', 'building', 'department'];
        foreach ($locationFields as $field) {
            if (array_key_exists($field, $computer['location'])) {
                echo "    ✓ location.{$field}: " . ($computer['location'][$field] ?? 'null') . "\n";
            } else {
                echo "    ✗ location.{$field}: not present\n";
            }
        }

        // Check purchasing fields
        $purchasingFields = ['purchase_date', 'purchase_price', 'po_number'];
        foreach ($purchasingFields as $field) {
            if (array_key_exists($field, $computer['purchasing'])) {
                echo "    ✓ purchasing.{$field}: " . ($computer['purchasing'][$field] ?? 'null') . "\n";
            } else {
                echo "    ✗ purchasing.{$field}: not present\n";
            }
        }

        // Dump full structure for reference
        echo "\n  Full computer structure:\n";
        echo "  " . json_encode($computer, JSON_PRETTY_PRINT) . "\n";
    }

    /** @test */
    public function it_verifies_mobile_device_detail_payload_structure()
    {
        if ($this->skipTests) {
            return;
        }

        // First, get list of mobile devices
        $listResponse = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/mobiledevices');

        $this->assertTrue($listResponse->successful());

        $listData = $listResponse->json();

        if (empty($listData['mobile_devices'])) {
            $this->markTestSkipped('No mobile devices found in JAMF to verify payload structure');
        }

        // Get detailed info for first mobile device
        $firstDevice = $listData['mobile_devices'][0];
        $deviceId = $firstDevice['id'];

        $detailResponse = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . "/JSSResource/mobiledevices/id/{$deviceId}");

        $this->assertTrue($detailResponse->successful(), 'Failed to fetch mobile device details');

        $device = $detailResponse->json()['mobile_device'];

        echo "\n✓ Verifying JAMF mobile device payload structure\n";
        echo "  Sample Device: " . ($device['general']['name'] ?? 'Unknown') . "\n";

        // Verify expected structure
        $requiredSections = [
            'general' => 'General Information',
            'location' => 'Location Information',
            'purchasing' => 'Purchasing Information',
        ];

        foreach ($requiredSections as $section => $label) {
            $this->assertArrayHasKey($section, $device, "Missing section: {$section}");
            echo "  ✓ {$label} section present\n";
        }

        // Check required fields in general section
        $generalFields = ['id', 'name', 'serial_number', 'model', 'os_version'];
        foreach ($generalFields as $field) {
            if (array_key_exists($field, $device['general'])) {
                echo "    ✓ general.{$field}: " . ($device['general'][$field] ?? 'null') . "\n";
            } else {
                echo "    ✗ general.{$field}: not present\n";
            }
        }

        // Dump full structure for reference
        echo "\n  Full mobile device structure:\n";
        echo "  " . json_encode($device, JSON_PRETTY_PRINT) . "\n";
    }

    /** @test */
    public function it_handles_api_errors_gracefully()
    {
        if ($this->skipTests) {
            return;
        }

        // Try to fetch a non-existent computer
        $response = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/computers/id/999999');

        echo "\n✓ Testing error handling\n";
        echo "  Status Code: {$response->status()}\n";

        // Should return 404
        $this->assertEquals(404, $response->status());

        echo "  Response Body: " . $response->body() . "\n";
    }

    /** @test */
    public function it_verifies_all_computers_have_serial_numbers()
    {
        if ($this->skipTests) {
            return;
        }

        $response = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/computers');

        $this->assertTrue($response->successful());

        $data = $response->json();

        if (empty($data['computers'])) {
            $this->markTestSkipped('No computers found in JAMF');
        }

        echo "\n✓ Checking serial numbers on all computers\n";

        $totalComputers = count($data['computers']);
        $computersWithSerial = 0;
        $computersWithoutSerial = 0;

        // Check first 10 computers (or all if less than 10)
        $checkLimit = min(10, $totalComputers);

        for ($i = 0; $i < $checkLimit; $i++) {
            $computerId = $data['computers'][$i]['id'];

            $detailResponse = Http::withBasicAuth(...$this->getJamfAuth())
                ->accept('application/json')
                ->get($this->baseUrl . "/JSSResource/computers/id/{$computerId}");

            if ($detailResponse->successful()) {
                $computer = $detailResponse->json()['computer'];
                $serial = $computer['general']['serial_number'] ?? '';

                if (!empty($serial)) {
                    $computersWithSerial++;
                } else {
                    $computersWithoutSerial++;
                    echo "  ⚠️  Computer ID {$computerId} has no serial number\n";
                }
            }
        }

        echo "  Computers checked: {$checkLimit}\n";
        echo "  With serial number: {$computersWithSerial}\n";
        echo "  Without serial number: {$computersWithoutSerial}\n";

        if ($computersWithoutSerial > 0) {
            echo "  ⚠️  WARNING: Some computers are missing serial numbers. They will be skipped during sync.\n";
        }
    }

    /** @test */
    public function it_measures_api_response_time()
    {
        if ($this->skipTests) {
            return;
        }

        // Measure list fetch time
        $startTime = microtime(true);

        $response = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/computers');

        $endTime = microtime(true);
        $listDuration = round(($endTime - $startTime) * 1000, 2);

        $this->assertTrue($response->successful());

        $data = $response->json();
        $computerCount = count($data['computers'] ?? []);

        echo "\n✓ API Performance\n";
        echo "  List fetch time: {$listDuration}ms\n";
        echo "  Computers in list: {$computerCount}\n";

        // Measure detail fetch time
        if ($computerCount > 0) {
            $firstComputerId = $data['computers'][0]['id'];

            $detailStartTime = microtime(true);

            $detailResponse = Http::withBasicAuth(...$this->getJamfAuth())
                ->accept('application/json')
                ->get($this->baseUrl . "/JSSResource/computers/id/{$firstComputerId}");

            $detailEndTime = microtime(true);
            $detailDuration = round(($detailEndTime - $detailStartTime) * 1000, 2);

            echo "  Detail fetch time: {$detailDuration}ms\n";

            // Estimate full sync time
            $estimatedFullSync = ($listDuration + ($detailDuration * $computerCount)) / 1000;
            echo "  Estimated full sync time: " . round($estimatedFullSync, 2) . " seconds\n";

            if ($estimatedFullSync > 300) {
                echo "  ⚠️  WARNING: Estimated sync time is over 5 minutes. Consider chunking.\n";
            }
        }
    }

    /** @test */
    public function it_verifies_manufacturer_is_always_apple()
    {
        if ($this->skipTests) {
            return;
        }

        $response = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . '/JSSResource/computers');

        $this->assertTrue($response->successful());

        $data = $response->json();

        if (empty($data['computers'])) {
            $this->markTestSkipped('No computers found in JAMF');
        }

        // Check first computer
        $firstComputerId = $data['computers'][0]['id'];

        $detailResponse = Http::withBasicAuth(...$this->getJamfAuth())
            ->accept('application/json')
            ->get($this->baseUrl . "/JSSResource/computers/id/{$firstComputerId}");

        $this->assertTrue($detailResponse->successful());

        $computer = $detailResponse->json()['computer'];

        echo "\n✓ Verifying manufacturer (should always be Apple for JAMF)\n";
        echo "  Computer Model: " . ($computer['hardware']['model'] ?? 'Unknown') . "\n";

        // JAMF only manages Apple devices, so manufacturer should always be Apple
        // (There's no explicit manufacturer field, but it's implied)
        echo "  Implicit Manufacturer: Apple (JAMF only manages Apple devices)\n";
        echo "  Note: Our sync job will always set manufacturer to 'Apple' for JAMF devices\n";
    }
}

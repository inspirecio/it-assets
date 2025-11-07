<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration tests for Microsoft Intune API
 *
 * These tests connect to the REAL Intune API to verify:
 * 1. OAuth authentication works correctly
 * 2. API payload structure matches our expectations
 * 3. Required fields are present in responses
 *
 * NO DATABASE WRITES are performed - this is read-only testing.
 *
 * Requirements:
 * - .env.testing must have valid Microsoft credentials:
 *   MICROSOFT_TENANT_ID
 *   MICROSOFT_CLIENT_ID
 *   MICROSOFT_CLIENT_SECRET
 *
 * Skip these tests if credentials are not configured.
 */
class IntuneApiIntegrationTest extends TestCase
{
    protected $accessToken;
    protected $skipTests = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Check if Intune credentials are configured
        if (!config('services.microsoft.tenant_id') ||
            !config('services.microsoft.client_id') ||
            !config('services.microsoft.client_secret')) {
            $this->skipTests = true;
            $this->markTestSkipped('Microsoft Intune credentials not configured in .env.testing');
        }
    }

    /**
     * Helper to get OAuth access token
     */
    protected function getAccessToken()
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/' . config('services.microsoft.tenant_id') . '/oauth2/v2.0/token',
            [
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]
        );

        if (!$response->successful()) {
            $this->fail('Failed to obtain OAuth token: ' . $response->body());
        }

        $data = $response->json();
        $this->accessToken = $data['access_token'];

        return $this->accessToken;
    }

    /** @test */
    public function it_can_authenticate_with_microsoft_oauth()
    {
        if ($this->skipTests) {
            return;
        }

        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/' . config('services.microsoft.tenant_id') . '/oauth2/v2.0/token',
            [
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]
        );

        $this->assertTrue($response->successful(), 'OAuth token request failed');

        $data = $response->json();

        // Verify response structure
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('token_type', $data);
        $this->assertArrayHasKey('expires_in', $data);

        // Verify token is valid
        $this->assertNotEmpty($data['access_token']);
        $this->assertEquals('Bearer', $data['token_type']);
        $this->assertGreaterThan(0, $data['expires_in']);

        echo "\n✓ Successfully authenticated with Microsoft OAuth\n";
        echo "  Token Type: {$data['token_type']}\n";
        echo "  Expires In: {$data['expires_in']} seconds\n";
    }

    /** @test */
    public function it_can_fetch_intune_devices()
    {
        if ($this->skipTests) {
            return;
        }

        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices');

        $this->assertTrue($response->successful(), 'Failed to fetch Intune devices: ' . $response->body());

        $data = $response->json();

        // Verify response structure
        $this->assertArrayHasKey('value', $data, 'Response missing "value" array');
        $this->assertIsArray($data['value'], '"value" should be an array');

        echo "\n✓ Successfully fetched Intune devices\n";
        echo "  Total Devices: " . count($data['value']) . "\n";

        // Check for pagination
        if (isset($data['@odata.nextLink'])) {
            echo "  Pagination: Yes (@odata.nextLink present)\n";
        } else {
            echo "  Pagination: No (all devices in single response)\n";
        }
    }

    /** @test */
    public function it_verifies_intune_device_payload_structure()
    {
        if ($this->skipTests) {
            return;
        }

        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices');

        $this->assertTrue($response->successful());

        $data = $response->json();
        $devices = $data['value'];

        if (empty($devices)) {
            $this->markTestSkipped('No devices found in Intune to verify payload structure');
        }

        // Test the first device
        $device = $devices[0];

        echo "\n✓ Verifying Intune device payload structure\n";
        echo "  Sample Device: " . ($device['deviceName'] ?? 'Unknown') . "\n";

        // Required fields for our sync job
        $requiredFields = [
            'id' => 'Device ID',
            'deviceName' => 'Device Name',
            'serialNumber' => 'Serial Number',
            'manufacturer' => 'Manufacturer',
            'model' => 'Model',
            'operatingSystem' => 'Operating System',
            'osVersion' => 'OS Version',
        ];

        $optionalFields = [
            'userPrincipalName' => 'User Principal Name',
            'enrolledDateTime' => 'Enrolled Date/Time',
            'lastSyncDateTime' => 'Last Sync Date/Time',
            'managedDeviceOwnerType' => 'Owner Type',
            'azureADDeviceId' => 'Azure AD Device ID',
            'deviceEnrollmentType' => 'Enrollment Type',
        ];

        // Check required fields
        foreach ($requiredFields as $field => $label) {
            if (array_key_exists($field, $device)) {
                echo "  ✓ {$label} ({$field}): " . ($device[$field] ?? 'null') . "\n";
            } else {
                $this->fail("Required field '{$field}' is missing from device payload");
            }
        }

        // Check optional fields
        echo "\n  Optional fields:\n";
        foreach ($optionalFields as $field => $label) {
            if (array_key_exists($field, $device)) {
                echo "  ✓ {$label} ({$field}): " . ($device[$field] ?? 'null') . "\n";
            } else {
                echo "  ✗ {$label} ({$field}): not present\n";
            }
        }

        // Dump full device structure for reference
        echo "\n  Full device structure (first device):\n";
        echo "  " . json_encode($device, JSON_PRETTY_PRINT) . "\n";
    }

    /** @test */
    public function it_handles_pagination_correctly()
    {
        if ($this->skipTests) {
            return;
        }

        $token = $this->getAccessToken();

        // Fetch first page with pagination limit
        $response = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$top=5');

        $this->assertTrue($response->successful());

        $data = $response->json();

        echo "\n✓ Testing pagination\n";
        echo "  First page devices: " . count($data['value']) . "\n";

        // If there's a next link, fetch it
        if (isset($data['@odata.nextLink'])) {
            echo "  Next link present: Yes\n";
            echo "  Next link URL: {$data['@odata.nextLink']}\n";

            $nextResponse = Http::withToken($token)
                ->get($data['@odata.nextLink']);

            $this->assertTrue($nextResponse->successful(), 'Failed to fetch next page');

            $nextData = $nextResponse->json();
            echo "  Second page devices: " . count($nextData['value']) . "\n";

            $this->assertArrayHasKey('value', $nextData);
            $this->assertIsArray($nextData['value']);
        } else {
            echo "  Next link present: No (all devices fit in one page)\n";
        }
    }

    /** @test */
    public function it_handles_api_errors_gracefully()
    {
        if ($this->skipTests) {
            return;
        }

        $token = $this->getAccessToken();

        // Try to fetch a non-existent device
        $response = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices/invalid-device-id-12345');

        echo "\n✓ Testing error handling\n";
        echo "  Status Code: {$response->status()}\n";

        // Should return 404
        $this->assertEquals(404, $response->status());

        $error = $response->json();

        // Microsoft Graph errors have specific structure
        $this->assertArrayHasKey('error', $error);
        $this->assertArrayHasKey('code', $error['error']);
        $this->assertArrayHasKey('message', $error['error']);

        echo "  Error Code: {$error['error']['code']}\n";
        echo "  Error Message: {$error['error']['message']}\n";
    }

    /** @test */
    public function it_verifies_device_filtering_capabilities()
    {
        if ($this->skipTests) {
            return;
        }

        $token = $this->getAccessToken();

        // Test filtering by operating system
        $response = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$filter=operatingSystem eq \'Windows\'');

        echo "\n✓ Testing filtering capabilities\n";

        if ($response->successful()) {
            $data = $response->json();
            echo "  Windows devices: " . count($data['value']) . "\n";
            $this->assertIsArray($data['value']);
        } else {
            echo "  Filtering not supported or no Windows devices found\n";
        }

        // Test filtering by manufacturer
        $response2 = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$filter=startswith(manufacturer,\'Dell\')');

        if ($response2->successful()) {
            $data2 = $response2->json();
            echo "  Dell devices: " . count($data2['value']) . "\n";
        } else {
            echo "  Manufacturer filtering not supported or no Dell devices found\n";
        }
    }

    /** @test */
    public function it_measures_api_response_time()
    {
        if ($this->skipTests) {
            return;
        }

        $token = $this->getAccessToken();

        $startTime = microtime(true);

        $response = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices');

        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);

        $this->assertTrue($response->successful());

        echo "\n✓ API Performance\n";
        echo "  Response Time: {$duration}ms\n";

        $data = $response->json();
        echo "  Devices Fetched: " . count($data['value']) . "\n";

        if (count($data['value']) > 0) {
            $perDevice = round($duration / count($data['value']), 2);
            echo "  Time per Device: {$perDevice}ms\n";
        }

        // Warning if response is slow
        if ($duration > 5000) {
            echo "  ⚠️  WARNING: API response is slow (>5 seconds)\n";
        }
    }
}

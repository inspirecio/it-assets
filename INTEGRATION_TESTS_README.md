# MDM API Integration Tests

This document explains how to run the integration tests for Intune and JAMF APIs. These tests connect to **real API endpoints** to verify payload structure without writing to the database.

## Overview

We have two integration test suites:

1. **IntuneApiIntegrationTest** - Tests Microsoft Intune via Microsoft Graph API
2. **JamfApiIntegrationTest** - Tests JAMF Pro via JAMF Classic API

These tests are **read-only** - they authenticate, fetch data, and verify structure, but **never write to the database**.

## Prerequisites

### For Intune Integration Tests

You need a Microsoft Azure app registration with the following:

1. **Tenant ID** - Your Azure AD tenant ID
2. **Client ID** - App registration application (client) ID
3. **Client Secret** - App registration client secret value

#### Required Microsoft Graph API Permissions

Your app registration needs these permissions:
- `DeviceManagementManagedDevices.Read.All` (Application permission)

#### How to Create Azure App Registration

1. Go to Azure Portal → Azure Active Directory → App registrations
2. Click "New registration"
3. Name it "Snipe-IT MDM Sync - Testing"
4. Click "Register"
5. Note the **Application (client) ID** and **Directory (tenant) ID**
6. Go to "Certificates & secrets" → New client secret
7. Copy the **secret value** (you can only see it once!)
8. Go to "API permissions" → Add permission → Microsoft Graph → Application permissions
9. Search for and add: `DeviceManagementManagedDevices.Read.All`
10. Click "Grant admin consent" (requires admin)

### For JAMF Integration Tests

You need a JAMF Pro account with read access:

1. **JAMF URL** - Your JAMF Pro instance URL (e.g., `https://yourcompany.jamfcloud.com`)
2. **Username** - JAMF Pro username with read access
3. **Password** - JAMF Pro password

#### Required JAMF Permissions

The account needs these privileges:
- Computers: Read
- Mobile Devices: Read

#### Recommended: Create Dedicated API User

1. Go to JAMF Pro → Settings → System → User Accounts & Groups
2. Create new Standard Account: "snipeit-api-testing"
3. Set privilege set with only read access to computers and mobile devices
4. Use this account for testing

## Configuration

### 1. Configure .env.testing

Add these credentials to your `.env.testing` file:

```env
# Microsoft Intune (Graph API)
MICROSOFT_TENANT_ID=your-tenant-id-here
MICROSOFT_CLIENT_ID=your-client-id-here
MICROSOFT_CLIENT_SECRET=your-client-secret-here

# JAMF Pro
JAMF_URL=https://yourcompany.jamfcloud.com
JAMF_USERNAME=your-jamf-username
JAMF_PASSWORD=your-jamf-password
```

**Important**: Never commit `.env.testing` with real credentials!

### 2. Verify Configuration

The tests will automatically skip if credentials are not configured, so you can safely run the test suite even without credentials.

## Running the Tests

### Run All Integration Tests

```bash
php artisan test tests/Integration
```

### Run Only Intune Integration Tests

```bash
php artisan test tests/Integration/IntuneApiIntegrationTest.php
```

### Run Only JAMF Integration Tests

```bash
php artisan test tests/Integration/JamfApiIntegrationTest.php
```

### Run a Specific Test

```bash
# Intune: Verify device payload structure
php artisan test --filter=it_verifies_intune_device_payload_structure

# JAMF: Verify computer payload structure
php artisan test --filter=it_verifies_computer_detail_payload_structure
```

## What Each Test Does

### Intune Integration Tests

| Test | Purpose |
|------|---------|
| `it_can_authenticate_with_microsoft_oauth` | Verifies OAuth 2.0 client credentials flow works |
| `it_can_fetch_intune_devices` | Fetches device list from Microsoft Graph API |
| `it_verifies_intune_device_payload_structure` | Checks all required fields are present in device response |
| `it_handles_pagination_correctly` | Tests @odata.nextLink pagination |
| `it_handles_api_errors_gracefully` | Verifies error response structure |
| `it_verifies_device_filtering_capabilities` | Tests OData $filter parameter |
| `it_measures_api_response_time` | Measures performance and warns if slow |

### JAMF Integration Tests

| Test | Purpose |
|------|---------|
| `it_can_authenticate_with_jamf_basic_auth` | Verifies Basic Auth works |
| `it_can_fetch_jamf_computers` | Fetches computer list from JAMF |
| `it_can_fetch_jamf_mobile_devices` | Fetches mobile device list from JAMF |
| `it_verifies_computer_detail_payload_structure` | Checks all required fields in computer detail response |
| `it_verifies_mobile_device_detail_payload_structure` | Checks all required fields in mobile device response |
| `it_handles_api_errors_gracefully` | Verifies 404 handling |
| `it_verifies_all_computers_have_serial_numbers` | Warns if devices are missing serial numbers |
| `it_measures_api_response_time` | Measures performance and estimates full sync time |
| `it_verifies_manufacturer_is_always_apple` | Confirms JAMF only manages Apple devices |

## Test Output Examples

### Successful Intune Test Output

```
✓ Successfully authenticated with Microsoft OAuth
  Token Type: Bearer
  Expires In: 3599 seconds

✓ Successfully fetched Intune devices
  Total Devices: 247
  Pagination: Yes (@odata.nextLink present)

✓ Verifying Intune device payload structure
  Sample Device: LAPTOP-JDOE
  ✓ Device ID (id): 12345678-1234-1234-1234-123456789abc
  ✓ Device Name (deviceName): LAPTOP-JDOE
  ✓ Serial Number (serialNumber): DELLSER123456
  ✓ Manufacturer (manufacturer): Dell Inc.
  ✓ Model (model): Latitude 5420
  ✓ Operating System (operatingSystem): Windows
  ✓ OS Version (osVersion): 10.0.19044.1234

  Optional fields:
  ✓ User Principal Name (userPrincipalName): john.doe@company.com
  ✓ Enrolled Date/Time (enrolledDateTime): 2024-01-15T10:30:00Z
  ✓ Last Sync Date/Time (lastSyncDateTime): 2025-01-06T08:15:00Z
  ...

✓ API Performance
  Response Time: 847ms
  Devices Fetched: 247
  Time per Device: 3.43ms
```

### Successful JAMF Test Output

```
✓ Successfully authenticated with JAMF Pro Basic Auth
  JAMF URL: https://yourcompany.jamfcloud.com
  Username: snipeit-api

✓ Successfully fetched JAMF computers
  Total Computers: 143

✓ Verifying JAMF computer payload structure
  Sample Computer: Johns-MacBook-Pro
  ✓ General Information section present
    ✓ general.id: 42
    ✓ general.name: Johns-MacBook-Pro
    ✓ general.serial_number: C02ABC123DEF
  ✓ Hardware Information section present
    ✓ hardware.model: MacBook Pro (16-inch, 2021)
    ✓ hardware.os_version: 14.2.1
    ✓ hardware.total_ram: 32768
  ✓ Location Information section present
    ✓ location.email_address: john.doe@company.com
    ✓ location.building: Main Office
    ✓ location.department: Engineering
  ...

✓ API Performance
  List fetch time: 234ms
  Computers in list: 143
  Detail fetch time: 156ms
  Estimated full sync time: 22.52 seconds
```

## Troubleshooting

### Intune Tests

**Error: Failed to obtain OAuth token**
- Check your `MICROSOFT_TENANT_ID`, `MICROSOFT_CLIENT_ID`, and `MICROSOFT_CLIENT_SECRET`
- Verify the client secret hasn't expired in Azure
- Confirm the app registration exists and isn't disabled

**Error: Insufficient privileges**
- Go to Azure Portal → App registrations → Your app → API permissions
- Ensure `DeviceManagementManagedDevices.Read.All` is present
- Click "Grant admin consent" if not already granted

**Error: No devices found**
- This is OK if your Intune tenant is empty
- Tests will skip payload verification if no devices exist
- At least try to have one test device for complete validation

### JAMF Tests

**Error: 401 Unauthorized**
- Check your `JAMF_URL`, `JAMF_USERNAME`, and `JAMF_PASSWORD`
- Ensure the account isn't locked
- Verify the account has read access to computers and mobile devices

**Error: Connection timeout**
- Check your JAMF_URL is correct (include https://)
- Ensure your network can reach the JAMF instance
- Try accessing the JAMF URL in a browser

**Error: No computers/devices found**
- This is OK if your JAMF instance is empty
- Tests will skip payload verification if no devices exist
- At least try to have one test device for complete validation

## Security Best Practices

1. **Never commit credentials** - Add `.env.testing` to `.gitignore`
2. **Use read-only accounts** - Create dedicated API users with minimal permissions
3. **Rotate secrets regularly** - Update client secrets/passwords periodically
4. **Use separate tenants** - Don't test against production Intune/JAMF if possible
5. **Monitor API usage** - Watch for unexpected API calls in Azure/JAMF logs

## CI/CD Integration

If you want to run these tests in CI/CD:

### GitHub Actions Example

```yaml
name: Integration Tests

on:
  schedule:
    - cron: '0 2 * * *'  # Run daily at 2 AM
  workflow_dispatch:  # Allow manual trigger

jobs:
  integration-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install Dependencies
        run: composer install

      - name: Run Integration Tests
        env:
          MICROSOFT_TENANT_ID: ${{ secrets.MICROSOFT_TENANT_ID }}
          MICROSOFT_CLIENT_ID: ${{ secrets.MICROSOFT_CLIENT_ID }}
          MICROSOFT_CLIENT_SECRET: ${{ secrets.MICROSOFT_CLIENT_SECRET }}
          JAMF_URL: ${{ secrets.JAMF_URL }}
          JAMF_USERNAME: ${{ secrets.JAMF_USERNAME }}
          JAMF_PASSWORD: ${{ secrets.JAMF_PASSWORD }}
        run: php artisan test tests/Integration
```

Store credentials as GitHub Secrets, never in the workflow file!

## API Rate Limits

### Microsoft Graph API

- **Default**: 2000 requests per app per 20 seconds
- Our tests make ~10 requests total
- **No risk** of hitting rate limits during testing

### JAMF Pro API

- **Varies by instance** - typically 500-1000 requests per minute
- Our tests make ~15 requests total (10 for computers, 5 for mobile devices)
- **No risk** of hitting rate limits during testing

If you're running tests repeatedly (e.g., in CI/CD), consider adding delays:

```php
// Add this in setUp() if needed
sleep(2);  // Wait 2 seconds between tests
```

## What These Tests Don't Do

❌ Write to database
❌ Create/update/delete devices in Intune/JAMF
❌ Modify any settings
❌ Run sync jobs
❌ Test batch processing
❌ Test error recovery

These tests **only verify** that:
✅ Authentication works
✅ API endpoints are reachable
✅ Response structure matches our expectations
✅ Required fields are present
✅ Performance is acceptable

## When to Run These Tests

**Run manually**:
- Before deploying to production
- After API changes announced by Microsoft/JAMF
- When troubleshooting sync issues
- When onboarding new Intune tenant or JAMF instance

**Run automatically**:
- Nightly in CI/CD (recommended)
- Before each production deployment
- After dependency updates (e.g., `guzzlehttp/guzzle`)

## Expected Results

All tests should **pass** if:
- Credentials are valid
- APIs are accessible
- At least one device exists in Intune/JAMF
- Network connectivity is stable

Tests will **skip** if:
- Credentials are not configured in `.env.testing`
- No devices exist (some tests will skip payload verification)

Tests should **fail** if:
- API structure has changed (Microsoft/JAMF made breaking changes)
- Required fields are missing
- Authentication fails
- Network issues

A failed test indicates the sync job may need updates!

## Next Steps

After running integration tests successfully:

1. Review the full device payload dump
2. Compare with our fixture data in `tests/Fixtures/`
3. Update fixtures if API structure has changed
4. Run unit tests to ensure compatibility: `php artisan test tests/Unit`
5. Do a test sync run in a staging environment
6. Monitor the first production sync closely

## Support

If you encounter issues:

1. Check the test output for specific error messages
2. Verify credentials are correct
3. Check API permissions/privileges
4. Review Azure/JAMF audit logs for authentication attempts
5. Consult Microsoft Graph API or JAMF Pro API documentation

## Related Documentation

- [MDM_SYNC_OVERVIEW.md](./MDM_SYNC_OVERVIEW.md) - Overview of sync architecture
- [INTUNE_SYNC_README.md](./INTUNE_SYNC_README.md) - Intune sync job documentation
- [JAMF_SYNC_README.md](./JAMF_SYNC_README.md) - JAMF sync job documentation
- [TESTING_RESULTS_UPDATED.md](./TESTING_RESULTS_UPDATED.md) - Unit test results and bug fixes

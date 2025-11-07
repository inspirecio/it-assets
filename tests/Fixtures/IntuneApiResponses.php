<?php

namespace Tests\Fixtures;

/**
 * Realistic Microsoft Graph API responses for Intune managed devices
 * Based on: https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list
 */
class IntuneApiResponses
{
    /**
     * OAuth token response from Microsoft
     */
    public static function tokenResponse(): array
    {
        return [
            'token_type' => 'Bearer',
            'expires_in' => 3599,
            'ext_expires_in' => 3599,
            'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsIng1dCI6IjdkRC1nZWNOZ1gxWmY3R0xrT3ZwT0IyZGNWQSIsImtpZCI6IjdkRC1nZWNOZ1gxWmY3R0xrT3ZwT0IyZGNWQSJ9.test-token',
        ];
    }

    /**
     * Single page of managed devices (no pagination)
     */
    public static function devicesResponse(): array
    {
        return [
            '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#deviceManagement/managedDevices',
            '@odata.count' => 3,
            'value' => self::devices(),
        ];
    }

    /**
     * First page of devices with pagination
     */
    public static function devicesPageOne(): array
    {
        return [
            '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#deviceManagement/managedDevices',
            'value' => [self::devices()[0]],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$skip=1',
        ];
    }

    /**
     * Second page of devices with pagination
     */
    public static function devicesPageTwo(): array
    {
        return [
            '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#deviceManagement/managedDevices',
            'value' => [self::devices()[1], self::devices()[2]],
        ];
    }

    /**
     * Empty devices response
     */
    public static function emptyDevicesResponse(): array
    {
        return [
            '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#deviceManagement/managedDevices',
            '@odata.count' => 0,
            'value' => [],
        ];
    }

    /**
     * Sample managed devices
     */
    public static function devices(): array
    {
        return [
            // Windows laptop
            [
                'id' => '12345678-1234-1234-1234-123456789abc',
                'userId' => '87654321-4321-4321-4321-cba987654321',
                'deviceName' => 'LAPTOP-JDOE',
                'managedDeviceOwnerType' => 'company',
                'enrolledDateTime' => '2024-01-15T10:30:00Z',
                'lastSyncDateTime' => '2025-01-06T08:15:00Z',
                'operatingSystem' => 'Windows',
                'complianceState' => 'compliant',
                'jailBroken' => 'Unknown',
                'managementAgent' => 'mdm',
                'osVersion' => '10.0.19044.1234',
                'easActivated' => false,
                'easDeviceId' => '',
                'easActivationDateTime' => '0001-01-01T00:00:00Z',
                'azureADRegistered' => true,
                'deviceEnrollmentType' => 'userEnrollment',
                'activationLockBypassCode' => null,
                'emailAddress' => 'john.doe@company.com',
                'azureADDeviceId' => '11111111-2222-3333-4444-555555555555',
                'deviceRegistrationState' => 'registered',
                'deviceCategoryDisplayName' => 'Corporate',
                'isSupervised' => false,
                'exchangeLastSuccessfulSyncDateTime' => '0001-01-01T00:00:00Z',
                'exchangeAccessState' => 'none',
                'exchangeAccessStateReason' => 'none',
                'remoteAssistanceSessionUrl' => null,
                'remoteAssistanceSessionErrorDetails' => null,
                'isEncrypted' => true,
                'userPrincipalName' => 'john.doe@company.com',
                'model' => 'Latitude 5420',
                'manufacturer' => 'Dell Inc.',
                'imei' => '',
                'complianceGracePeriodExpirationDateTime' => '9999-12-31T23:59:59.9999999Z',
                'serialNumber' => 'DELLSER123456',
                'phoneNumber' => '',
                'androidSecurityPatchLevel' => '',
                'userDisplayName' => 'John Doe',
                'configurationManagerClientEnabledFeatures' => null,
                'wiFiMacAddress' => '00:11:22:33:44:55',
                'deviceHealthAttestationState' => null,
                'subscriberCarrier' => '',
                'meid' => '',
                'totalStorageSpaceInBytes' => 512110190592,
                'freeStorageSpaceInBytes' => 256055095296,
                'managedDeviceName' => 'LAPTOP-JDOE',
                'partnerReportedThreatState' => 'available',
            ],
            // MacBook
            [
                'id' => '22345678-1234-1234-1234-123456789def',
                'userId' => '77654321-4321-4321-4321-cba987654322',
                'deviceName' => 'Johns-MacBook-Pro',
                'managedDeviceOwnerType' => 'company',
                'enrolledDateTime' => '2024-03-20T14:22:00Z',
                'lastSyncDateTime' => '2025-01-06T09:30:00Z',
                'operatingSystem' => 'macOS',
                'complianceState' => 'compliant',
                'jailBroken' => 'Unknown',
                'managementAgent' => 'mdm',
                'osVersion' => '14.2.1',
                'easActivated' => false,
                'easDeviceId' => '',
                'easActivationDateTime' => '0001-01-01T00:00:00Z',
                'azureADRegistered' => true,
                'deviceEnrollmentType' => 'userEnrollment',
                'activationLockBypassCode' => null,
                'emailAddress' => 'john.smith@company.com',
                'azureADDeviceId' => '22222222-3333-4444-5555-666666666666',
                'deviceRegistrationState' => 'registered',
                'deviceCategoryDisplayName' => 'Corporate',
                'isSupervised' => false,
                'exchangeLastSuccessfulSyncDateTime' => '0001-01-01T00:00:00Z',
                'exchangeAccessState' => 'none',
                'exchangeAccessStateReason' => 'none',
                'remoteAssistanceSessionUrl' => null,
                'remoteAssistanceSessionErrorDetails' => null,
                'isEncrypted' => true,
                'userPrincipalName' => 'john.smith@company.com',
                'model' => 'MacBookPro18,1',
                'manufacturer' => 'Apple',
                'imei' => '',
                'complianceGracePeriodExpirationDateTime' => '9999-12-31T23:59:59.9999999Z',
                'serialNumber' => 'C02ABC123DEF',
                'phoneNumber' => '',
                'androidSecurityPatchLevel' => '',
                'userDisplayName' => 'John Smith',
                'configurationManagerClientEnabledFeatures' => null,
                'wiFiMacAddress' => 'AA:BB:CC:DD:EE:FF',
                'deviceHealthAttestationState' => null,
                'subscriberCarrier' => '',
                'meid' => '',
                'totalStorageSpaceInBytes' => 1000204886016,
                'freeStorageSpaceInBytes' => 500102443008,
                'managedDeviceName' => 'Johns-MacBook-Pro',
                'partnerReportedThreatState' => 'available',
            ],
            // iPhone (no serial number - should be skipped)
            [
                'id' => '33345678-1234-1234-1234-123456789ghi',
                'userId' => '67654321-4321-4321-4321-cba987654323',
                'deviceName' => 'Janes iPhone',
                'managedDeviceOwnerType' => 'personal',
                'enrolledDateTime' => '2024-06-10T11:15:00Z',
                'lastSyncDateTime' => '2025-01-06T07:45:00Z',
                'operatingSystem' => 'iOS',
                'complianceState' => 'compliant',
                'jailBroken' => 'False',
                'managementAgent' => 'mdm',
                'osVersion' => '17.2.1',
                'easActivated' => false,
                'easDeviceId' => '',
                'easActivationDateTime' => '0001-01-01T00:00:00Z',
                'azureADRegistered' => true,
                'deviceEnrollmentType' => 'userEnrollment',
                'activationLockBypassCode' => null,
                'emailAddress' => 'jane.williams@company.com',
                'azureADDeviceId' => '33333333-4444-5555-6666-777777777777',
                'deviceRegistrationState' => 'registered',
                'deviceCategoryDisplayName' => 'BYOD',
                'isSupervised' => false,
                'exchangeLastSuccessfulSyncDateTime' => '0001-01-01T00:00:00Z',
                'exchangeAccessState' => 'none',
                'exchangeAccessStateReason' => 'none',
                'remoteAssistanceSessionUrl' => null,
                'remoteAssistanceSessionErrorDetails' => null,
                'isEncrypted' => true,
                'userPrincipalName' => 'jane.williams@company.com',
                'model' => 'iPhone 14 Pro',
                'manufacturer' => 'Apple Inc.',
                'imei' => '123456789012345',
                'complianceGracePeriodExpirationDateTime' => '9999-12-31T23:59:59.9999999Z',
                'serialNumber' => null, // Missing serial - should be skipped
                'phoneNumber' => '+1-555-0123',
                'androidSecurityPatchLevel' => '',
                'userDisplayName' => 'Jane Williams',
                'configurationManagerClientEnabledFeatures' => null,
                'wiFiMacAddress' => 'FF:EE:DD:CC:BB:AA',
                'deviceHealthAttestationState' => null,
                'subscriberCarrier' => 'Verizon',
                'meid' => '12345678901234',
                'totalStorageSpaceInBytes' => 256060514304,
                'freeStorageSpaceInBytes' => 128030257152,
                'managedDeviceName' => 'Janes iPhone',
                'partnerReportedThreatState' => 'available',
            ],
        ];
    }

    /**
     * Device with minimal fields (edge case)
     */
    public static function minimalDevice(): array
    {
        return [
            'id' => '44445678-1234-1234-1234-123456789jkl',
            'deviceName' => 'Unknown Device',
            'serialNumber' => 'MINIMAL123',
            'operatingSystem' => 'Unknown',
        ];
    }

    /**
     * OAuth token error response
     */
    public static function tokenErrorResponse(): array
    {
        return [
            'error' => 'invalid_client',
            'error_description' => 'AADSTS7000215: Invalid client secret provided.',
            'error_codes' => [7000215],
            'timestamp' => '2025-01-06 10:00:00Z',
            'trace_id' => 'abc123-def456-ghi789',
            'correlation_id' => 'xyz987-uvw654-rst321',
        ];
    }

    /**
     * API error response (unauthorized)
     */
    public static function unauthorizedResponse(): array
    {
        return [
            'error' => [
                'code' => 'Unauthorized',
                'message' => 'Access token has expired or is not yet valid.',
                'innerError' => [
                    'date' => '2025-01-06T10:00:00',
                    'request-id' => 'request-123-456-789',
                    'client-request-id' => 'client-123-456-789',
                ],
            ],
        ];
    }
}

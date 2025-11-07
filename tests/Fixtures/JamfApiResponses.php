<?php

namespace Tests\Fixtures;

/**
 * Realistic JAMF Pro Classic API responses
 * Based on: https://developer.jamf.com/jamf-pro/reference/
 */
class JamfApiResponses
{
    /**
     * Computers list response
     */
    public static function computersList(): array
    {
        return [
            'computers' => [
                ['id' => 1, 'name' => 'Johns-MacBook-Pro'],
                ['id' => 2, 'name' => 'Janes-iMac'],
            ],
        ];
    }

    /**
     * Empty computers list
     */
    public static function emptyComputersList(): array
    {
        return [
            'computers' => [],
        ];
    }

    /**
     * Computer detail response (ID 1)
     */
    public static function computerDetail(): array
    {
        return [
            'computer' => [
                'general' => [
                    'id' => 1,
                    'name' => 'Johns-MacBook-Pro',
                    'network_adapter_type' => 'Airport',
                    'mac_address' => 'AA:BB:CC:DD:EE:FF',
                    'alt_network_adapter_type' => '',
                    'alt_mac_address' => '',
                    'ip_address' => '192.168.1.100',
                    'last_reported_ip' => '192.168.1.100',
                    'serial_number' => 'C02ABC123DEF',
                    'udid' => '12345678-90AB-CDEF-1234-567890ABCDEF',
                    'jamf_version' => '11.2.1-t1234567890',
                    'platform' => 'Mac',
                    'barcode_1' => '',
                    'barcode_2' => '',
                    'asset_tag' => 'ASSET-001',
                    'remote_management' => [
                        'managed' => true,
                        'management_username' => 'admin',
                    ],
                    'supervised' => false,
                    'mdm_capable' => true,
                    'mdm_capable_users' => [
                        'mdm_capable_user' => 'jdoe',
                    ],
                    'management_status' => [
                        'enrolled_via_dep' => true,
                        'user_approved_enrollment' => true,
                        'user_approved_mdm' => true,
                    ],
                    'report_date' => '2025-01-06 09:30:00',
                    'report_date_epoch' => 1736156400000,
                    'report_date_utc' => '2025-01-06T14:30:00.000+0000',
                    'last_contact_time' => '2025-01-06 09:30:00',
                    'last_contact_time_epoch' => 1736156400000,
                    'last_contact_time_utc' => '2025-01-06T14:30:00.000+0000',
                    'initial_entry_date' => '2024-03-20',
                    'initial_entry_date_epoch' => 1710896400000,
                    'initial_entry_date_utc' => '2024-03-20T05:00:00.000+0000',
                    'last_cloud_backup_date_epoch' => 0,
                    'last_cloud_backup_date_utc' => '',
                    'last_enrolled_date_epoch' => 1710896400000,
                    'last_enrolled_date_utc' => '2024-03-20T05:00:00.000+0000',
                    'mdm_profile_expiration_epoch' => 1767636400000,
                    'mdm_profile_expiration_utc' => '2026-01-06T05:00:00.000+0000',
                    'distribution_point' => '',
                    'sus' => '',
                    'site' => [
                        'id' => -1,
                        'name' => 'None',
                    ],
                    'itunes_store_account_is_active' => false,
                ],
                'hardware' => [
                    'make' => 'Apple',
                    'model' => 'MacBook Pro (16-inch, 2021)',
                    'model_identifier' => 'MacBookPro18,1',
                    'os_name' => 'macOS',
                    'os_version' => '14.2.1',
                    'os_build' => '23C71',
                    'software_update_device_id' => 'J316sAP',
                    'active_directory_status' => 'Not Bound',
                    'service_pack' => '',
                    'processor_type' => 'Apple M1 Pro',
                    'is_apple_silicon' => true,
                    'processor_architecture' => 'arm64',
                    'processor_speed' => 3228,
                    'processor_speed_mhz' => 3228,
                    'number_processors' => 1,
                    'number_cores' => 10,
                    'total_ram' => 32768,
                    'total_ram_mb' => 32768,
                    'boot_rom' => '8419.41.10',
                    'bus_speed' => 0,
                    'bus_speed_mhz' => 0,
                    'battery_capacity' => 100,
                    'cache_size' => 0,
                    'cache_size_kb' => 0,
                    'available_ram_slots' => 0,
                    'optical_drive' => '',
                    'nic_speed' => 'n/a',
                    'smc_version' => '',
                    'ble_capable' => true,
                    'supports_ios_app_installs' => true,
                    'sip_status' => 'Enabled',
                    'gatekeeper_status' => 'App Store and identified developers',
                    'xprotect_version' => '5153',
                    'institutional_recovery_key' => 'Not Present',
                    'disk_encryption_configuration' => 'FileVault 2',
                    'filevault2_users' => ['jdoe'],
                    'storage' => [
                        [
                            'disk' => 'disk0',
                            'model' => 'APPLE SSD AP1024R',
                            'revision' => '1139.41',
                            'serial_number' => 'SSD123456789',
                            'size' => 1000204886016,
                            'drive_capacity_mb' => 953869,
                            'connection_type' => 'NO',
                            'smart_status' => 'Verified',
                            'partitions' => [
                                [
                                    'name' => 'Macintosh HD',
                                    'size' => 494384795648,
                                    'type' => 'boot',
                                    'partition_capacity_mb' => 471521,
                                    'percentage_full' => 52,
                                    'available_mb' => 226000,
                                    'filevault_status' => 'Encrypted',
                                    'filevault_percent' => 100,
                                    'filevault2_status' => 'Encrypted',
                                    'filevault2_percent' => 100,
                                    'boot_drive_available_mb' => 226000,
                                    'lvgUUID' => '',
                                    'lvUUID' => '',
                                    'pvUUID' => '',
                                ],
                            ],
                        ],
                    ],
                ],
                'location' => [
                    'username' => 'jdoe',
                    'realname' => 'John Doe',
                    'real_name' => 'John Doe',
                    'email_address' => 'john.doe@company.com',
                    'position' => 'Software Engineer',
                    'phone' => '555-0123',
                    'phone_number' => '555-0123',
                    'department' => 'Engineering',
                    'building' => 'Main Office',
                    'room' => '301',
                ],
                'purchasing' => [
                    'is_purchased' => true,
                    'is_leased' => false,
                    'po_number' => 'PO-2021-1234',
                    'vendor' => 'Apple',
                    'applecare_id' => '',
                    'purchase_price' => '$2499.00',
                    'purchasing_account' => 'IT Budget',
                    'po_date' => '2021-12-01',
                    'po_date_epoch' => 1638316800000,
                    'po_date_utc' => '2021-12-01T00:00:00.000+0000',
                    'warranty_expires' => '2024-12-01',
                    'warranty_expires_epoch' => 1733011200000,
                    'warranty_expires_utc' => '2024-12-01T00:00:00.000+0000',
                    'lease_expires' => '',
                    'lease_expires_epoch' => 0,
                    'lease_expires_utc' => '',
                    'life_expectancy' => 0,
                    'purchasing_contact' => 'IT Procurement',
                    'os_applecare_id' => '',
                    'os_maintenance_expires' => '',
                    'attachments' => [],
                ],
            ],
        ];
    }

    /**
     * Computer detail with minimal fields (edge case)
     */
    public static function computerDetailMinimal(): array
    {
        return [
            'computer' => [
                'general' => [
                    'id' => 2,
                    'name' => 'Janes-iMac',
                    'serial_number' => 'C02XYZ789GHI',
                ],
                'hardware' => [
                    'model' => 'iMac (24-inch, M1, 2021)',
                    'os_version' => '14.1',
                ],
                'location' => [],
                'purchasing' => [],
            ],
        ];
    }

    /**
     * Computer detail without serial number (should be skipped)
     */
    public static function computerDetailNoSerial(): array
    {
        return [
            'computer' => [
                'general' => [
                    'id' => 3,
                    'name' => 'Test-Mac',
                    'serial_number' => null,
                ],
                'hardware' => [
                    'model' => 'MacBook Air',
                    'os_version' => '13.5',
                ],
                'location' => [],
                'purchasing' => [],
            ],
        ];
    }

    /**
     * Mobile devices list response
     */
    public static function mobileDevicesList(): array
    {
        return [
            'mobile_devices' => [
                ['id' => 1, 'name' => 'Johns-iPad'],
                ['id' => 2, 'name' => 'Janes-iPhone'],
            ],
        ];
    }

    /**
     * Empty mobile devices list
     */
    public static function emptyMobileDevicesList(): array
    {
        return [
            'mobile_devices' => [],
        ];
    }

    /**
     * Mobile device detail response (iPad)
     */
    public static function mobileDeviceDetail(): array
    {
        return [
            'mobile_device' => [
                'general' => [
                    'id' => 1,
                    'display_name' => 'Johns iPad Pro',
                    'name' => 'Johns-iPad',
                    'asset_tag' => 'IPAD-001',
                    'last_inventory_update' => '2025-01-06 08:45:00',
                    'last_inventory_update_epoch' => 1736153100000,
                    'last_inventory_update_utc' => '2025-01-06T13:45:00.000+0000',
                    'capacity' => '256 GB',
                    'capacity_mb' => 262144,
                    'available' => '128 GB',
                    'available_mb' => 131072,
                    'percentage_used' => 50,
                    'os_type' => 'iOS',
                    'os_version' => '17.2.1',
                    'os_build' => '21C66',
                    'software_update_device_id' => 'J517AP',
                    'serial_number' => 'DMPABC123DEF',
                    'udid' => '00001234-000A00B12C3D456E',
                    'initial_entry_date_epoch' => 1710896400000,
                    'initial_entry_date_utc' => '2024-03-20T05:00:00.000+0000',
                    'phone_number' => '',
                    'ip_address' => '192.168.1.101',
                    'wifi_mac_address' => 'FF:EE:DD:CC:BB:AA',
                    'bluetooth_mac_address' => 'FF:EE:DD:CC:BB:AB',
                    'modem_firmware' => '',
                    'model' => 'iPad Pro 12.9-inch (5th generation)',
                    'model_identifier' => 'iPad13,8',
                    'model_number' => 'MHNH3LL/A',
                    'modelDisplay' => 'iPad Pro 12.9-inch (5th generation)',
                    'device_name' => 'Johns-iPad',
                    'device_id' => '00001234-000A00B12C3D456E',
                    'site' => [
                        'id' => -1,
                        'name' => 'None',
                    ],
                ],
                'location' => [
                    'username' => 'jdoe',
                    'realname' => 'John Doe',
                    'real_name' => 'John Doe',
                    'email_address' => 'john.doe@company.com',
                    'position' => 'Software Engineer',
                    'phone' => '',
                    'phone_number' => '',
                    'department' => 'Engineering',
                    'building' => 'Main Office',
                    'room' => '301',
                ],
                'purchasing' => [
                    'is_purchased' => true,
                    'is_leased' => false,
                    'po_number' => 'PO-2022-5678',
                    'vendor' => 'Apple',
                    'applecare_id' => '',
                    'purchase_price' => '$1099.00',
                    'purchasing_account' => 'IT Budget',
                    'po_date' => '2022-03-15',
                    'po_date_epoch' => 1647302400000,
                    'po_date_utc' => '2022-03-15T00:00:00.000+0000',
                    'warranty_expires' => '2025-03-15',
                    'warranty_expires_epoch' => 1741996800000,
                    'warranty_expires_utc' => '2025-03-15T00:00:00.000+0000',
                    'lease_expires' => '',
                    'lease_expires_epoch' => 0,
                    'lease_expires_utc' => '',
                    'life_expectancy' => 0,
                    'purchasing_contact' => 'IT Procurement',
                    'attachments' => [],
                ],
            ],
        ];
    }

    /**
     * Mobile device detail with minimal fields
     */
    public static function mobileDeviceDetailMinimal(): array
    {
        return [
            'mobile_device' => [
                'general' => [
                    'id' => 2,
                    'name' => 'Janes-iPhone',
                    'serial_number' => 'FGHABC789XYZ',
                    'model' => 'iPhone 14 Pro',
                    'os_version' => '17.1',
                ],
                'location' => [],
                'purchasing' => [],
            ],
        ];
    }
}

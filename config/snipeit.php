<?php

return [
    'default_status_id' => env('SNIPEIT_DEFAULT_STATUS_ID', 2),
    'default_category_id' => env('SNIPEIT_DEFAULT_CATEGORY_ID', 1),
    'default_model_id' => env('SNIPEIT_DEFAULT_MODEL_ID', 1),
    'default_manufacturer_id' => env('SNIPEIT_DEFAULT_MANUFACTURER_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Intune Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for syncing devices from Microsoft Intune
    |
    */

    // Default category ID for Intune-synced devices (null = auto-create "Intune Devices" category)
    'intune_default_category_id' => env('INTUNE_DEFAULT_CATEGORY_ID', null),

    // Default status ID for Intune-synced devices (null = use "Ready to Deploy" or first deployable status)
    'intune_default_status_id' => env('INTUNE_DEFAULT_STATUS_ID', null),

    // Default location ID for Intune-synced devices (null = no default location)
    'intune_default_location_id' => env('INTUNE_DEFAULT_LOCATION_ID', null),

    // Number of devices to process per batch job
    'intune_sync_chunk_size' => env('INTUNE_SYNC_CHUNK_SIZE', 50),

    // Whether to automatically assign assets to users based on userPrincipalName (email)
    'intune_auto_assign_users' => env('INTUNE_AUTO_ASSIGN_USERS', false),

    /*
    |--------------------------------------------------------------------------
    | JAMF Pro Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for syncing devices from JAMF Pro
    |
    */

    // Default category ID for JAMF computers (null = auto-create "JAMF Computers" category)
    'jamf_default_category_id_computers' => env('JAMF_DEFAULT_CATEGORY_ID_COMPUTERS', null),

    // Default category ID for JAMF mobile devices (null = auto-create "JAMF Mobile Devices" category)
    'jamf_default_category_id_mobile' => env('JAMF_DEFAULT_CATEGORY_ID_MOBILE', null),

    // Default status ID for JAMF-synced devices (null = use "Ready to Deploy" or first deployable status)
    'jamf_default_status_id' => env('JAMF_DEFAULT_STATUS_ID', null),

    // Default location ID for JAMF-synced devices (null = match by JAMF location name or no default)
    'jamf_default_location_id' => env('JAMF_DEFAULT_LOCATION_ID', null),

    // Number of devices to process per batch job
    'jamf_sync_chunk_size' => env('JAMF_SYNC_CHUNK_SIZE', 50),

    // Whether to automatically assign assets to users based on email or username
    'jamf_auto_assign_users' => env('JAMF_AUTO_ASSIGN_USERS', false),

    // Whether to sync computers from JAMF Pro
    'jamf_sync_computers' => env('JAMF_SYNC_COMPUTERS', true),

    // Whether to sync mobile devices from JAMF Pro
    'jamf_sync_mobile_devices' => env('JAMF_SYNC_MOBILE_DEVICES', true),
];
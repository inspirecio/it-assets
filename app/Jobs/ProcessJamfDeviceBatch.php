<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\HuntressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

class ProcessJamfDeviceBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes per batch

    protected $devices;
    protected $batchNumber;

    public function __construct($devices, $batchNumber = 0)
    {
        $this->devices = $devices;
        $this->batchNumber = $batchNumber;
    }

    public function handle()
    {
        Log::info("Processing JAMF batch #{$this->batchNumber} with " . count($this->devices) . " devices");

        $huntressService = app(HuntressApiService::class);
        $huntressConfigured = $huntressService->isConfigured();

        if (!$huntressConfigured) {
            Log::debug('Huntress API is not configured; skipping Huntress enrichment for this batch.');
        }

        $synced = 0;
        $updated = 0;
        $created = 0;
        $errors = 0;

        foreach ($this->devices as $device) {
            try {
                $result = $this->syncDeviceToDatabase($device, $huntressConfigured ? $huntressService : null);

                if ($result) {
                    $synced++;
                    $status = $result['status'] ?? null;
                    if ($status === 'created') {
                        $created++;
                    } elseif ($status === 'updated') {
                        $updated++;
                    }
                } else {
                    $errors++;
                }
            } catch (\Exception $e) {
                $deviceName = $this->getDeviceName($device);
                Log::error("Error syncing device {$deviceName}: " . $e->getMessage());
                Log::error($e->getTraceAsString());
                $errors++;
            }
        }

        Log::info("JAMF Batch #{$this->batchNumber} completed: {$synced} synced ({$created} created, {$updated} updated), {$errors} errors");
    }

    /**
     * Sync a single device to Snipe-IT database
     *
     * @return string|false 'created', 'updated', or false on failure
     */
    private function syncDeviceToDatabase($device, ?HuntressApiService $huntressService)
    {
        $deviceType = $device['_device_type'] ?? 'computer';

        $result = $deviceType === 'computer'
            ? $this->syncComputer($device)
            : $this->syncMobileDevice($device);

        if ($result && $huntressService && isset($result['asset']) && $result['asset'] instanceof Asset) {
            $this->syncHuntressData($result['asset'], $huntressService);
        }

        return $result;
    }

    /**
     * Sync a JAMF computer to database
     */
    private function syncComputer($device)
    {
        $general = $device['general'] ?? [];
        $hardware = $device['hardware'] ?? [];
        $location = $device['location'] ?? [];
        $purchasing = $device['purchasing'] ?? [];

        // Extract computer information
        $serialNumber = $general['serial_number'] ?? null;
        $deviceName = $general['name'] ?? 'Unknown Computer';
        $modelName = $hardware['model'] ?? ($hardware['model_identifier'] ?? 'Unknown Model');
        $manufacturerName = 'Apple'; // JAMF only manages Apple devices
        $osVersion = $hardware['os_version'] ?? ($general['platform'] ?? '');
        $userEmail = $location['email_address'] ?? null;
        $userName = $location['username'] ?? null;
        $locationName = $location['building'] ?? null;
        $purchaseDate = $purchasing['po_date'] ?? ($purchasing['purchase_date'] ?? null);
        $purchaseCost = $purchasing['purchase_price'] ?? null;
        $poNumber = $purchasing['po_number'] ?? null;

        // Validate serial number
        if (!$serialNumber) {
            Log::warning("Computer {$deviceName} has no serial number, skipping");
            return false;
        }

        // Use database transaction for data integrity
        return DB::transaction(function () use (
            $serialNumber, $deviceName, $modelName, $manufacturerName,
            $osVersion, $userEmail, $userName, $locationName,
            $purchaseDate, $purchaseCost, $poNumber
        ) {
            // Get or create manufacturer
            $manufacturer = $this->getOrCreateManufacturer($manufacturerName);

            // Get or create model
            $model = $this->getOrCreateModel($modelName, $manufacturer->id, 'computer');

            // Get default status
            $statusId = $this->getDefaultStatusId();

            // Get location by name if available
            $locationId = $this->getLocationByName($locationName) ?? config('snipeit.jamf_default_location_id', null);

            // Find user by email if configured and available
            $assignedTo = null;
            if (config('snipeit.jamf_auto_assign_users', false)) {
                if ($userEmail) {
                    $user = User::where('email', $userEmail)->first();
                    if ($user) {
                        $assignedTo = $user->id;
                    }
                } elseif ($userName) {
                    // Try username if email not available
                    $user = User::where('username', $userName)->first();
                    if ($user) {
                        $assignedTo = $user->id;
                    }
                }
            }

            // Prepare notes
            $notes = "Synced from JAMF Pro\n";
            $notes .= "Device Type: Computer\n";
            $notes .= "OS Version: {$osVersion}\n";
            if ($userEmail) {
                $notes .= "User Email: {$userEmail}\n";
            }
            if ($userName) {
                $notes .= "Username: {$userName}\n";
            }
            if ($locationName) {
                $notes .= "JAMF Location: {$locationName}\n";
            }

            // Check if asset exists
            $existingAsset = Asset::where('serial', $serialNumber)
                ->withTrashed()
                ->first();

            $wasCreated = !$existingAsset;

            // Prepare purchase date
            $formattedPurchaseDate = null;
            if ($purchaseDate) {
                try {
                    $formattedPurchaseDate = date('Y-m-d', strtotime($purchaseDate));
                } catch (\Exception $e) {
                    Log::warning("Invalid purchase date format: {$purchaseDate}");
                }
            }

            // Create or update asset
            $asset = Asset::updateOrCreate(
                ['serial' => $serialNumber],
                [
                    'asset_tag' => $serialNumber, // Using serial as asset tag
                    'name' => $deviceName,
                    'model_id' => $model->id,
                    'status_id' => $statusId,
                    'notes' => $notes,
                    'location_id' => $locationId,
                    'assigned_to' => $assignedTo,
                    'assigned_type' => $assignedTo ? User::class : null,
                    'purchase_date' => $formattedPurchaseDate,
                    'purchase_cost' => $purchaseCost ? (float)str_replace(['$', ','], '', $purchaseCost) : null,
                    'order_number' => $poNumber,
                ]
            );

            // Restore if soft-deleted
            if ($existingAsset && $existingAsset->trashed()) {
                $asset->restore();
                Log::info("Restored soft-deleted asset: {$deviceName} (Serial: {$serialNumber})");
            }

            $result = [
                'status' => $wasCreated ? 'created' : 'updated',
                'asset' => $asset->fresh(),
            ];

            if ($wasCreated) {
                Log::info("Created computer: {$deviceName} (Serial: {$serialNumber})");
            } else {
                Log::info("Updated computer: {$deviceName} (Serial: {$serialNumber})");
            }

            return $result;
        });
    }

    /**
     * Sync a JAMF mobile device to database
     */
    private function syncMobileDevice($device)
    {
        $general = $device['general'] ?? [];
        $location = $device['location'] ?? [];
        $purchasing = $device['purchasing'] ?? [];

        // Extract mobile device information
        $serialNumber = $general['serial_number'] ?? null;
        $deviceName = $general['name'] ?? ($general['display_name'] ?? 'Unknown Mobile Device');
        $modelName = $general['model'] ?? ($general['model_identifier'] ?? 'Unknown Model');
        $manufacturerName = 'Apple'; // JAMF only manages Apple devices
        $osVersion = $general['os_version'] ?? '';
        $capacity = $general['capacity'] ?? '';
        $userEmail = $location['email_address'] ?? null;
        $userName = $location['username'] ?? null;
        $locationName = $location['building'] ?? null;
        $purchaseDate = $purchasing['po_date'] ?? ($purchasing['purchase_date'] ?? null);
        $purchaseCost = $purchasing['purchase_price'] ?? null;
        $poNumber = $purchasing['po_number'] ?? null;

        // Validate serial number
        if (!$serialNumber) {
            Log::warning("Mobile device {$deviceName} has no serial number, skipping");
            return false;
        }

        // Use database transaction for data integrity
        return DB::transaction(function () use (
            $serialNumber, $deviceName, $modelName, $manufacturerName,
            $osVersion, $capacity, $userEmail, $userName, $locationName,
            $purchaseDate, $purchaseCost, $poNumber
        ) {
            // Get or create manufacturer
            $manufacturer = $this->getOrCreateManufacturer($manufacturerName);

            // Get or create model
            $model = $this->getOrCreateModel($modelName, $manufacturer->id, 'mobile_device');

            // Get default status
            $statusId = $this->getDefaultStatusId();

            // Get location by name if available
            $locationId = $this->getLocationByName($locationName) ?? config('snipeit.jamf_default_location_id', null);

            // Find user by email if configured and available
            $assignedTo = null;
            if (config('snipeit.jamf_auto_assign_users', false)) {
                if ($userEmail) {
                    $user = User::where('email', $userEmail)->first();
                    if ($user) {
                        $assignedTo = $user->id;
                    }
                } elseif ($userName) {
                    $user = User::where('username', $userName)->first();
                    if ($user) {
                        $assignedTo = $user->id;
                    }
                }
            }

            // Prepare notes
            $notes = "Synced from JAMF Pro\n";
            $notes .= "Device Type: Mobile Device\n";
            $notes .= "OS Version: {$osVersion}\n";
            if ($capacity) {
                $notes .= "Capacity: {$capacity}\n";
            }
            if ($userEmail) {
                $notes .= "User Email: {$userEmail}\n";
            }
            if ($userName) {
                $notes .= "Username: {$userName}\n";
            }
            if ($locationName) {
                $notes .= "JAMF Location: {$locationName}\n";
            }

            // Check if asset exists
            $existingAsset = Asset::where('serial', $serialNumber)
                ->withTrashed()
                ->first();

            $wasCreated = !$existingAsset;

            // Prepare purchase date
            $formattedPurchaseDate = null;
            if ($purchaseDate) {
                try {
                    $formattedPurchaseDate = date('Y-m-d', strtotime($purchaseDate));
                } catch (\Exception $e) {
                    Log::warning("Invalid purchase date format: {$purchaseDate}");
                }
            }

            // Create or update asset
            $asset = Asset::updateOrCreate(
                ['serial' => $serialNumber],
                [
                    'asset_tag' => $serialNumber,
                    'name' => $deviceName,
                    'model_id' => $model->id,
                    'status_id' => $statusId,
                    'notes' => $notes,
                    'location_id' => $locationId,
                    'assigned_to' => $assignedTo,
                    'assigned_type' => $assignedTo ? User::class : null,
                    'purchase_date' => $formattedPurchaseDate,
                    'purchase_cost' => $purchaseCost ? (float)str_replace(['$', ','], '', $purchaseCost) : null,
                    'order_number' => $poNumber,
                ]
            );

            // Restore if soft-deleted
            if ($existingAsset && $existingAsset->trashed()) {
                $asset->restore();
                Log::info("Restored soft-deleted mobile device: {$deviceName} (Serial: {$serialNumber})");
            }

            $result = [
                'status' => $wasCreated ? 'created' : 'updated',
                'asset' => $asset->fresh(),
            ];

            if ($wasCreated) {
                Log::info("Created mobile device: {$deviceName} (Serial: {$serialNumber})");
            } else {
                Log::info("Updated mobile device: {$deviceName} (Serial: {$serialNumber})");
            }

            return $result;
        });
    }

    private function syncHuntressData(Asset $asset, HuntressApiService $huntressService): void
    {
        $serialNumber = $asset->serial;

        if (!$serialNumber) {
            return;
        }

        $agent = $huntressService->findAgentBySerial($serialNumber);

        if (!$agent) {
            Log::debug("No Huntress agent found for serial {$serialNumber}; clearing Huntress fields if present.");
            $this->persistHuntressFields($asset, $this->buildHuntressFieldMap([], [], []));

            return;
        }

        $agentId = Arr::get($agent, 'id');

        if (!$agentId) {
            Log::warning("Huntress agent payload missing ID for serial {$serialNumber}; skipping Huntress enrichment.");

            return;
        }

        $incidents = $huntressService->getIncidentsForAgent($agentId, 3);
        $remediations = $huntressService->getRemediationsForAgent($agentId, 3);

        $fields = $this->buildHuntressFieldMap($agent, $incidents, $remediations);

        $this->persistHuntressFields($asset, $fields);
    }

    private function buildHuntressFieldMap(array $agent, array $incidents, array $remediations): array
    {
        $fields = [];

        foreach ($this->huntressFieldSlugs() as $slug) {
            $fields[$this->customFieldColumn($slug)] = null;
        }

        if (!empty($agent)) {
            $fields[$this->customFieldColumn('huntress_agent_id')] = $this->encodeValue(Arr::get($agent, 'id'));
            $fields[$this->customFieldColumn('huntress_device_name')] = $this->encodeValue(Arr::get($agent, 'device_name'));
            $fields[$this->customFieldColumn('huntress_hostname')] = $this->encodeValue(Arr::get($agent, 'hostname'));
            $fields[$this->customFieldColumn('huntress_os_name')] = $this->encodeValue(Arr::get($agent, 'os.name'));
            $fields[$this->customFieldColumn('huntress_os_version')] = $this->encodeValue(Arr::get($agent, 'os.version'));
            $fields[$this->customFieldColumn('huntress_os_architecture')] = $this->encodeValue(Arr::get($agent, 'os.architecture'));
            $fields[$this->customFieldColumn('huntress_ip_addresses')] = $this->formatSimpleList(Arr::get($agent, 'ip_addresses', []));
            $fields[$this->customFieldColumn('huntress_mac_addresses')] = $this->formatSimpleList(Arr::get($agent, 'mac_addresses', []));
            $fields[$this->customFieldColumn('huntress_last_seen_at')] = $this->encodeValue(Arr::get($agent, 'last_seen_at'));
            $fields[$this->customFieldColumn('huntress_is_online')] = $this->formatBoolean(Arr::get($agent, 'is_online'));
            $fields[$this->customFieldColumn('huntress_is_decommissioned')] = $this->formatBoolean(Arr::get($agent, 'is_decommissioned'));
            $fields[$this->customFieldColumn('huntress_installation_status')] = $this->encodeValue(Arr::get($agent, 'installation_status'));
        }

        $fields[$this->customFieldColumn('huntress_incident_id')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'id'));
        $fields[$this->customFieldColumn('huntress_incident_agent_id')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'agent_id'));
        $fields[$this->customFieldColumn('huntress_incident_type')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'type'));
        $fields[$this->customFieldColumn('huntress_incident_status')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'status'));
        $fields[$this->customFieldColumn('huntress_incident_severity')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'severity'));
        $fields[$this->customFieldColumn('huntress_incident_detected_at')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'detected_at'));
        $fields[$this->customFieldColumn('huntress_incident_resolved_at')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'resolved_at'));
        $fields[$this->customFieldColumn('huntress_incident_title')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'title'));
        $fields[$this->customFieldColumn('huntress_incident_summary')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'summary'));
        $fields[$this->customFieldColumn('huntress_incident_detection_method')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'detection_method'));
        $fields[$this->customFieldColumn('huntress_incident_evidence')] = $this->formatEnumeratedList($incidents, fn ($incident) => $this->encodeValue(Arr::get($incident, 'evidence')));
        $fields[$this->customFieldColumn('huntress_incident_recommendation')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'recommendation'));
        $fields[$this->customFieldColumn('huntress_incident_is_false_positive')] = $this->formatEnumeratedList($incidents, fn ($incident) => $this->formatBoolean(Arr::get($incident, 'is_false_positive')));
        $fields[$this->customFieldColumn('huntress_incident_assigned_to')] = $this->formatEnumeratedList($incidents, function ($incident) {
            $name = Arr::get($incident, 'assigned_to.name');
            $identifier = Arr::get($incident, 'assigned_to.id');

            return $name ?? $identifier;
        });
        $fields[$this->customFieldColumn('huntress_incident_created_at')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'created_at'));
        $fields[$this->customFieldColumn('huntress_incident_updated_at')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'updated_at'));

        $fields[$this->customFieldColumn('huntress_remediation_id')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'id'));
        $fields[$this->customFieldColumn('huntress_remediation_agent_id')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'agent_id'));
        $fields[$this->customFieldColumn('huntress_remediation_incident_id')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'incident_id'));
        $fields[$this->customFieldColumn('huntress_remediation_status')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'status'));
        $fields[$this->customFieldColumn('huntress_remediation_action_type')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'action_type'));
        $fields[$this->customFieldColumn('huntress_remediation_requested_at')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'requested_at'));
        $fields[$this->customFieldColumn('huntress_remediation_completed_at')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'completed_at'));
        $fields[$this->customFieldColumn('huntress_remediation_requested_by')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'requested_by.name') ?? Arr::get($remediation, 'requested_by.id'));
        $fields[$this->customFieldColumn('huntress_remediation_executed_by')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'executed_by.name') ?? Arr::get($remediation, 'executed_by.id'));
        $fields[$this->customFieldColumn('huntress_remediation_notes')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'notes'));
        $fields[$this->customFieldColumn('huntress_remediation_evidence')] = $this->formatEnumeratedList($remediations, fn ($remediation) => $this->encodeValue(Arr::get($remediation, 'evidence')));
        $fields[$this->customFieldColumn('huntress_remediation_created_at')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'created_at'));
        $fields[$this->customFieldColumn('huntress_remediation_updated_at')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'updated_at'));

        return $fields;
    }

    private function persistHuntressFields(Asset $asset, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $attributes = $asset->getAttributes();
        $dirty = false;

        foreach ($fields as $column => $value) {
            if (!array_key_exists($column, $attributes)) {
                Log::debug("Skipping Huntress field update for missing column {$column}.");
                continue;
            }

            if ($asset->getAttribute($column) === $value) {
                continue;
            }

            $asset->setAttribute($column, $value);
            $dirty = true;
        }

        if ($dirty) {
            $asset->save();
            Log::info("Updated Huntress custom fields for asset {$asset->asset_tag} (Serial: {$asset->serial})");
        }
    }

    private function huntressFieldSlugs(): array
    {
        return [
            'huntress_agent_id',
            'huntress_device_name',
            'huntress_hostname',
            'huntress_os_name',
            'huntress_os_version',
            'huntress_os_architecture',
            'huntress_ip_addresses',
            'huntress_mac_addresses',
            'huntress_last_seen_at',
            'huntress_is_online',
            'huntress_is_decommissioned',
            'huntress_installation_status',
            'huntress_incident_id',
            'huntress_incident_agent_id',
            'huntress_incident_type',
            'huntress_incident_status',
            'huntress_incident_severity',
            'huntress_incident_detected_at',
            'huntress_incident_resolved_at',
            'huntress_incident_title',
            'huntress_incident_summary',
            'huntress_incident_detection_method',
            'huntress_incident_evidence',
            'huntress_incident_recommendation',
            'huntress_incident_is_false_positive',
            'huntress_incident_assigned_to',
            'huntress_incident_created_at',
            'huntress_incident_updated_at',
            'huntress_remediation_id',
            'huntress_remediation_agent_id',
            'huntress_remediation_incident_id',
            'huntress_remediation_status',
            'huntress_remediation_action_type',
            'huntress_remediation_requested_at',
            'huntress_remediation_completed_at',
            'huntress_remediation_requested_by',
            'huntress_remediation_executed_by',
            'huntress_remediation_notes',
            'huntress_remediation_evidence',
            'huntress_remediation_created_at',
            'huntress_remediation_updated_at',
        ];
    }

    private function customFieldColumn(string $slug): string
    {
        return '_snipeit_' . $slug;
    }

    private function formatSimpleList(array $values): ?string
    {
        $values = array_values(array_filter($values, function ($value) {
            return !is_null($value) && $value !== '';
        }));

        if (empty($values)) {
            return null;
        }

        return implode("\n", array_map(fn ($value) => $this->encodeValue($value) ?? '', $values));
    }

    private function formatBoolean($value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }

        if ($value === false || $value === 0 || $value === '0') {
            return '0';
        }

        return (string) $value;
    }

    private function formatEnumeratedList(array $items, callable $resolver): ?string
    {
        if (empty($items)) {
            return null;
        }

        $lines = [];

        foreach ($items as $index => $item) {
            $value = $resolver($item, $index);

            if ($value === null || $value === '') {
                continue;
            }

            $encoded = $this->encodeValue($value);

            if ($encoded === null || $encoded === '') {
                continue;
            }

            $lines[] = ($index + 1) . ') ' . $encoded;
        }

        if (empty($lines)) {
            return null;
        }

        return implode("\n", $lines);
    }

    private function encodeValue($value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($value instanceof \JsonSerializable) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * Get device name from device data
     */
    private function getDeviceName($device)
    {
        $general = $device['general'] ?? [];
        return $general['name'] ?? ($general['display_name'] ?? 'Unknown Device');
    }

    /**
     * Get or create manufacturer (with caching)
     */
    private function getOrCreateManufacturer($manufacturerName)
    {
        $cacheKey = 'jamf_manufacturer_' . strtolower(str_replace(' ', '_', $manufacturerName));

        return Cache::remember($cacheKey, 3600, function () use ($manufacturerName) {
            return Manufacturer::firstOrCreate(
                ['name' => $manufacturerName],
                [
                    'name' => $manufacturerName,
                    'image' => null,
                ]
            );
        });
    }

    /**
     * Get or create asset model (with caching)
     */
    private function getOrCreateModel($modelName, $manufacturerId, $deviceType)
    {
        $cacheKey = 'jamf_model_' . strtolower(str_replace(' ', '_', $modelName)) . '_' . $manufacturerId;

        return Cache::remember($cacheKey, 3600, function () use ($modelName, $manufacturerId, $deviceType) {
            // Get default category for JAMF devices
            $categoryId = $this->getJamfCategory($deviceType);

            return AssetModel::firstOrCreate(
                [
                    'name' => $modelName,
                    'manufacturer_id' => $manufacturerId,
                ],
                [
                    'name' => $modelName,
                    'manufacturer_id' => $manufacturerId,
                    'category_id' => $categoryId,
                    'model_number' => null,
                ]
            );
        });
    }

    /**
     * Get or create the JAMF category based on device type
     */
    private function getJamfCategory($deviceType)
    {
        $cacheKey = 'jamf_category_' . $deviceType;

        return Cache::remember($cacheKey, 3600, function () use ($deviceType) {
            if ($deviceType === 'computer') {
                $categoryId = config('snipeit.jamf_default_category_id_computers');
                $categoryName = 'JAMF Computers';
            } else {
                $categoryId = config('snipeit.jamf_default_category_id_mobile');
                $categoryName = 'JAMF Mobile Devices';
            }

            // If configured category ID exists, use it
            if ($categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    return $category->id;
                }
            }

            // Otherwise, create or find category
            $category = Category::firstOrCreate(
                ['name' => $categoryName],
                [
                    'name' => $categoryName,
                    'category_type' => 'asset',
                ]
            );

            return $category->id;
        });
    }

    /**
     * Get default status label ID
     */
    private function getDefaultStatusId()
    {
        return Cache::remember('jamf_default_status', 3600, function () {
            $statusId = config('snipeit.jamf_default_status_id');

            // If configured status ID exists, use it
            if ($statusId) {
                $status = Statuslabel::find($statusId);
                if ($status) {
                    return $status->id;
                }
            }

            // Otherwise, try to find "Ready to Deploy" status
            $status = Statuslabel::where('name', 'Ready to Deploy')->first();

            if ($status) {
                return $status->id;
            }

            // Fallback to first deployable status
            $status = Statuslabel::where('deployable', 1)->first();

            if ($status) {
                return $status->id;
            }

            // Last resort: return ID 1
            Log::warning('No suitable status label found, using default ID 1');
            return 1;
        });
    }

    /**
     * Get location by name (with caching)
     */
    private function getLocationByName($locationName)
    {
        if (!$locationName) {
            return null;
        }

        $cacheKey = 'jamf_location_' . strtolower(str_replace(' ', '_', $locationName));

        return Cache::remember($cacheKey, 3600, function () use ($locationName) {
            $location = Location::where('name', $locationName)->first();
            return $location ? $location->id : null;
        });
    }
}

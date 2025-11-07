<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\HuntressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class SyncHuntressDataToAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200;

    private int $chunkSize;

    private int $incidentLimit;

    private int $remediationLimit;

    public function __construct(?int $chunkSize = null, ?int $incidentLimit = null, ?int $remediationLimit = null)
    {
        $this->chunkSize = max(1, $chunkSize ?? (int) config('services.huntress.chunk_size', 100));
        $this->incidentLimit = max(0, $incidentLimit ?? (int) config('services.huntress.incident_limit', 3));
        $this->remediationLimit = max(0, $remediationLimit ?? (int) config('services.huntress.remediation_limit', 3));
    }

    public function handle(HuntressApiService $huntressService): void
    {
        if (!$huntressService->isConfigured()) {
            Log::info('Huntress sync skipped because the API is not configured.');

            return;
        }

        $processed = 0;
        $updated = 0;
        $cleared = 0;

        Asset::query()
            ->whereNull('deleted_at')
            ->whereNotNull('serial')
            ->where('serial', '!=', '')
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($assets) use ($huntressService, &$processed, &$updated, &$cleared) {
                /** @var \App\Models\Asset $asset */
                foreach ($assets as $asset) {
                    $processed++;

                    try {
                        $result = $this->syncAsset($asset, $huntressService);
                    } catch (\Throwable $exception) {
                        Log::warning('Failed syncing Huntress data for asset.', [
                            'asset_id' => $asset->id,
                            'serial' => $asset->serial,
                            'message' => $exception->getMessage(),
                        ]);

                        continue;
                    }

                    if ($result === 'updated') {
                        $updated++;
                    } elseif ($result === 'cleared') {
                        $cleared++;
                    }
                }
            });

        Log::info('Completed Huntress asset sync.', [
            'processed' => $processed,
            'updated' => $updated,
            'cleared' => $cleared,
        ]);
    }

    private function syncAsset(Asset $asset, HuntressApiService $huntressService): string
    {
        $serialNumber = trim((string) $asset->serial);

        if ($serialNumber === '') {
            return $this->persistFields($asset, $this->blankFieldMap()) ? 'cleared' : 'skipped';
        }

        $agent = $huntressService->findAgentBySerial($serialNumber);

        if (!$agent) {
            Log::debug('No Huntress agent found for asset serial; clearing fields.', [
                'asset_id' => $asset->id,
                'serial' => $serialNumber,
            ]);

            return $this->persistFields($asset, $this->blankFieldMap()) ? 'cleared' : 'skipped';
        }

        $agentId = Arr::get($agent, 'id');

        if (!$agentId) {
            Log::warning('Huntress agent payload missing id; clearing Huntress fields.', [
                'asset_id' => $asset->id,
                'serial' => $serialNumber,
            ]);

            return $this->persistFields($asset, $this->blankFieldMap()) ? 'cleared' : 'skipped';
        }

        $incidents = $this->incidentLimit > 0
            ? $huntressService->getIncidentsForAgent($agentId, $this->incidentLimit)
            : [];

        $remediations = $this->remediationLimit > 0
            ? $huntressService->getRemediationsForAgent($agentId, $this->remediationLimit)
            : [];

        $fields = $this->buildFieldMap($agent, $incidents, $remediations);

        return $this->persistFields($asset, $fields) ? 'updated' : 'skipped';
    }

    private function buildFieldMap(array $agent, array $incidents, array $remediations): array
    {
        $fields = $this->blankFieldMap();

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
        $fields[$this->customFieldColumn('huntress_incident_created_at')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'created_at'));
        $fields[$this->customFieldColumn('huntress_incident_updated_at')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'updated_at'));
        $fields[$this->customFieldColumn('huntress_incident_closed_at')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'closed_at'));
        $fields[$this->customFieldColumn('huntress_incident_description')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'description'));
        $fields[$this->customFieldColumn('huntress_incident_remediation_steps')] = $this->formatEnumeratedList($incidents, fn ($incident) => Arr::get($incident, 'remediation_steps'));

        $fields[$this->customFieldColumn('huntress_remediation_id')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'id'));
        $fields[$this->customFieldColumn('huntress_remediation_incident_id')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'incident_id'));
        $fields[$this->customFieldColumn('huntress_remediation_status')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'status'));
        $fields[$this->customFieldColumn('huntress_remediation_type')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'type') ?? Arr::get($remediation, 'action_type'));
        $fields[$this->customFieldColumn('huntress_remediation_created_at')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'created_at'));
        $fields[$this->customFieldColumn('huntress_remediation_updated_at')] = $this->formatEnumeratedList($remediations, fn ($remediation) => Arr::get($remediation, 'updated_at'));

        return $fields;
    }

    private function blankFieldMap(): array
    {
        $map = [];

        foreach ($this->huntressFieldSlugs() as $slug) {
            $map[$this->customFieldColumn($slug)] = null;
        }

        return $map;
    }

    private function persistFields(Asset $asset, array $fields): bool
    {
        $attributes = $asset->getAttributes();
        $dirty = false;

        foreach ($fields as $column => $value) {
            if (!array_key_exists($column, $attributes)) {
                Log::debug('Skipping Huntress field update because column is missing on asset.', [
                    'asset_id' => $asset->id,
                    'column' => $column,
                ]);

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

            Log::info('Updated Huntress custom fields for asset.', [
                'asset_id' => $asset->id,
                'asset_tag' => $asset->asset_tag,
                'serial' => $asset->serial,
            ]);
        }

        return $dirty;
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
            'huntress_incident_created_at',
            'huntress_incident_updated_at',
            'huntress_incident_closed_at',
            'huntress_incident_description',
            'huntress_incident_remediation_steps',
            'huntress_remediation_id',
            'huntress_remediation_incident_id',
            'huntress_remediation_status',
            'huntress_remediation_type',
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
        $values = array_values(array_filter($values, fn ($value) => $value !== null && $value !== ''));

        if (empty($values)) {
            return null;
        }

        return implode("\n", array_map(fn ($value) => $this->encodeValue($value) ?? '', $values));
    }

    private function formatBoolean($value): ?string
    {
        if ($value === null) {
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
        if ($value === null) {
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
}



<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HuntressApiService
{
    private string $baseUrl;

    private ?string $apiKey;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.huntress.url', 'https://api.huntress.io'), '/');
        $this->apiKey = config('services.huntress.api_key');
        $this->timeout = (int) config('services.huntress.timeout', 15);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function findAgentBySerial(string $serialNumber): ?array
    {
        $response = $this->get('/v1/agents', [
            'serial_number' => $serialNumber,
            'per_page' => 1,
        ]);

        $agents = $this->extractData($response, 'agents');

        return $agents[0] ?? null;
    }

    public function getIncidentsForAgent(string $agentId, int $limit = 3): array
    {
        $response = $this->get('/v1/incidents', [
            'agent_id' => $agentId,
            'per_page' => $limit,
            'sort' => '-detected_at',
        ]);

        $incidents = $this->extractData($response, 'incidents');

        return array_slice($incidents, 0, $limit);
    }

    public function getRemediationsForAgent(string $agentId, int $limit = 3): array
    {
        $response = $this->get('/v1/remediations', [
            'agent_id' => $agentId,
            'per_page' => $limit,
            'sort' => '-requested_at',
        ]);

        $remediations = $this->extractData($response, 'remediations');

        return array_slice($remediations, 0, $limit);
    }

    private function get(string $endpoint, array $query = []): ?array
    {
        return $this->request('get', $endpoint, $query);
    }

    private function request(string $method, string $endpoint, array $query = []): ?array
    {
        if (!$this->isConfigured()) {
            Log::warning('Huntress API key missing; skipping Huntress API request.');

            return null;
        }

        $url = ltrim($endpoint, '/');

        try {
            $response = $this->http()
                ->{$method}($url, $query);
        } catch (\Throwable $exception) {
            Log::warning('Huntress API request failed.', [
                'endpoint' => $endpoint,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('Huntress API responded with non-success status.', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    private function http()
    {
        return Http::withHeaders($this->buildHeaders())
            ->timeout($this->timeout)
            ->retry(2, 500)
            ->acceptJson()
            ->baseUrl($this->baseUrl);
    }

    private function buildHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if (empty($this->apiKey)) {
            return $headers;
        }

        if (Str::contains($this->apiKey, ':')) {
            $headers['Authorization'] = 'Basic '.base64_encode($this->apiKey);
        } else {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        return $headers;
    }

    private function extractData(?array $response, string $collectionKey): array
    {
        if (!$response) {
            return [];
        }

        $data = Arr::get($response, $collectionKey);

        if (is_array($data)) {
            return $data;
        }

        $data = Arr::get($response, 'data');

        if (is_array($data)) {
            return $data;
        }

        return [];
    }
}



<?php
/**
 * Odoo API Pool — Parallel HTTP Execution
 *
 * Uses curl_multi to execute multiple Odoo API calls concurrently.
 * Critical for dashboard pages that need profile + credit + orders + invoices
 * in a single page load — runs them in parallel instead of sequentially.
 *
 * Usage:
 *   $pool = new OdooAPIPool($apiKey, $baseUrl);
 *   $pool->add('profile', '/reya/user/profile', ['line_user_id' => $uid]);
 *   $pool->add('credit',  '/reya/credit-status', ['line_user_id' => $uid]);
 *   $pool->add('orders',  '/reya/orders', ['line_user_id' => $uid, 'limit' => 10]);
 *   $results = $pool->execute();
 *   // $results['profile'] = ['success' => true, 'data' => ...] 
 *   // $results['credit']  = ['success' => true, 'data' => ...]
 *   // etc.
 */
class OdooAPIPool
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;

    /** @var array<string, array{endpoint: string, params: array}> */
    private array $requests = [];

    /**
     * @param string $apiKey       X-Api-Key for Odoo
     * @param string $baseUrl      Base URL of the Odoo instance
     * @param int    $timeout      Per-request timeout in seconds
     * @param int    $connectTimeout Connect timeout in seconds
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        int $timeout = 15,
        int $connectTimeout = 5
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Add a request to the pool.
     *
     * @param string $key      Unique key to identify this request in results
     * @param string $endpoint API endpoint (e.g. '/reya/orders')
     * @param array  $params   JSON-RPC params
     * @return $this
     */
    public function add(string $key, string $endpoint, array $params = []): self
    {
        $this->requests[$key] = [
            'endpoint' => $endpoint,
            'params' => $params,
        ];
        return $this;
    }

    /**
     * Execute all queued requests in parallel.
     *
     * @return array<string, array{success: bool, data?: array, error?: string, http_code: int, duration_ms: int}>
     */
    public function execute(): array
    {
        if (empty($this->requests)) {
            return [];
        }

        // For a single request, skip curl_multi overhead
        if (count($this->requests) === 1) {
            return $this->executeSingle();
        }

        $mh = curl_multi_init();

        // Limit concurrent connections to avoid overwhelming the server
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 6);
        }

        $handles = [];
        $startTimes = [];

        foreach ($this->requests as $key => $req) {
            $ch = $this->createHandle($req['endpoint'], $req['params']);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
            $startTimes[$key] = microtime(true);
        }

        // Execute all handles
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status > CURLM_OK) {
                break;
            }
            // Wait for activity (max 100ms poll interval)
            if ($running > 0) {
                curl_multi_select($mh, 0.1);
            }
        } while ($running > 0);

        // Collect results
        $results = [];
        foreach ($handles as $key => $ch) {
            $duration = round((microtime(true) - $startTimes[$key]) * 1000);
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            $results[$key] = $this->parseResponse($response, $httpCode, $curlError, $duration);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        $this->requests = [];

        return $results;
    }

    /**
     * Optimized path for single request (avoids curl_multi overhead).
     */
    private function executeSingle(): array
    {
        $key = array_key_first($this->requests);
        $req = $this->requests[$key];

        $ch = $this->createHandle($req['endpoint'], $req['params']);
        $startTime = microtime(true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $duration = round((microtime(true) - $startTime) * 1000);

        $this->requests = [];

        return [$key => $this->parseResponse($response, $httpCode, $curlError, $duration)];
    }

    /**
     * Create a configured cURL handle for a JSON-RPC request.
     */
    private function createHandle(string $endpoint, array $params)
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'params' => $params,
        ]);

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $this->apiKey,
                'Connection: keep-alive',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_ENCODING => '',
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);

        return $ch;
    }

    /**
     * Parse a cURL response into a standardized result array.
     */
    private function parseResponse($response, int $httpCode, string $curlError, int $durationMs): array
    {
        if ($response === false || $curlError !== '') {
            return [
                'success' => false,
                'error' => 'NETWORK_ERROR: ' . ($curlError ?: 'empty response'),
                'http_code' => 0,
                'duration_ms' => $durationMs,
            ];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON: ' . json_last_error_msg(),
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
            ];
        }

        if ($httpCode >= 400) {
            $errorMsg = $data['error']['message'] ?? $data['error'] ?? "HTTP {$httpCode}";
            return [
                'success' => false,
                'error' => $errorMsg,
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
            ];
        }

        // 200 with error field
        if (isset($data['error'])) {
            return [
                'success' => false,
                'error' => $data['error']['message'] ?? 'API error',
                'data' => [],
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
            ];
        }

        // Extract result from JSON-RPC wrapper
        $resultData = $data['result'] ?? $data;

        return [
            'success' => true,
            'data' => $resultData,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Get count of queued requests.
     */
    public function count(): int
    {
        return count($this->requests);
    }

    /**
     * Clear all queued requests.
     */
    public function clear(): self
    {
        $this->requests = [];
        return $this;
    }
}

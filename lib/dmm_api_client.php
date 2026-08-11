<?php

declare(strict_types=1);

class DmmApiClient
{
    public function __construct(
        private readonly string $apiId,
        private readonly string $affiliateId,
        private readonly string $endpoint
    ) {
    }

    public function fetchFloorList(): array
    {
        return $this->request('FloorList');
    }

    public function searchActresses(array $params = []): array
    {
        return $this->request('ActressSearch', $params);
    }

    public function searchGenres(array $params = []): array
    {
        return $this->request('GenreSearch', $params);
    }

    public function searchMakers(array $params = []): array
    {
        return $this->request('MakerSearch', $params);
    }

    public function searchSeries(array $params = []): array
    {
        return $this->request('SeriesSearch', $params);
    }

    public function searchAuthors(array $params = []): array
    {
        return $this->request('AuthorSearch', $params);
    }

    public function fetchItems(string $site, string $service, string $floor, array $params = []): array
    {
        return $this->request('ItemList', array_merge($params, ['site' => $site, 'service' => $service, 'floor' => $floor]));
    }

    private function request(string $operation, array $params = []): array
    {
        $query = array_filter(array_merge($params, [
            'api_id' => $this->apiId,
            'affiliate_id' => $this->affiliateId,
            'output' => 'json',
        ]), static fn ($v) => $v !== null && $v !== '');

        $url = rtrim($this->endpoint, '/') . '/' . $operation . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $safeUrl = rtrim($this->endpoint, '/') . '/' . $operation . '?' . http_build_query($this->maskSensitiveParams($query), '', '&', PHP_QUERY_RFC3986);
        $requestHash = hash('sha256', $url);

        $cached = $this->fetchCachedResponse($requestHash);
        if ($cached !== null) {
            $this->insertApiLog($operation, $safeUrl, $requestHash, 200, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', true);
            return $cached;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->insertApiLog($operation, $safeUrl, $requestHash, 0, json_encode(['error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', false);
            throw new RuntimeException('cURL error: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->insertApiLog($operation, $safeUrl, $requestHash, $httpCode, $response, false);

        if ($httpCode >= 400) {
            throw new RuntimeException($this->buildHttpErrorMessage($httpCode, $operation, $query, $response));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON decode failed.');
        }

        if (isset($decoded['result']['status']) && (int) $decoded['result']['status'] !== 200) {
            throw new RuntimeException('API error status: ' . $decoded['result']['status']);
        }

        return $decoded;
    }

    private function buildHttpErrorMessage(int $httpCode, string $operation, array $query, string $response): string
    {
        $parts = ['HTTP error: ' . $httpCode];
        $parts[] = 'request: ' . json_encode($this->diagnosticParams($operation, $query), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $parts[] = 'response: ' . $this->redactSensitiveText($this->formatErrorResponse($response), $query);
        return implode(' / ', $parts);
    }

    private function formatErrorResponse(string $response): string
    {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $summary = [];
            foreach (['code', 'message', 'error', 'errors'] as $key) {
                if (array_key_exists($key, $decoded)) {
                    $summary[$key] = $decoded[$key];
                }
            }
            if (isset($decoded['result']) && is_array($decoded['result'])) {
                $summary['result'] = $decoded['result'];
            }
            if ($summary !== []) {
                return mb_substr(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 0, 2000);
            }
        }

        $body = trim($response);
        return $body === '' ? '(empty)' : mb_substr($body, 0, 1000);
    }

    private function diagnosticParams(string $operation, array $query): array
    {
        $diagnostic = [
            'operation' => $operation,
            'api_id' => '***',
            'affiliate_id' => '***',
        ];

        foreach (['site', 'service', 'floor', 'hits', 'offset', 'sort'] as $key) {
            if (array_key_exists($key, $query)) {
                $diagnostic[$key] = $query[$key];
            }
        }
        $diagnostic['keyword'] = isset($query['keyword']) && trim((string)$query['keyword']) !== '' ? 'あり' : 'なし';

        return $diagnostic;
    }

    private function maskSensitiveParams(array $query): array
    {
        if (array_key_exists('api_id', $query)) {
            $query['api_id'] = '***';
        }
        if (array_key_exists('affiliate_id', $query)) {
            $query['affiliate_id'] = '***';
        }
        return $query;
    }

    private function redactSensitiveText(string $text, array $query): string
    {
        foreach (['api_id', 'affiliate_id'] as $key) {
            $value = (string)($query[$key] ?? '');
            if ($value !== '') {
                $text = str_replace($value, '***', $text);
            }
        }
        return $text;
    }

    private function fetchCachedResponse(string $requestHash): ?array
    {
        if (!function_exists('db')) {
            return null;
        }

        $stmt = db()->prepare('SELECT response_body FROM api_logs WHERE request_hash = :request_hash AND response_status = 200 AND cache_hit = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1');
        $stmt->execute([':request_hash' => $requestHash]);
        $body = $stmt->fetchColumn();
        if (!is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function insertApiLog(string $apiName, string $requestUrl, string $requestHash, int $status, string $responseBody, bool $cacheHit): void
    {
        if (!function_exists('db')) {
            return;
        }

        $body = mb_substr($responseBody, 0, 65535);

        try {
            $stmt = db()->prepare('INSERT INTO api_logs (api_name, endpoint, request_params, request_url, request_hash, response_status, status_code, response_body, cache_hit, is_success, message, created_at) VALUES (:api_name, :endpoint, :request_params, :request_url, :request_hash, :response_status, :status_code, :response_body, :cache_hit, :is_success, :message, NOW())');
            $stmt->execute([
                ':api_name' => $apiName,
                ':endpoint' => $apiName,
                ':request_params' => json_encode(['url' => $requestUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':request_url' => $requestUrl,
                ':request_hash' => $requestHash,
                ':response_status' => $status,
                ':status_code' => $status,
                ':response_body' => $body,
                ':cache_hit' => $cacheHit ? 1 : 0,
                ':is_success' => ($status >= 200 && $status < 400) ? 1 : 0,
                ':message' => $cacheHit ? 'cache' : (($status >= 200 && $status < 400) ? 'ok' : 'error'),
            ]);
            return;
        } catch (Throwable $e) {
            error_log('api_logs extended insert failed, fallback to legacy columns: ' . $e->getMessage());
        }

        try {
            $stmt = db()->prepare('INSERT INTO api_logs (api_name, request_url, request_hash, response_status, response_body, cache_hit, created_at) VALUES (:api_name, :request_url, :request_hash, :response_status, :response_body, :cache_hit, NOW())');
            $stmt->execute([
                ':api_name' => $apiName,
                ':request_url' => $requestUrl,
                ':request_hash' => $requestHash,
                ':response_status' => $status,
                ':response_body' => $body,
                ':cache_hit' => $cacheHit ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            error_log('api_logs legacy insert failed: ' . $e->getMessage());
        }
    }
}

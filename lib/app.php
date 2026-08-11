<?php

declare(strict_types=1);

require_once __DIR__ . '/dmm_api_client.php';
require_once __DIR__ . '/dmm_sync_service.php';
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/api_credentials.php';
require_once __DIR__ . '/config.php';


function settings_normalize_token(string $value, string $fallback): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return $fallback;
    }

    if (preg_match_all('/[A-Za-z][A-Za-z0-9_.-]*/', $trimmed, $matches) === 1 && !empty($matches[0])) {
        return (string)$matches[0][count($matches[0]) - 1];
    }

    return $fallback;
}

function settings_normalize_site(string $value): string
{
    $upper = strtoupper(trim($value));
    if (str_contains($upper, 'FANZA')) {
        return 'FANZA';
    }
    if (str_contains($upper, 'DMM')) {
        return 'DMM.com';
    }

    return 'FANZA';
}


function settings_get(): array
{
    $defaults = app_config()['dmm'] ?? [];
    $catalogTargets = settings_catalog_targets($defaults);
    $primaryTarget = $catalogTargets[0];

    $envApiId = trim((string)(getenv('DMM_API_ID') ?: getenv('FANZA_API_ID') ?: ''));
    $envAffiliateId = trim((string)(getenv('DMM_AFFILIATE_ID') ?: getenv('FANZA_AFFILIATE_ID') ?: ''));

    $itemCred = api_credential_get('items');
    $dbApiId = trim((string)($itemCred['api_id'] ?? ''));
    $dbAffiliateId = trim((string)($itemCred['affiliate_id'] ?? ''));

    return [
        'api_id' => $dbApiId !== '' ? $dbApiId : ($envApiId !== '' ? $envApiId : ''),
        'affiliate_id' => $dbAffiliateId !== '' ? $dbAffiliateId : ($envAffiliateId !== '' ? $envAffiliateId : ''),
        'site' => (string)$primaryTarget['site'],
        'service' => (string)$primaryTarget['service'],
        'floor' => (string)$primaryTarget['floor'],
        'catalog_targets' => $catalogTargets,
        'master_floor_id' => trim(site_setting_get('master_floor_id', (string)($defaults['master_floor_id'] ?? '43'))),
        'item_sync_batch' => settings_allowed_item_sync_batch(settings_int('item_sync_batch', 100)),
        'item_sync_enabled' => settings_bool('item_sync_enabled', false),
        'item_sync_interval_minutes' => settings_int('item_sync_interval_minutes', 60),
        'last_item_sync_at' => site_setting_get('last_item_sync_at', ''),
        'item_sync_offset' => settings_int('item_sync_offset', 1),
        'item_sync_test_offset' => settings_int('item_sync_test_offset', 1),
    ];
}

/** @return array<int,array{site:string,service:string,floor:string,label:string}> */
function settings_catalog_targets(array $defaults): array
{
    $configured = $defaults['catalog_targets'] ?? [];
    if (!is_array($configured) || $configured === []) {
        $configured = [[
            'site' => $defaults['site'] ?? 'FANZA',
            'service' => $defaults['service'] ?? 'digital',
            'floor' => $defaults['floor'] ?? 'videoa',
            'label' => '商品',
        ]];
    }

    $targets = [];
    foreach ($configured as $target) {
        if (!is_array($target)) {
            continue;
        }
        $service = strtolower(settings_normalize_token((string)($target['service'] ?? ''), ''));
        $floor = strtolower(settings_normalize_token((string)($target['floor'] ?? ''), ''));
        if ($service === '' || $floor === '') {
            continue;
        }
        $key = $service . ':' . $floor;
        $targets[$key] = [
            'site' => settings_normalize_site((string)($target['site'] ?? ($defaults['site'] ?? 'FANZA'))),
            'service' => $service,
            'floor' => $floor,
            'label' => trim((string)($target['label'] ?? $floor)) ?: $floor,
        ];
    }

    if ($targets === []) {
        $targets['digital:videoa'] = ['site' => 'FANZA', 'service' => 'digital', 'floor' => 'videoa', 'label' => '商品'];
    }

    return array_values($targets);
}

function settings_catalog_target_key(array $target): string
{
    return strtolower((string)($target['service'] ?? 'digital')) . ':' . strtolower((string)($target['floor'] ?? 'videoa'));
}

function settings_int(string $key, int $default): int
{
    $value = site_setting_get($key, (string)$default);
    if (!preg_match('/^-?\d+$/', $value)) {
        return $default;
    }
    return (int)$value;
}

function settings_allowed_item_sync_batch(int $value): int
{
    $allowed = [1, 10, 20, 30, 50, 100, 200, 300, 500];
    if (!in_array($value, $allowed, true)) {
        return 100;
    }
    return $value;
}

function settings_bool(string $key, bool $default): bool
{
    return settings_int($key, $default ? 1 : 0) === 1;
}

function settings_save(string $apiId, string $affiliateId, int $itemSyncBatch = 100, ?int $masterFloorId = null): void
{
    $allowed = [1, 10, 20, 30, 50, 100, 200, 300, 500];
    if (!in_array($itemSyncBatch, $allowed, true)) {
        $itemSyncBatch = 100;
    }

    $payload = [
        'fanza_api_id' => trim($apiId),
        'fanza_affiliate_id' => trim($affiliateId),
        'item_sync_batch' => (string)$itemSyncBatch,
    ];
    if ($masterFloorId !== null) {
        $payload['master_floor_id'] = (string)max(1, $masterFloorId);
    }

    site_setting_set_many($payload);
}

function dmm_client_for_type(string $apiType): DmmApiClient
{
    $cred = api_credential_get($apiType);
    $endpoint = app_config()['dmm']['endpoint'];
    return new DmmApiClient((string)($cred['api_id'] ?? ''), (string)($cred['affiliate_id'] ?? ''), $endpoint);
}

function dmm_client_from_settings(): DmmApiClient
{
    return dmm_client_for_type('items');
}

function dmm_sync_service(?string $apiType = null): DmmSyncService
{
    return new DmmSyncService($apiType === null ? dmm_client_from_settings() : dmm_client_for_type($apiType), db());
}

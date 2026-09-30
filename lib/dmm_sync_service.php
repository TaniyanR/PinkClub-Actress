<?php

declare(strict_types=1);

require_once __DIR__ . '/dmm_api_client.php';
require_once __DIR__ . '/dmm_normalizer.php';
require_once __DIR__ . '/repository.php';

class DmmSyncService
{
    public function __construct(private readonly DmmApiClient $client, private readonly PDO $pdo)
    {
        $this->ensureSchema();
    }

    public function syncFloors(): int
    {
        $response = $this->client->fetchFloorList();
        $siteList = DmmNormalizer::toList($response['result']['site'] ?? []);
        $count = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($siteList as $site) {
                $siteCode = (string)($site['code'] ?? ($site['site'] ?? ''));
                $siteName = $site['name'] ?? '';
                $this->upsertSimple('dmm_sites', 'site_code', $siteCode, $siteName);
                foreach (DmmNormalizer::toList($site['service'] ?? []) as $service) {
                    $serviceCode = (string)($service['code'] ?? ($service['service'] ?? ''));
                    $serviceName = $service['name'] ?? '';
                    $this->pdo->prepare('INSERT INTO dmm_services(site_code,service_code,name,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()')
                        ->execute([$siteCode, $serviceCode, $serviceName]);
                    foreach (DmmNormalizer::toList($service['floor'] ?? []) as $floor) {
                        $floorCode = (string)($floor['code'] ?? ($floor['floor'] ?? ''));
                        $floorName = $floor['name'] ?? '';
                        $this->pdo->prepare('INSERT INTO dmm_floors(service_code,floor_code,name,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()')
                            ->execute([$serviceCode, $floorCode, $floorName]);
                        $count++;
                    }
                }
            }
            $this->pdo->commit();
            $this->logSync('floors', 1, $count, 'Floor sync completed.');
            return $count;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->logSync('floors', 0, 0, $e->getMessage());
            throw $e;
        }
    }

    public function syncMaster(string $kind, ?string $floorId = null, int $offset = 1, int $hits = 100, array $extraParams = []): int
    {
        $count = 0;
        $params = ['hits' => min(100, max(1, $hits)), 'offset' => max(1, $offset)];
        if ($kind === 'actress') {
            $params = array_merge($params, $extraParams);
        }
        if ($floorId && $kind !== 'actress') {
            $params['floor_id'] = $floorId;
        }

        $response = match ($kind) {
            'actress' => $this->client->searchActresses($params),
            'genre' => $this->client->searchGenres($params),
            'maker' => $this->client->searchMakers($params),
            'series' => $this->client->searchSeries($params),
            'author' => $this->client->searchAuthors($params),
            default => throw new InvalidArgumentException('Unknown master type.'),
        };

        $key = $kind === 'series' ? 'series' : ($kind === 'actress' ? 'actress' : $kind);
        $rows = DmmNormalizer::toList($response['result'][$key] ?? []);
        $table = match ($kind) {
            'genre' => 'genres',
            'maker' => 'makers',
            'author' => 'authors',
            'actress' => 'actresses',
            'series' => 'series_master',
            default => throw new InvalidArgumentException('Unknown master type.'),
        };

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $idKey = match ($kind) {
                    'genre' => 'genre_id',
                    'maker' => 'maker_id',
                    'series' => 'series_id',
                    'author' => 'author_id',
                    default => 'id',
                };
                $id = (string)($r[$idKey] ?? ($r['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $name = (string) ($r['name'] ?? '');
                $ruby = $r['ruby'] ?? null;

                if ($kind === 'actress') {
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO actresses(
                            dmm_id,name,ruby,birthday,prefectures,hobby,bust,cup,waist,hip,height,blood_type,
                            image_url,image_small,image_large,updated_at
                         ) VALUES(
                            :id,:name,:ruby,:birthday,:pref,:hobby,:bust,:cup,:waist,:hip,:height,:blood_type,
                            :img,:img_small,:img_large,NOW()
                         )
                         ON DUPLICATE KEY UPDATE
                            name=VALUES(name),ruby=VALUES(ruby),birthday=VALUES(birthday),prefectures=VALUES(prefectures),
                            hobby=VALUES(hobby),bust=VALUES(bust),cup=VALUES(cup),waist=VALUES(waist),hip=VALUES(hip),
                            height=VALUES(height),blood_type=VALUES(blood_type),image_url=VALUES(image_url),
                            image_small=VALUES(image_small),image_large=VALUES(image_large),updated_at=NOW()"
                    );
                    $stmt->execute([
                        'id' => $id,
                        'name' => $name,
                        'ruby' => $ruby,
                        'birthday' => $r['birthday'] ?? null,
                        'pref' => $r['prefectures'] ?? null,
                        'hobby' => $r['hobby'] ?? null,
                        'bust' => $r['bust'] ?? null,
                        'cup' => $r['cup'] ?? null,
                        'waist' => $r['waist'] ?? null,
                        'hip' => $r['hip'] ?? null,
                        'height' => $r['height'] ?? null,
                        'blood_type' => $r['blood_type'] ?? null,
                        'img' => $r['imageURL']['large'] ?? ($r['image_url'] ?? null),
                        'img_small' => $r['imageURL']['small'] ?? ($r['image_small'] ?? null),
                        'img_large' => $r['imageURL']['large'] ?? ($r['image_large'] ?? null),
                    ]);
                } else {
                    $stmt = $this->pdo->prepare("INSERT INTO {$table}(dmm_id,name,ruby,birthday,prefectures,image_url,image_small,image_large,updated_at) VALUES(:id,:name,:ruby,:birthday,:pref,:img,:img_small,:img_large,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),ruby=VALUES(ruby),birthday=VALUES(birthday),prefectures=VALUES(prefectures),image_url=VALUES(image_url),image_small=VALUES(image_small),image_large=VALUES(image_large),updated_at=NOW()");
                    $stmt->execute([
                        'id' => $id,
                        'name' => $name,
                        'ruby' => $ruby,
                        'birthday' => $r['birthday'] ?? null,
                        'pref' => $r['prefectures'] ?? null,
                        'img' => $r['imageURL']['large'] ?? ($r['image_url'] ?? null),
                        'img_small' => $r['imageURL']['small'] ?? ($r['image_small'] ?? null),
                        'img_large' => $r['imageURL']['large'] ?? ($r['image_large'] ?? null),
                    ]);
                }
                $count++;
            }
            $this->pdo->commit();
            $this->logSync($kind . 's', 1, $count, 'Master sync completed.');
            return $count;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->logSync($kind . 's', 0, 0, $e->getMessage());
            throw $e;
        }
    }


    public function syncGenres(string $floorId, ?string $initial = null, int $hits = 100, int $offset = 1): int
    {
        return $this->syncMaster('genre', $floorId, $offset, $hits);
    }

    public function syncMakers(string $floorId, ?string $initial = null, int $hits = 100, int $offset = 1): int
    {
        return $this->syncMaster('maker', $floorId, $offset, $hits);
    }

    public function syncSeries(string $floorId, ?string $initial = null, int $hits = 100, int $offset = 1): int
    {
        return $this->syncMaster('series', $floorId, $offset, $hits);
    }

    public function syncAuthors(string $floorId, ?string $initial = null, int $hits = 100, int $offset = 1): int
    {
        return $this->syncMaster('author', $floorId, $offset, $hits);
    }

    public function syncItems(string $siteCode, string $serviceCode, string $floorCode, array $params = []): int
    {
        $hits = min(100, max(1, (int)($params['hits'] ?? 100)));
        $offset = $this->normalizeItemListOffset((int)($params['offset'] ?? 1));
        $requestParams = array_merge($params, ['hits' => $hits, 'offset' => $offset]);
        $response = $this->client->fetchItems($siteCode, $serviceCode, $floorCode, $requestParams);
        $items = $this->filterItemsWithRegisteredActresses(DmmNormalizer::normalizeItemsResponse($response));
        return $this->saveItems($items, 'items');
    }

    /**
     * 女優APIで既に登録済みの女優を起点に、その女優の作品だけを取得・保存する。
     * 商品API側の出演者ID表現が異なる場合でも、article=actress で検索した対象女優との
     * 関係を強制保存するため、公開側は actresses.dmm_id だけで作品を取得できる。
     */
    public function syncItemsForRegisteredActress(
        int $actressId,
        string $dmmId,
        string $actressName,
        int $hits = 20,
        int $offset = 1
    ): array {
        $actressId = max(1, $actressId);
        $dmmId = trim($dmmId);
        $actressName = trim($actressName);
        $hits = min(100, max(1, $hits));
        $offset = $this->normalizeItemListOffset($offset);

        $check = $this->pdo->prepare("SELECT id,dmm_id,name FROM actresses WHERE id=:id AND dmm_id=:dmm_id AND dmm_id REGEXP '^[0-9]+
    public function syncItemsBatch(string $siteCode, string $serviceCode, string $floorCode, int $batch, int $offset = 1, array $extraParams = [], array $excludeKeywords = []): array
    {
        $targetNew = max(1, $batch);
        $currentOffset = $this->normalizeItemListOffset($offset);
        $nextOffset = $currentOffset;
        $apiCount = 0;
        $newCount = 0;
        $updatedCount = 0;
        $excludedCount = 0;
        $checkedCount = 0;
        $maxChecked = 1000;
        $hitLimit = 100;
        $reachedEnd = false;

        $fetchAndSave = function (int $requestOffset, bool $advancePastOffset) use (
            $siteCode,
            $serviceCode,
            $floorCode,
            $extraParams,
            $excludeKeywords,
            $hitLimit,
            &$apiCount,
            &$newCount,
            &$updatedCount,
            &$excludedCount,
            &$checkedCount,
            &$nextOffset,
            &$reachedEnd,
            $targetNew
        ): int {
            $requestOffset = $this->normalizeItemListOffset($requestOffset);
            $requestParams = array_merge($extraParams, ['hits' => $hitLimit, 'offset' => $requestOffset]);
            $response = $this->client->fetchItems($siteCode, $serviceCode, $floorCode, $requestParams);
            $fetchedItems = DmmNormalizer::normalizeItemsResponse($response);
            $fetchedCount = count($fetchedItems);
            $apiCount += $fetchedCount;
            $checkedCount += $fetchedCount;
            if ($fetchedCount === 0) {
                $reachedEnd = true;
                if ($advancePastOffset) {
                    $nextOffset = 1;
                }
                return 0;
            }

            $processedCount = 0;
            $saveItems = [];
            $saveUpdatedCount = 0;
            $existingContentIds = $this->itemsExistByContentIds(array_map(static function (array $item): string {
                return (string)($item['content_id'] ?? '');
            }, $fetchedItems));
            $fetchedItems = $this->filterItemsWithRegisteredActresses($fetchedItems);
            foreach ($fetchedItems as $item) {
                $processedCount++;
                $excluded = false;
                if ($excludeKeywords !== []) {
                    $title = (string)($item['title'] ?? '');
                    foreach ($excludeKeywords as $keyword) {
                        $keyword = trim((string)$keyword);
                        if ($keyword !== '' && mb_strpos($title, $keyword) !== false) {
                            $excluded = true;
                            break;
                        }
                    }
                }
                if ($excluded) {
                    $excludedCount++;
                    continue;
                }

                $contentId = (string)($item['content_id'] ?? '');
                $exists = isset($existingContentIds[$contentId]);
                if (!$exists && $newCount >= $targetNew) {
                    $processedCount--;
                    break;
                }

                $saveItems[] = $item;
                if ($exists) {
                    $saveUpdatedCount++;
                    continue;
                }
                $newCount++;
                if ($contentId !== '') {
                    $existingContentIds[$contentId] = true;
                }
            }

            if ($saveItems !== []) {
                $this->saveItemsWithStats($saveItems, 'items', false);
                $updatedCount += $saveUpdatedCount;
            }

            if ($advancePastOffset) {
                $nextOffset = $this->normalizeItemListOffset($requestOffset + $processedCount);
                if ($fetchedCount < $hitLimit) {
                    $nextOffset = 1;
                    $reachedEnd = true;
                }
            }

            return $fetchedCount;
        };

        $fetchAndSave(1, false);

        while ($newCount < $targetNew && $checkedCount < $maxChecked && !$reachedEnd) {
            $fetchAndSave($currentOffset, true);
            $currentOffset = $nextOffset;
            if ($nextOffset === 1 && $reachedEnd) {
                break;
            }
        }

        $message = sprintf(
            '商品を同期しました。API取得: %d件 / 新規: %d件 / 更新: %d件 / 除外: %d件 / 次回offset: %d',
            $apiCount,
            $newCount,
            $updatedCount,
            $excludedCount,
            $nextOffset
        );
        $this->logSync('items', 1, $newCount, $message);

        return [
            'synced_count' => $newCount,
            'api_count' => $apiCount,
            'new_count' => $newCount,
            'updated_count' => $updatedCount,
            'excluded_count' => $excludedCount,
            'checked_count' => $checkedCount,
            'next_offset' => $nextOffset,
            'message' => $message,
        ];
    }

    private function normalizeItemListOffset(int $offset): int
    {
        $offset = max(1, $offset);
        return $offset > 50000 ? 1 : $offset;
    }

    private function saveItems(array $items, string $logType): int
    {
        $stats = $this->saveItemsWithStats($items, $logType, true);
        return (int)$stats['saved_count'];
    }

    private function saveItemsWithStats(array $items, string $logType, bool $writeLog, ?array $forcedActress = null): array
    {
        $count = 0;
        $newCount = 0;
        $updatedCount = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $exists = $this->itemExistsByContentId((string)($item['content_id'] ?? ''));
                $itemId = $this->upsertItem($item);
                $this->rebuildItemRelations($itemId, $item, $forcedActress);
                if (function_exists('generate_tags_for_item')) {
                    generate_tags_for_item([
                        'content_id' => $item['content_id'] ?? '',
                        'title' => $item['title'] ?? '',
                        'category_name' => $item['category_name'] ?? '',
                    ]);
                }
                $count++;
                if ($exists) {
                    $updatedCount++;
                } else {
                    $newCount++;
                }
            }
            $this->pdo->commit();
            if ($writeLog) {
                $this->logSync($logType, 1, $count, 'Item sync completed.');
            }
            return ['saved_count' => $count, 'new_count' => $newCount, 'updated_count' => $updatedCount];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($writeLog) {
                $this->logSync($logType, 0, 0, $e->getMessage());
            }
            throw $e;
        }
    }

    private function itemExistsByContentId(string $contentId): bool
    {
        if ($contentId === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM items WHERE content_id = ? LIMIT 1');
        $stmt->execute([$contentId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param string[] $contentIds
     * @return array<string, bool>
     */
    private function itemsExistByContentIds(array $contentIds): array
    {
        $contentIds = array_values(array_unique(array_filter(array_map('strval', $contentIds), static fn (string $contentId): bool => $contentId !== '')));
        if ($contentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
        $stmt = $this->pdo->prepare("SELECT content_id FROM items WHERE content_id IN ({$placeholders})");
        $stmt->execute($contentIds);

        $exists = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $contentId) {
            $exists[(string)$contentId] = true;
        }
        return $exists;
    }

    private function upsertSimple(string $table, string $codeColumn, string $code, string $name): void
    {
        $this->pdo->prepare("INSERT INTO {$table}({$codeColumn},name,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()")
            ->execute([$code, $name]);
    }

    private function upsertItem(array $item): int
    {
        $sql = 'INSERT INTO items(content_id,product_id,item_source,title,service_code,service_name,floor_code,floor_name,category_name,volume,review_count,review_average,url,affiliate_url,image_list,image_small,image_large,sample_movie_url_476,sample_movie_url_560,sample_movie_url_644,sample_movie_url_720,sample_movie_pc_flag,sample_movie_sp_flag,price_min_text,list_price_text,release_date,raw_json,updated_at)
                VALUES(:content_id,:product_id,:item_source,:title,:service_code,:service_name,:floor_code,:floor_name,:category_name,:volume,:review_count,:review_average,:url,:affiliate_url,:image_list,:image_small,:image_large,:u476,:u560,:u644,:u720,:pc,:sp,:price_min,:list_price,:release_date,:raw_json,NOW())
                ON DUPLICATE KEY UPDATE item_source=VALUES(item_source),title=VALUES(title),service_name=VALUES(service_name),floor_name=VALUES(floor_name),category_name=VALUES(category_name),volume=VALUES(volume),review_count=VALUES(review_count),review_average=VALUES(review_average),url=VALUES(url),affiliate_url=VALUES(affiliate_url),image_list=VALUES(image_list),image_small=VALUES(image_small),image_large=VALUES(image_large),sample_movie_url_476=VALUES(sample_movie_url_476),sample_movie_url_560=VALUES(sample_movie_url_560),sample_movie_url_644=VALUES(sample_movie_url_644),sample_movie_url_720=VALUES(sample_movie_url_720),sample_movie_pc_flag=VALUES(sample_movie_pc_flag),sample_movie_sp_flag=VALUES(sample_movie_sp_flag),price_min_text=VALUES(price_min_text),list_price_text=VALUES(list_price_text),release_date=VALUES(release_date),raw_json=VALUES(raw_json),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([
            'content_id' => $item['content_id'], 'product_id' => $item['product_id'], 'item_source' => 'fanza_product', 'title' => $item['title'],
            'service_code' => $item['service_code'], 'service_name' => $item['service_name'], 'floor_code' => $item['floor_code'], 'floor_name' => $item['floor_name'],
            'category_name' => $item['category_name'], 'volume' => $item['volume'], 'review_count' => $item['review_count'], 'review_average' => $item['review_average'],
            'url' => $item['url'], 'affiliate_url' => $item['affiliate_url'], 'image_list' => $item['image_list'], 'image_small' => $item['image_small'], 'image_large' => $item['image_large'],
            'u476' => $item['sample_movie_url_476'], 'u560' => $item['sample_movie_url_560'], 'u644' => $item['sample_movie_url_644'], 'u720' => $item['sample_movie_url_720'],
            'pc' => $item['sample_movie_pc_flag'], 'sp' => $item['sample_movie_sp_flag'], 'price_min' => $item['price_min_text'], 'list_price' => $item['list_price_text'],
            'release_date' => $item['release_date'], 'raw_json' => json_encode($item['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $idStmt = $this->pdo->prepare('SELECT id FROM items WHERE content_id = ?');
        $idStmt->execute([$item['content_id']]);
        return (int) $idStmt->fetchColumn();
    }

    /**
     * 商品保存時の女優関係は、女優APIで登録済みの女優だけに限定する。
     * 商品APIだけを根拠に actresses マスタへ人物を追加しない。
     */
    private function rebuildItemRelations(int $itemId, array $item, ?array $forcedActress = null): void
    {
        $tables = ['item_actresses', 'item_genres', 'item_campaigns', 'item_labels', 'item_directors', 'item_makers', 'item_series', 'item_authors', 'item_actors'];
        foreach ($tables as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE item_id = ?")->execute([$itemId]);
        }

        $this->insertRegisteredActressRelations($itemId, is_array($item['actresses'] ?? null) ? $item['actresses'] : [], $forcedActress);
        $this->insertRelation($itemId, 'item_genres', 'genre_name', $item['genres']);
        $this->insertRelation($itemId, 'item_campaigns', 'campaign_name', $item['campaigns']);
        $this->insertRelation($itemId, 'item_labels', 'label_name', $item['labels']);
        $this->insertRelation($itemId, 'item_directors', 'director_name', $item['directors']);
        $this->insertRelation($itemId, 'item_makers', 'maker_name', $item['makers']);
        $this->insertRelation($itemId, 'item_series', 'series_name', $item['series']);
        $this->insertRelation($itemId, 'item_authors', 'author_name', $item['authors']);
        $this->insertRelation($itemId, 'item_actors', 'actor_name', $item['actors'] ?? []);
    }

    private function insertRegisteredActressRelations(int $itemId, array $rows, ?array $forcedActress): void
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dmmId = trim((string)($row['id'] ?? ''));
            if ($dmmId !== '' && preg_match('/^[0-9]+$/', $dmmId) === 1) {
                $ids[$dmmId] = true;
            }
        }

        $registered = [];
        if ($ids !== []) {
            $idList = array_keys($ids);
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $stmt = $this->pdo->prepare("SELECT dmm_id,name FROM actresses WHERE dmm_id IN ({$placeholders}) AND dmm_id REGEXP '^[0-9]+$'");
            $stmt->execute($idList);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $registered[(string)$row['dmm_id']] = (string)$row['name'];
            }
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO item_actresses(item_id,dmm_id,actress_name)
             SELECT :item_id,:dmm_id,:name
             WHERE NOT EXISTS (
               SELECT 1 FROM item_actresses WHERE item_id=:check_item_id AND dmm_id=:check_dmm_id
             )'
        );
        foreach ($registered as $dmmId => $name) {
            $insert->execute([
                ':item_id' => $itemId,
                ':dmm_id' => $dmmId,
                ':name' => $name,
                ':check_item_id' => $itemId,
                ':check_dmm_id' => $dmmId,
            ]);
        }

        if (is_array($forcedActress)) {
            $forcedId = max(0, (int)($forcedActress['id'] ?? 0));
            $forcedDmmId = trim((string)($forcedActress['dmm_id'] ?? ''));
            if ($forcedId > 0 && preg_match('/^[0-9]+$/', $forcedDmmId) === 1) {
                $stmt = $this->pdo->prepare('SELECT dmm_id,name FROM actresses WHERE id=:id AND dmm_id=:dmm_id LIMIT 1');
                $stmt->execute([':id' => $forcedId, ':dmm_id' => $forcedDmmId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $insert->execute([
                        ':item_id' => $itemId,
                        ':dmm_id' => (string)$row['dmm_id'],
                        ':name' => (string)$row['name'],
                        ':check_item_id' => $itemId,
                        ':check_dmm_id' => (string)$row['dmm_id'],
                    ]);
                }
            }
        }
    }

    /**
     * 通常の商品一括同期では、女優APIで登録済みの出演者が1人もいない作品を保存しない。
     * 1回のSQLで登録済みDMM女優IDを取得し、N+1を避ける。
     */
    private function filterItemsWithRegisteredActresses(array $items): array
    {
        $allIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array)($item['actresses'] ?? []) as $performer) {
                if (!is_array($performer)) {
                    continue;
                }
                $dmmId = trim((string)($performer['id'] ?? ''));
                if ($dmmId !== '' && preg_match('/^[0-9]+$/', $dmmId) === 1) {
                    $allIds[$dmmId] = true;
                }
            }
        }
        if ($allIds === []) {
            return [];
        }

        $ids = array_keys($allIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT dmm_id FROM actresses WHERE dmm_id IN ({$placeholders}) AND dmm_id REGEXP '^[0-9]+$'");
        $stmt->execute($ids);
        $registered = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

        return array_values(array_filter($items, static function ($item) use ($registered): bool {
            if (!is_array($item)) {
                return false;
            }
            foreach ((array)($item['actresses'] ?? []) as $performer) {
                if (!is_array($performer)) {
                    continue;
                }
                $dmmId = trim((string)($performer['id'] ?? ''));
                if ($dmmId !== '' && isset($registered[$dmmId])) {
                    return true;
                }
            }
            return false;
        }));
    }

    private function insertRelation(int $itemId, string $table, string $nameCol, array $rows): void
    {
        $masterMap = [
            'item_genres' => 'genres',
            'item_makers' => 'makers',
            'item_series' => 'series_master',
            'item_authors' => 'authors',
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dmmId = trim((string)($row['id'] ?? ''));
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if ($dmmId === '') {
                $dmmId = 'name:' . sha1(mb_strtolower($name, 'UTF-8'));
            }

            $this->pdo->prepare("INSERT IGNORE INTO {$table}(item_id,dmm_id,{$nameCol}) VALUES(?,?,?)")
                ->execute([$itemId, $dmmId, $name]);

            $masterTable = $masterMap[$table] ?? null;
            if (is_string($masterTable) && $masterTable !== '' && $dmmId !== '') {
                $this->pdo->prepare("INSERT INTO {$masterTable}(dmm_id,name,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=NOW()")
                    ->execute([$dmmId, $name]);
            }
        }
    }

    public function logSync(string $type, int $isSuccess, int $count, string $message): void
    {
        $this->pdo->prepare('INSERT INTO sync_logs(sync_type,is_success,synced_count,message,created_at) VALUES(?,?,?,?,NOW())')
            ->execute([$type, $isSuccess, $count, mb_substr($message, 0, 1000)]);
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_makers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,dmm_id VARCHAR(64) NULL,maker_name VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_item_maker (item_id,dmm_id),CONSTRAINT fk_item_maker_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_series (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,dmm_id VARCHAR(64) NULL,series_name VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_item_series (item_id,dmm_id),CONSTRAINT fk_item_series_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_authors (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,dmm_id VARCHAR(64) NULL,author_name VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_item_author (item_id,dmm_id),CONSTRAINT fk_item_author_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_actors (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,dmm_id VARCHAR(64) NULL,actor_name VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_item_actor (item_id,dmm_id),CONSTRAINT fk_item_actor_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sync_job_state (job_key VARCHAR(64) PRIMARY KEY,next_offset INT NOT NULL DEFAULT 1,next_initial VARCHAR(10) NULL,last_run_at DATETIME NULL,last_success TINYINT(1) NOT NULL DEFAULT 0,last_message TEXT NULL,lock_until DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $actressColumns = [];
        $actressStmt = $this->pdo->query('SHOW COLUMNS FROM actresses');
        foreach (($actressStmt ? $actressStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $col) {
            $actressColumns[(string)($col['Field'] ?? '')] = true;
        }
        $actressProfileColumns = [
            'hobby' => 'VARCHAR(255) NULL',
            'bust' => 'VARCHAR(32) NULL',
            'cup' => 'VARCHAR(32) NULL',
            'waist' => 'VARCHAR(32) NULL',
            'hip' => 'VARCHAR(32) NULL',
            'height' => 'VARCHAR(32) NULL',
            'blood_type' => 'VARCHAR(32) NULL',
        ];
        foreach ($actressProfileColumns as $column => $definition) {
            if (!isset($actressColumns[$column])) {
                $this->pdo->exec("ALTER TABLE actresses ADD COLUMN {$column} {$definition}");
            }
        }

        $itemColumns = [];
        $itemStmt = $this->pdo->query('SHOW COLUMNS FROM items');
        foreach (($itemStmt ? $itemStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $col) {
            $itemColumns[(string)($col['Field'] ?? '')] = true;
        }
        if (!isset($itemColumns['view_count'])) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN view_count INT NOT NULL DEFAULT 0');
        }
        if (!isset($itemColumns['item_source'])) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN item_source VARCHAR(32) NOT NULL DEFAULT "unknown" AFTER product_id');
        }
        try {
            $this->pdo->exec('CREATE INDEX idx_items_item_source_release ON items(item_source, release_date, id)');
        } catch (Throwable) {
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS page_views (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,ip_hash VARCHAR(64) NULL,user_agent VARCHAR(255) NULL,INDEX idx_page_views_item_date (item_id, viewed_at),CONSTRAINT fk_page_views_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS tags (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_tags (item_id INT UNSIGNED NOT NULL,tag_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (item_id, tag_id),INDEX idx_item_tags_tag (tag_id),CONSTRAINT fk_item_tags_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,CONSTRAINT fk_item_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS api_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,api_name VARCHAR(64) NOT NULL,request_url TEXT NOT NULL,request_hash CHAR(64) NOT NULL,response_status INT NULL,response_body MEDIUMTEXT NULL,cache_hit TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_api_logs_created (created_at),INDEX idx_api_logs_name (api_name),INDEX idx_api_logs_hash (request_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    }
}
 LIMIT 1");
        $check->execute([':id' => $actressId, ':dmm_id' => $dmmId]);
        $registered = $check->fetch(PDO::FETCH_ASSOC);
        if (!is_array($registered)) {
            throw new RuntimeException('登録済み女優を確認できません。');
        }

        $response = $this->client->fetchItems('FANZA', 'digital', 'videoa', [
            'hits' => $hits,
            'offset' => $offset,
            'sort' => 'date',
            'article' => 'actress',
            'article_id' => $dmmId,
        ]);
        $items = array_values(array_filter(
            DmmNormalizer::normalizeItemsResponse($response),
            static fn($item): bool => is_array($item)
                && trim((string)($item['content_id'] ?? '')) !== ''
                && strtolower(trim((string)($item['floor_code'] ?? 'videoa'))) === 'videoa'
        ));

        $stats = $this->saveItemsWithStats(
            $items,
            'items',
            false,
            [
                'id' => (int)$registered['id'],
                'dmm_id' => (string)$registered['dmm_id'],
                'name' => (string)($registered['name'] ?? $actressName),
            ]
        );

        $apiCount = count($items);
        $nextOffset = $apiCount < $hits ? 1 : $this->normalizeItemListOffset($offset + $apiCount);
        $message = sprintf(
            '%s の作品を同期しました。API取得: %d件 / 保存: %d件 / 新規: %d件 / 更新: %d件 / 次回offset: %d',
            (string)($registered['name'] ?? $actressName),
            $apiCount,
            (int)($stats['saved_count'] ?? 0),
            (int)($stats['new_count'] ?? 0),
            (int)($stats['updated_count'] ?? 0),
            $nextOffset
        );
        $this->logSync('items', 1, (int)($stats['saved_count'] ?? 0), $message);

        return [
            'api_count' => $apiCount,
            'saved_count' => (int)($stats['saved_count'] ?? 0),
            'new_count' => (int)($stats['new_count'] ?? 0),
            'updated_count' => (int)($stats['updated_count'] ?? 0),
            'next_offset' => $nextOffset,
            'reached_end' => $apiCount < $hits,
            'message' => $message,
        ];
    }

    public function syncItemsBatch(string $siteCode, string $serviceCode, string $floorCode, int $batch, int $offset = 1, array $extraParams = [], array $excludeKeywords = []): array
    {
        $targetNew = max(1, $batch);
        $currentOffset = $this->normalizeItemListOffset($offset);
        $nextOffset = $currentOffset;
        $apiCount = 0;
        $newCount = 0;
        $updatedCount = 0;
        $excludedCount = 0;
        $checkedCount = 0;
        $maxChecked = 1000;
        $hitLimit = 100;
        $reachedEnd = false;

        $fetchAndSave = function (int $requestOffset, bool $advancePastOffset) use (
            $siteCode,
            $serviceCode,
            $floorCode,
            $extraParams,
            $excludeKeywords,
            $hitLimit,
            &$apiCount,
            &$newCount,
            &$updatedCount,
            &$excludedCount,
            &$checkedCount,
            &$nextOffset,
            &$reachedEnd,
            $targetNew
        ): int {
            $requestOffset = $this->normalizeItemListOffset($requestOffset);
            $requestParams = array_merge($extraParams, ['hits' => $hitLimit, 'offset' => $requestOffset]);
            $response = $this->client->fetchItems($siteCode, $serviceCode, $floorCode, $requestParams);
            $fetchedItems = DmmNormalizer::normalizeItemsResponse($response);
            $fetchedCount = count($fetchedItems);
            $apiCount += $fetchedCount;
            $checkedCount += $fetchedCount;
            if ($fetchedCount === 0) {
                $reachedEnd = true;
                if ($advancePastOffset) {
                    $nextOffset = 1;
                }
                return 0;
            }

            $processedCount = 0;
            $saveItems = [];
            $saveUpdatedCount = 0;
            $existingContentIds = $this->itemsExistByContentIds(array_map(static function (array $item): string {
                return (string)($item['content_id'] ?? '');
            }, $fetchedItems));
            foreach ($fetchedItems as $item) {
                $processedCount++;
                $excluded = false;
                if ($excludeKeywords !== []) {
                    $title = (string)($item['title'] ?? '');
                    foreach ($excludeKeywords as $keyword) {
                        $keyword = trim((string)$keyword);
                        if ($keyword !== '' && mb_strpos($title, $keyword) !== false) {
                            $excluded = true;
                            break;
                        }
                    }
                }
                if ($excluded) {
                    $excludedCount++;
                    continue;
                }

                $contentId = (string)($item['content_id'] ?? '');
                $exists = isset($existingContentIds[$contentId]);
                if (!$exists && $newCount >= $targetNew) {
                    $processedCount--;
                    break;
                }

                $saveItems[] = $item;
                if ($exists) {
                    $saveUpdatedCount++;
                    continue;
                }
                $newCount++;
                if ($contentId !== '') {
                    $existingContentIds[$contentId] = true;
                }
            }

            if ($saveItems !== []) {
                $this->saveItemsWithStats($saveItems, 'items', false);
                $updatedCount += $saveUpdatedCount;
            }

            if ($advancePastOffset) {
                $nextOffset = $this->normalizeItemListOffset($requestOffset + $processedCount);
                if ($fetchedCount < $hitLimit) {
                    $nextOffset = 1;
                    $reachedEnd = true;
                }
            }

            return $fetchedCount;
        };

        $fetchAndSave(1, false);

        while ($newCount < $targetNew && $checkedCount < $maxChecked && !$reachedEnd) {
            $fetchAndSave($currentOffset, true);
            $currentOffset = $nextOffset;
            if ($nextOffset === 1 && $reachedEnd) {
                break;
            }
        }

        $message = sprintf(
            '商品を同期しました。API取得: %d件 / 新規: %d件 / 更新: %d件 / 除外: %d件 / 次回offset: %d',
            $apiCount,
            $newCount,
            $updatedCount,
            $excludedCount,
            $nextOffset
        );
        $this->logSync('items', 1, $newCount, $message);

        return [
            'synced_count' => $newCount,
            'api_count' => $apiCount,
            'new_count' => $newCount,
            'updated_count' => $updatedCount,
            'excluded_count' => $excludedCount,
            'checked_count' => $checkedCount,
            'next_offset' => $nextOffset,
            'message' => $message,
        ];
    }

    private function normalizeItemListOffset(int $offset): int
    {
        $offset = max(1, $offset);
        return $offset > 50000 ? 1 : $offset;
    }

    private function saveItems(array $items, string $logType): int
    {
        $stats = $this->saveItemsWithStats($items, $logType, true);
        return (int)$stats['saved_count'];
    }

    private function saveItemsWithStats(array $items, string $logType, bool $writeLog): array
    {
        $count = 0;
        $newCount = 0;
        $updatedCount = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $exists = $this->itemExistsByContentId((string)($item['content_id'] ?? ''));
                $itemId = $this->upsertItem($item);
                $this->rebuildItemRelations($itemId, $item);
                if (function_exists('generate_tags_for_item')) {
                    generate_tags_for_item([
                        'content_id' => $item['content_id'] ?? '',
                        'title' => $item['title'] ?? '',
                        'category_name' => $item['category_name'] ?? '',
                    ]);
                }
                $count++;
                if ($exists) {
                    $updatedCount++;
                } else {
                    $newCount++;
                }
            }
            $this->pdo->commit();
            if ($writeLog) {
                $this->logSync($logType, 1, $count, 'Item sync completed.');
            }
            return ['saved_count' => $count, 'new_count' => $newCount, 'updated_count' => $updatedCount];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($writeLog) {
                $this->logSync($logType, 0, 0, $e->getMessage());
            }
            throw $e;
        }
    }

    private function itemExistsByContentId(string $contentId): bool
    {
        if ($contentId === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM items WHERE content_id = ? LIMIT 1');
        $stmt->execute([$contentId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param string[] $contentIds
     * @return array<string, bool>
     */
    private function itemsExistByContentIds(array $contentIds): array
    {
        $contentIds = array_values(array_unique(array_filter(array_map('strval', $contentIds), static fn (string $contentId): bool => $contentId !== '')));
        if ($contentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
        $stmt = $this->pdo->prepare("SELECT content_id FROM items WHERE content_id IN ({$placeholders})");
        $stmt->execute($contentIds);

        $exists = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $contentId) {
            $exists[(string)$contentId] = true;
        }
        return $exists;
    }

    private function upsertSimple(string $table, string $codeColumn, string $code, string $name): void
    {
        $this->pdo->prepare("INSERT INTO {$table}({$codeColumn},name,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()")
            ->execute([$code, $name]);
    }

    private function upsertItem(array $item): int
    {
        $sql = 'INSERT INTO items(content_id,product_id,item_source,title,service_code,service_name,floor_code,floor_name,category_name,volume,review_count,review_average,url,affiliate_url,image_list,image_small,image_large,sample_movie_url_476,sample_movie_url_560,sample_movie_url_644,sample_movie_url_720,sample_movie_pc_flag,sample_movie_sp_flag,price_min_text,list_price_text,release_date,raw_json,updated_at)
                VALUES(:content_id,:product_id,:item_source,:title,:service_code,:service_name,:floor_code,:floor_name,:category_name,:volume,:review_count,:review_average,:url,:affiliate_url,:image_list,:image_small,:image_large,:u476,:u560,:u644,:u720,:pc,:sp,:price_min,:list_price,:release_date,:raw_json,NOW())
                ON DUPLICATE KEY UPDATE item_source=VALUES(item_source),title=VALUES(title),service_name=VALUES(service_name),floor_name=VALUES(floor_name),category_name=VALUES(category_name),volume=VALUES(volume),review_count=VALUES(review_count),review_average=VALUES(review_average),url=VALUES(url),affiliate_url=VALUES(affiliate_url),image_list=VALUES(image_list),image_small=VALUES(image_small),image_large=VALUES(image_large),sample_movie_url_476=VALUES(sample_movie_url_476),sample_movie_url_560=VALUES(sample_movie_url_560),sample_movie_url_644=VALUES(sample_movie_url_644),sample_movie_url_720=VALUES(sample_movie_url_720),sample_movie_pc_flag=VALUES(sample_movie_pc_flag),sample_movie_sp_flag=VALUES(sample_movie_sp_flag),price_min_text=VALUES(price_min_text),list_price_text=VALUES(list_price_text),release_date=VALUES(release_date),raw_json=VALUES(raw_json),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([
            'content_id' => $item['content_id'], 'product_id' => $item['product_id'], 'item_source' => 'fanza_product', 'title' => $item['title'],
            'service_code' => $item['service_code'], 'service_name' => $item['service_name'], 'floor_code' => $item['floor_code'], 'floor_name' => $item['floor_name'],
            'category_name' => $item['category_name'], 'volume' => $item['volume'], 'review_count' => $item['review_count'], 'review_average' => $item['review_average'],
            'url' => $item['url'], 'affiliate_url' => $item['affiliate_url'], 'image_list' => $item['image_list'], 'image_small' => $item['image_small'], 'image_large' => $item['image_large'],
            'u476' => $item['sample_movie_url_476'], 'u560' => $item['sample_movie_url_560'], 'u644' => $item['sample_movie_url_644'], 'u720' => $item['sample_movie_url_720'],
            'pc' => $item['sample_movie_pc_flag'], 'sp' => $item['sample_movie_sp_flag'], 'price_min' => $item['price_min_text'], 'list_price' => $item['list_price_text'],
            'release_date' => $item['release_date'], 'raw_json' => json_encode($item['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $idStmt = $this->pdo->prepare('SELECT id FROM items WHERE content_id = ?');
        $idStmt->execute([$item['content_id']]);
        return (int) $idStmt->fetchColumn();
    }

    private function rebuildItemRelations(int $itemId, array $item): void
    {
        $tables = ['item_actresses', 'item_genres', 'item_campaigns', 'item_labels', 'item_directors', 'item_makers', 'item_series', 'item_authors', 'item_actors'];
        foreach ($tables as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE item_id = ?")->execute([$itemId]);
        }

        $this->insertRelation($itemId, 'item_actresses', 'actress_name', $item['actresses']);
        $this->insertRelation($itemId, 'item_genres', 'genre_name', $item['genres']);
        $this->insertRelation($itemId, 'item_campaigns', 'campaign_name', $item['campaigns']);
        $this->insertRelation($itemId, 'item_labels', 'label_name', $item['labels']);
        $this->insertRelation($itemId, 'item_directors', 'director_name', $item['directors']);
        $this->insertRelation($itemId, 'item_makers', 'maker_name', $item['makers']);
        $this->insertRelation($itemId, 'item_series', 'series_name', $item['series']);
        $this->insertRelation($itemId, 'item_authors', 'author_name', $item['authors']);
        $this->insertRelation($itemId, 'item_actors', 'actor_name', $item['actors'] ?? []);
    }

    private function insertRelation(int $itemId, string $table, string $nameCol, array $rows): void
    {
        $masterMap = [
            'item_actresses' => 'actresses',
            'item_genres' => 'genres',
            'item_makers' => 'makers',
            'item_series' => 'series_master',
            'item_authors' => 'authors',
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dmmId = trim((string)($row['id'] ?? ''));
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if ($dmmId === '') {
                $dmmId = 'name:' . sha1(mb_strtolower($name, 'UTF-8'));
            }

            $this->pdo->prepare("INSERT IGNORE INTO {$table}(item_id,dmm_id,{$nameCol}) VALUES(?,?,?)")
                ->execute([$itemId, $dmmId, $name]);

            $masterTable = $masterMap[$table] ?? null;
            if (is_string($masterTable) && $masterTable !== '' && $dmmId !== '') {
                $this->pdo->prepare("INSERT INTO {$masterTable}(dmm_id,name,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=NOW()")
                    ->execute([$dmmId, $name]);
            }
        }
    }

    public function logSync(string $type, int $isSuccess, int $count, string $message): void
    {
        $this->pdo->prepare('INSERT INTO sync_logs(sync_type,is_success,synced_count,message,created_at) VALUES(?,?,?,?,NOW())')
            ->execute([$type, $isSuccess, $count, mb_substr($message, 0, 1000)]);
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_makers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,dmm_id VARCHAR(64) NULL,maker_name VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_item_maker (item_id,dmm_id),CONSTRAINT fk_item_maker_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_series (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,dmm_id VARCHAR(64) NULL,series_name VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_item_series (item_id,dmm_id),CONSTRAINT fk_item_series_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_authors (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,dmm_id VARCHAR(64) NULL,author_name VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_item_author (item_id,dmm_id),CONSTRAINT fk_item_author_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_actors (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,dmm_id VARCHAR(64) NULL,actor_name VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_item_actor (item_id,dmm_id),CONSTRAINT fk_item_actor_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sync_job_state (job_key VARCHAR(64) PRIMARY KEY,next_offset INT NOT NULL DEFAULT 1,next_initial VARCHAR(10) NULL,last_run_at DATETIME NULL,last_success TINYINT(1) NOT NULL DEFAULT 0,last_message TEXT NULL,lock_until DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $itemColumns = [];
        $itemStmt = $this->pdo->query('SHOW COLUMNS FROM items');
        foreach (($itemStmt ? $itemStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $col) {
            $itemColumns[(string)($col['Field'] ?? '')] = true;
        }
        if (!isset($itemColumns['view_count'])) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN view_count INT NOT NULL DEFAULT 0');
        }
        if (!isset($itemColumns['item_source'])) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN item_source VARCHAR(32) NOT NULL DEFAULT "unknown" AFTER product_id');
        }
        try {
            $this->pdo->exec('CREATE INDEX idx_items_item_source_release ON items(item_source, release_date, id)');
        } catch (Throwable) {
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS page_views (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,ip_hash VARCHAR(64) NULL,user_agent VARCHAR(255) NULL,INDEX idx_page_views_item_date (item_id, viewed_at),CONSTRAINT fk_page_views_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS tags (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_tags (item_id INT UNSIGNED NOT NULL,tag_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (item_id, tag_id),INDEX idx_item_tags_tag (tag_id),CONSTRAINT fk_item_tags_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,CONSTRAINT fk_item_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS api_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,api_name VARCHAR(64) NOT NULL,request_url TEXT NOT NULL,request_hash CHAR(64) NOT NULL,response_status INT NULL,response_body MEDIUMTEXT NULL,cache_hit TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_api_logs_created (created_at),INDEX idx_api_logs_name (api_name),INDEX idx_api_logs_hash (request_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    }
}

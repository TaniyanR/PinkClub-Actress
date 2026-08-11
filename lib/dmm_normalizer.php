<?php

declare(strict_types=1);

class DmmNormalizer
{
    private static function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return null;
    }

    private static function normalizeMovieUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        return null;
    }

    private static function collectMovieUrlsFromValue(mixed $value, array &$urls): void
    {
        if (is_string($value)) {
            $candidate = self::normalizeMovieUrl($value);
            if ($candidate !== null) {
                $urls[] = $candidate;
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            self::collectMovieUrlsFromValue($child, $urls);
        }
    }

    private static function sampleMovieUrl(array $sampleMovie, string $key): ?string
    {
        $candidate = self::normalizeMovieUrl((string)($sampleMovie[$key] ?? ''));
        if ($candidate !== null) {
            return $candidate;
        }

        $urls = [];
        self::collectMovieUrlsFromValue($sampleMovie[$key] ?? null, $urls);
        if ($urls !== []) {
            return $urls[0];
        }

        self::collectMovieUrlsFromValue($sampleMovie, $urls);
        return $urls[0] ?? null;
    }

    private static function extractTitle(array $row): string
    {
        $infoTitle = $row['iteminfo']['title'][0] ?? [];
        $title = self::firstNonEmptyString(
            $row['title'] ?? null,
            $row['name'] ?? null,
            $row['productTitle'] ?? null,
            is_array($infoTitle) ? ($infoTitle['name'] ?? null) : null,
            is_array($infoTitle) ? ($infoTitle['value'] ?? null) : null
        );
        return $title ?? '';
    }

    private static function isInvalidName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }
        if (preg_match('/^[\-‐‑‒–—―ーｰ_\s]+$/u', $name) === 1) {
            return true;
        }
        return preg_match('/[�]|(?:Ã.|Â.|縺|繝|譁|螟)/u', $name) === 1;
    }

    private static function normalizeNamedList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $source = is_array($value) && array_is_list($value) ? $value : [$value];
        $rows = [];
        $seen = [];

        foreach ($source as $entry) {
            if (is_string($entry)) {
                $id = '';
                $name = trim($entry);
                $ruby = null;
            } elseif (is_array($entry)) {
                $id = trim((string)($entry['id'] ?? $entry['dmm_id'] ?? ''));
                $name = trim((string)($entry['name'] ?? $entry['value'] ?? $entry['text'] ?? ''));
                $ruby = isset($entry['ruby']) ? trim((string)$entry['ruby']) : null;
            } else {
                continue;
            }

            if (self::isInvalidName($name)) {
                continue;
            }

            $key = mb_strtolower($id !== '' ? 'id:' . $id : 'name:' . $name, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = ['id' => $id, 'name' => $name, 'ruby' => $ruby];
        }

        return $rows;
    }

    public static function toList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return $value;
            }
            return [$value];
        }

        return [];
    }

    public static function normalizeItemsResponse(array $response): array
    {
        $items = $response['result']['items'] ?? $response['result']['item'] ?? [];
        if (isset($items['item'])) {
            $items = $items['item'];
        }

        $rows = self::toList($items);
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $info = is_array($row['iteminfo'] ?? null) ? $row['iteminfo'] : [];
            $sampleMovie = $row['sampleMovieURL']
                ?? $row['sampleMovieUrl']
                ?? $row['sample_movie_url']
                ?? $row['sampleMovie']
                ?? $row['sampleMovieURLVR']
                ?? [];
            $delivery = $row['prices']['deliveries']['delivery'] ?? [];
            $deliveryList = self::toList($delivery);
            $priceMin = $deliveryList[0]['price'] ?? null;
            $listPrice = $deliveryList[0]['list_price'] ?? null;

            $normalized[] = [
                'raw' => $row,
                'content_id' => $row['content_id'] ?? null,
                'product_id' => $row['product_id'] ?? null,
                'title' => self::extractTitle($row),
                'service_code' => $row['service_code'] ?? '',
                'service_name' => $row['service_name'] ?? '',
                'floor_code' => $row['floor_code'] ?? '',
                'floor_name' => $row['floor_name'] ?? '',
                'category_name' => $row['category_name'] ?? null,
                'volume' => $row['volume'] ?? null,
                'review_count' => isset($row['review']['count']) ? (int) $row['review']['count'] : null,
                'review_average' => isset($row['review']['average']) ? (float) $row['review']['average'] : null,
                'url' => $row['URL'] ?? null,
                'affiliate_url' => $row['affiliateURL'] ?? null,
                'image_list' => self::firstNonEmptyString($row['imageURL']['list'] ?? null, $row['packageImage']['list'] ?? null),
                'image_small' => self::firstNonEmptyString($row['imageURL']['small'] ?? null, $row['packageImage']['small'] ?? null),
                'image_large' => self::firstNonEmptyString($row['imageURL']['large'] ?? null, $row['packageImage']['large'] ?? null),
                'sample_movie_url_476' => is_array($sampleMovie) ? self::sampleMovieUrl($sampleMovie, 'size_476_306') : self::normalizeMovieUrl((string)$sampleMovie),
                'sample_movie_url_560' => is_array($sampleMovie) ? self::sampleMovieUrl($sampleMovie, 'size_560_360') : null,
                'sample_movie_url_644' => is_array($sampleMovie) ? self::sampleMovieUrl($sampleMovie, 'size_644_414') : null,
                'sample_movie_url_720' => is_array($sampleMovie) ? self::sampleMovieUrl($sampleMovie, 'size_720_480') : null,
                'sample_movie_pc_flag' => is_array($sampleMovie) && isset($sampleMovie['pc_flag']) ? (int) $sampleMovie['pc_flag'] : 0,
                'sample_movie_sp_flag' => is_array($sampleMovie) && isset($sampleMovie['sp_flag']) ? (int) $sampleMovie['sp_flag'] : 0,
                'price_min_text' => $priceMin,
                'list_price_text' => $listPrice,
                'release_date' => !empty($row['date']) ? $row['date'] : null,
                'actresses' => self::normalizeNamedList($info['actress'] ?? []),
                'genres' => self::normalizeNamedList($info['genre'] ?? []),
                'makers' => self::normalizeNamedList($info['maker'] ?? []),
                'series' => self::normalizeNamedList($info['series'] ?? []),
                'authors' => self::normalizeNamedList($info['author'] ?? []),
                'directors' => self::normalizeNamedList($info['director'] ?? []),
                'labels' => self::normalizeNamedList($info['label'] ?? []),
                'campaigns' => self::normalizeNamedList($row['campaign'] ?? []),
                'actors' => self::normalizeNamedList($info['actor'] ?? []),
            ];
        }

        return $normalized;
    }
}

<?php

declare(strict_types=1);

function pcf_actress_directory_cache_dir(): string
{
    // v5: 通常女優/しろうと分離 + 同一表示名の重複を1人へ統合。
    return dirname(__DIR__) . '/storage/cache/actress-directory-public-v5';
}

function pcf_actress_directory_cache_manifest_path(): string
{
    return pcf_actress_directory_cache_dir() . '/manifest.json';
}

function pcf_actress_directory_cache_read_manifest(): ?array
{
    $path = pcf_actress_directory_cache_manifest_path();
    if (!is_file($path)) return null;
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) && isset($decoded['groups']) && is_array($decoded['groups']) ? $decoded : null;
}

function pcf_actress_directory_invalid_name(string $name): bool
{
    if (function_exists('pcf_is_noise_name') && pcf_is_noise_name($name)) return true;
    $value = mb_strtolower(trim($name), 'UTF-8');
    if ($value === '') return true;
    foreach (['相互リンク','相互rss','お問い合わせ','privacy policy','プライバシー','サイトについて','公式サイト','オフィシャルサイト'] as $invalid) {
        if (str_contains($value, mb_strtolower($invalid, 'UTF-8'))) return true;
    }
    return false;
}

function pcf_actress_directory_normalized_name(string $name): string
{
    $value = mb_convert_kana(trim($name), 'asKV', 'UTF-8');
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    return mb_strtolower($value, 'UTF-8');
}

function pcf_actress_directory_row_score(array $row): int
{
    $score = 0;
    foreach (['image_large','image_small','image_url'] as $key) if (trim((string)($row[$key] ?? '')) !== '') { $score += 100; break; }
    foreach (['ruby','birthday','prefectures'] as $key) if (trim((string)($row[$key] ?? '')) !== '') $score += 10;
    return $score;
}

function pcf_actress_directory_group_key(array $row): string
{
    $name = trim((string)($row['name'] ?? ''));
    $ruby = trim((string)($row['ruby'] ?? ''));
    $first = mb_substr($ruby !== '' ? $ruby : $name, 0, 1, 'UTF-8');
    if ($first === '') return '';
    $hiragana = mb_convert_kana($first, 'c', 'UTF-8');
    foreach ([
        'kana:あ'=>'/^[ぁ-お]/u','kana:か'=>'/^[か-ご]/u','kana:さ'=>'/^[さ-ぞ]/u','kana:た'=>'/^[た-ど]/u',
        'kana:な'=>'/^[な-の]/u','kana:は'=>'/^[は-ぽ]/u','kana:ま'=>'/^[ま-も]/u','kana:や'=>'/^[や-よ]/u',
        'kana:ら'=>'/^[ら-ろ]/u','kana:わ'=>'/^[わ-ん]/u',
    ] as $key=>$pattern) if (preg_match($pattern,$hiragana)) return $key;
    return preg_match('/^[A-Za-z]/',$first) ? 'alpha:'.strtoupper($first) : '';
}

function pcf_actress_directory_cache_rebuild(bool $force = false): array
{
    $directory = pcf_actress_directory_cache_dir();
    if (!is_dir($directory) && !@mkdir($directory,0755,true) && !is_dir($directory)) throw new RuntimeException('女優一覧キャッシュの保存先を作成できません。');
    $lock = @fopen($directory.'/rebuild.lock','c');
    if ($lock === false) throw new RuntimeException('女優一覧キャッシュのロックを作成できません。');

    try {
        if (!flock($lock,LOCK_EX)) throw new RuntimeException('女優一覧キャッシュをロックできません。');
        $manifestPath = pcf_actress_directory_cache_manifest_path();
        if (!$force && is_file($manifestPath) && filemtime($manifestPath)>=time()-3600) {
            $existing = pcf_actress_directory_cache_read_manifest();
            if (is_array($existing)) return $existing;
        }

        $stmt = db()->query(
            "SELECT a.id,a.dmm_id,a.name,a.ruby,a.birthday,a.prefectures,a.image_small,a.image_large,a.image_url
             FROM actresses a
             WHERE TRIM(COALESCE(a.name,''))<>''
               AND a.dmm_id REGEXP '^[0-9]+$'
               AND NOT (
                 EXISTS (SELECT 1 FROM item_actresses ia_c INNER JOIN items i_c ON i_c.id=ia_c.item_id WHERE ia_c.dmm_id=a.dmm_id AND (i_c.floor_code='videoc' OR i_c.floor_name LIKE '%素人%' OR i_c.floor_name LIKE '%しろうと%' OR i_c.floor_name LIKE '%シロウト%'))
                 AND NOT EXISTS (SELECT 1 FROM item_actresses ia_a INNER JOIN items i_a ON i_a.id=ia_a.item_id WHERE ia_a.dmm_id=a.dmm_id AND i_a.floor_code='videoa')
               )
             ORDER BY a.name ASC,a.id ASC LIMIT 10000"
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        // AI/Ai、あいか/あいか等、表示上同じ名前は1人へ統合。
        // 画像・プロフィール情報が豊富なレコードを優先する。
        $bestByName = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id=(int)($row['id']??0); $name=trim((string)($row['name']??''));
            if ($id<=0 || $name==='' || pcf_actress_directory_invalid_name($name)) continue;
            $nameKey = pcf_actress_directory_normalized_name($name);
            if ($nameKey==='') continue;
            $score = pcf_actress_directory_row_score($row);
            if (!isset($bestByName[$nameKey])) { $bestByName[$nameKey]=['row'=>$row,'score'=>$score]; continue; }
            $current=$bestByName[$nameKey];
            if ($score>(int)$current['score'] || ($score===(int)$current['score'] && $id<(int)($current['row']['id']??PHP_INT_MAX))) {
                $bestByName[$nameKey]=['row'=>$row,'score'=>$score];
            }
        }

        $groups=[];
        foreach ($bestByName as $entry) {
            $row=$entry['row'];
            $key=pcf_actress_directory_group_key($row);
            if ($key==='') continue;
            $image='';
            foreach (['image_large','image_small','image_url'] as $imageKey) {
                $candidate=trim((string)($row[$imageKey]??'')); if($candidate!==''){ $image=$candidate; break; }
            }
            $groups[$key][]=[(int)$row['id'],(string)$row['name'],$image];
        }
        foreach ($groups as &$groupRows) usort($groupRows,static fn(array $a,array $b):int=>strcmp(mb_strtolower((string)$a[1],'UTF-8'),mb_strtolower((string)$b[1],'UTF-8')));
        unset($groupRows);

        $orderedKeys=array_map(static fn(string $kana):string=>'kana:'.$kana,['あ','か','さ','た','な','は','ま','や','ら','わ']);
        $alphaKeys=array_values(array_filter(array_keys($groups),static fn(string $key):bool=>str_starts_with($key,'alpha:'))); sort($alphaKeys,SORT_STRING);
        $orderedKeys=array_merge($orderedKeys,$alphaKeys);
        $manifestGroups=[];
        foreach($orderedKeys as $key){
            $groupRows=$groups[$key]??[]; if($groupRows===[])continue;
            $filename=sha1($key).'.json'; $payload=json_encode($groupRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $tmp=$directory.'/'.$filename.'.tmp';
            if($payload===false || @file_put_contents($tmp,$payload,LOCK_EX)===false || !@rename($tmp,$directory.'/'.$filename)){ @unlink($tmp); throw new RuntimeException('女優一覧の行キャッシュを保存できません。'); }
            $manifestGroups[]=['key'=>$key,'label'=>str_starts_with($key,'kana:')?mb_substr($key,5):substr($key,6),'type'=>str_starts_with($key,'kana:')?'kana':'alpha','count'=>count($groupRows),'file'=>$filename];
        }
        $manifest=['created_at'=>time(),'groups'=>$manifestGroups];
        $json=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $tmpManifest=$manifestPath.'.tmp';
        if($json===false || @file_put_contents($tmpManifest,$json,LOCK_EX)===false || !@rename($tmpManifest,$manifestPath)){ @unlink($tmpManifest); throw new RuntimeException('女優一覧キャッシュの目次を保存できません。'); }
        return $manifest;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

function pcf_actress_directory_cache_count(array $manifest): ?int
{
    $count=0;
    foreach(($manifest['groups']??[]) as $group){
        if(!is_array($group))continue;
        if(array_key_exists('count',$group)){ $count+=max(0,(int)$group['count']); continue; }
        $filename=basename((string)($group['file']??'')); if($filename==='')return null;
        $rows=json_decode((string)@file_get_contents(pcf_actress_directory_cache_dir().'/'.$filename),true); if(!is_array($rows))return null; $count+=count($rows);
    }
    return $count;
}

function pcf_actress_directory_cache_manifest(): array
{
    $path=pcf_actress_directory_cache_manifest_path();
    if(is_file($path)&&filemtime($path)>=time()-3600){ $manifest=pcf_actress_directory_cache_read_manifest(); if(is_array($manifest))return $manifest; }
    return pcf_actress_directory_cache_rebuild();
}

function pcf_actress_directory_cache_group(string $key): array
{
    $manifest=pcf_actress_directory_cache_manifest();
    foreach(($manifest['groups']??[]) as $group){
        if(!is_array($group)||(string)($group['key']??'')!==$key)continue;
        $filename=basename((string)($group['file']??''));
        $decoded=json_decode((string)@file_get_contents(pcf_actress_directory_cache_dir().'/'.$filename),true);
        return is_array($decoded)?$decoded:[];
    }
    return [];
}

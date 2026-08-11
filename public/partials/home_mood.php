<?php
declare(strict_types=1);

$moods = [
    ['label' => '可愛い作品', 'query' => '美少女 アイドル 制服 萌え 清楚'],
    ['label' => 'イチャイチャ系', 'query' => '恋人 ラブラブ 同棲 カップル イチャイチャ'],
    ['label' => '激しい作品', 'query' => 'ハード 激しい 絶頂 痙攣'],
    ['label' => 'ストーリー重視', 'query' => 'ドラマ ストーリー 長編 映画'],
    ['label' => '美少女', 'query' => '美少女 透明感 かわいい'],
    ['label' => '清楚系', 'query' => '清楚 おしとやか 純粋'],
    ['label' => 'お姉さん', 'query' => 'お姉さん 年上 美人'],
    ['label' => '人妻', 'query' => '人妻 若妻 奥さん'],
    ['label' => '熟女', 'query' => '熟女 母性 熟れた'],
    ['label' => 'ギャル', 'query' => 'ギャル 派手 金髪'],
    ['label' => '制服', 'query' => '制服 セーラー服 ブレザー'],
    ['label' => 'コスプレ', 'query' => 'コスプレ 衣装 仮装'],
    ['label' => 'ナース', 'query' => 'ナース 看護師 病院'],
    ['label' => '女教師', 'query' => '女教師 先生 教室'],
    ['label' => 'アイドル', 'query' => 'アイドル 芸能 グラビア'],
    ['label' => '巨乳', 'query' => '巨乳 爆乳 豊満'],
    ['label' => 'スレンダー', 'query' => 'スレンダー 美脚 細身'],
    ['label' => '癒やし系', 'query' => '癒し 優しい 甘い'],
    ['label' => '恋愛気分', 'query' => '恋愛 恋人 デート'],
    ['label' => 'じっくり長編', 'query' => '長編 大作 4時間'],
    ['label' => '短時間で楽しむ', 'query' => '短時間 ショート 短編'],
    ['label' => '新人デビュー', 'query' => '新人 デビュー 初撮り'],
    ['label' => '単体作品', 'query' => '単体作品 専属 単体'],
    ['label' => 'VR作品', 'query' => 'VR専用 8KVR VR'],
    ['label' => '8K・高画質', 'query' => '8K 4K 高画質'],
    ['label' => 'マッサージ', 'query' => 'マッサージ エステ オイル'],
    ['label' => '痴女', 'query' => '痴女 誘惑 責め'],
    ['label' => '素人作品', 'query' => '素人 ナンパ 一般人'],
    ['label' => '独占配信', 'query' => '独占配信 独占 オリジナル'],
    ['label' => 'ドキュメンタリー', 'query' => 'ドキュメンタリー 密着 記録'],
];
$moodLinks = array_map(static fn(array $mood): array => [
    'label' => (string)$mood['label'],
    'href' => public_url('search.php') . '?' . http_build_query(['q' => (string)$mood['query']]),
], $moods);
?>
<section aria-label="気分から作品を探す" style="margin:0 0 16px;padding:12px 14px;border:1px solid #e0e0e0;border-radius:6px;background:#fff;box-sizing:border-box;">
  <strong style="display:block;margin:0 0 10px;font-size:14px;line-height:1.4;">【 気分で探す 】</strong>
  <div data-mood-links style="display:flex;align-items:center;justify-content:flex-start;gap:8px;flex-wrap:wrap;"></div>
</section>
<script>
(() => {
  const container = document.querySelector('[data-mood-links]');
  if (!container) return;
  const moods = <?= json_encode($moodLinks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const media = window.matchMedia('(max-width: 768px)');
  const render = () => {
    const shuffled = moods.slice();
    for (let i = shuffled.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
    }
    container.replaceChildren();
    for (const mood of shuffled.slice(0, media.matches ? 3 : 8)) {
      const link = document.createElement('a');
      link.href = mood.href;
      link.textContent = mood.label;
      link.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:6px 12px;border:1px solid #777;border-radius:5px;background:#fff;color:#222;text-decoration:none;font-size:13px;font-weight:700;line-height:1.2;box-sizing:border-box;white-space:nowrap;';
      container.appendChild(link);
    }
  };
  render();
  if (media.addEventListener) {
    media.addEventListener('change', render);
  } else {
    media.addListener(render);
  }
})();
</script>

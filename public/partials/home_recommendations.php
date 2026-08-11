<?php
declare(strict_types=1);
?>
<section id="pcf-recommendations" class="pcf-recommendations" data-endpoint="<?= e(public_url('recommendations.php')) ?>" aria-labelledby="pcf-recommendations-title" hidden>
  <div class="pcf-recommendations__heading">
    <div>
      <h2 id="pcf-recommendations-title">あなたへのおすすめ</h2>
      <p>最近見た作品の傾向をもとに、このブラウザ内で選んでいます。</p>
    </div>
    <button id="pcf-recommendations-hide" class="pcf-recommendations__control" type="button">おすすめを表示しない</button>
  </div>
  <div id="pcf-recommendations-list" class="pcf-recommendations__list" aria-live="polite"></div>
</section>
<div id="pcf-recommendations-restore" class="pcf-recommendations-restore" hidden>
  <button id="pcf-recommendations-show" type="button">あなたへのおすすめを表示する</button>
</div>
<style>
.pcf-recommendations{margin:18px 0 24px;padding:16px;border:1px solid #ddd;border-radius:8px;background:#fff}
.pcf-recommendations__heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.pcf-recommendations__heading h2{margin:0 0 4px;font-size:22px}
.pcf-recommendations__heading p{margin:0;color:#666;font-size:12px;line-height:1.5}
.pcf-recommendations__control,.pcf-recommendations-restore button{border:1px solid #888;border-radius:5px;background:#fff;color:#333;cursor:pointer}
.pcf-recommendations__control{padding:7px 10px;font-size:12px;white-space:nowrap}
.pcf-recommendations__list{display:flex;gap:14px;overflow-x:auto;padding:2px 2px 10px;scrollbar-width:thin}
.pcf-recommendations__card{flex:0 0 180px;min-width:180px}
.pcf-recommendations__image{display:block;width:180px;height:250px;object-fit:cover;border-radius:6px;background:#eee}
.pcf-recommendations__title{display:-webkit-box;margin:8px 0 6px;overflow:hidden;color:#1670b7;font-size:14px;font-weight:700;line-height:1.45;text-decoration:none;-webkit-box-orient:vertical;-webkit-line-clamp:3}
.pcf-recommendations__reason{margin:0;color:#666;font-size:11px;line-height:1.45}
.pcf-recommendations-restore{margin:10px 0 20px;text-align:right}
.pcf-recommendations-restore button{padding:6px 9px;font-size:12px}
@media (max-width:700px){.pcf-recommendations{padding:12px}.pcf-recommendations__heading{display:block}.pcf-recommendations__control{margin-top:9px}.pcf-recommendations__card{flex-basis:150px;min-width:150px}.pcf-recommendations__image{width:150px;height:210px}.pcf-recommendations-restore{text-align:left}}
</style>

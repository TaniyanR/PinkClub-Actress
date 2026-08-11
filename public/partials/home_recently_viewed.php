<?php
declare(strict_types=1);
?>
<section id="pcf-recently-viewed" class="pcf-recent" aria-labelledby="pcf-recent-title" hidden>
  <div class="pcf-recent__heading">
    <div>
      <h2 id="pcf-recent-title">最近見た作品</h2>
      <p>閲覧履歴は、このブラウザ内だけに保存されます。</p>
    </div>
    <div class="pcf-recent__heading-actions">
      <button id="pcf-recent-hide" class="pcf-recent__control" type="button" onclick="try{localStorage.setItem('pcf_recently_viewed_hidden_v1','1')}catch(e){}var s=document.getElementById('pcf-recently-viewed');var r=document.getElementById('pcf-recent-restore');if(s)s.hidden=true;if(r)r.hidden=false;">履歴を表示しない</button>
      <button id="pcf-recent-clear" class="pcf-recent__control" type="button">履歴をすべて削除</button>
    </div>
  </div>
  <div id="pcf-recent-list" class="pcf-recent__list" aria-live="polite"></div>
</section>
<div id="pcf-recent-restore" class="pcf-recent-restore" hidden>
  <button id="pcf-recent-show" type="button" onclick="try{localStorage.removeItem('pcf_recently_viewed_hidden_v1')}catch(e){}var s=document.getElementById('pcf-recently-viewed');var r=document.getElementById('pcf-recent-restore');if(r)r.hidden=true;if(s)s.hidden=false;">最近見た作品を表示する</button>
</div>
<style>
.pcf-recent{margin:18px 0 24px;padding:16px;border:1px solid #ddd;border-radius:8px;background:#fff}
.pcf-recent__heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.pcf-recent__heading h2{margin:0 0 4px;font-size:22px}
.pcf-recent__heading p{margin:0;color:#666;font-size:12px;line-height:1.5}
.pcf-recent__heading-actions{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}
.pcf-recent__control,.pcf-recent__remove,.pcf-recent-restore button{border:1px solid #888;border-radius:5px;background:#fff;color:#333;cursor:pointer}
.pcf-recent__control{padding:7px 10px;font-size:12px;white-space:nowrap}
.pcf-recent__list{display:flex;gap:14px;overflow-x:auto;padding:2px 2px 10px;scrollbar-width:thin}
.pcf-recent__card{position:relative;display:flex;flex:0 0 180px;min-width:180px;flex-direction:column}
.pcf-recent__card-image{display:block;width:180px;height:250px;object-fit:cover;border-radius:6px;background:#eee}
.pcf-recent__card-title{display:-webkit-box;height:4.35em;margin:8px 0 7px;overflow:hidden;color:#1670b7;font-size:14px;font-weight:700;line-height:1.45;text-decoration:none;-webkit-box-orient:vertical;-webkit-line-clamp:3}
.pcf-recent__card-actions{display:flex;gap:6px;align-items:center;margin-top:auto}
.pcf-recent__open{flex:1;padding:7px 8px;border:1px solid #555;border-radius:5px;color:#222;text-align:center;text-decoration:none;font-size:12px;font-weight:700}
.pcf-recent__remove{padding:7px 8px;font-size:12px}
.pcf-recent-restore{margin:10px 0 20px;text-align:right}
.pcf-recent-restore button{padding:6px 9px;font-size:12px}
@media (max-width:700px){.pcf-recent{padding:12px}.pcf-recent__heading{display:block}.pcf-recent__heading-actions{justify-content:flex-start;margin-top:9px}.pcf-recent__card{flex-basis:150px;min-width:150px}.pcf-recent__card-image{width:150px;height:210px}.pcf-recent-restore{text-align:left}}
</style>

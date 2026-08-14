<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$safeTextSetting = static function (string $key, string $default = ''): string {
    if (function_exists('front_safe_text_setting')) {
        return front_safe_text_setting($key, $default);
    }

    try {
        if (function_exists('setting')) {
            $value = setting($key, $default);
            return is_string($value) ? $value : $default;
        }
        if (function_exists('app_setting_get')) {
            $value = app_setting_get($key, $default);
            return is_string($value) ? $value : $default;
        }
    } catch (Throwable $e) {
        if (function_exists('app_log_error')) {
            app_log_error('footer safe text setting fallback failed: ' . $key, $e);
        }
    }

    return $default;
};

$siteName = trim($safeTextSetting('site_name', ''));
if ($siteName === '') {
    $siteName = trim($safeTextSetting('site.title', ''));
}
if ($siteName === '') {
    $siteName = 'PinkClub Actress';
}

$copyrightStartYear = (int)date('Y');
try {
    $pdo = db();
    $startDate = null;
    foreach (['date_published', 'release_date', 'created_at'] as $column) {
        $stmt = $pdo->query("SELECT MIN(" . $column . ") FROM items WHERE " . $column . " IS NOT NULL AND " . $column . " <> ''");
        $value = $stmt ? trim((string)$stmt->fetchColumn()) : '';
        if ($value !== '') {
            $startDate = $value;
            break;
        }
    }
    if ($startDate !== null) {
        $timestamp = strtotime($startDate);
        if ($timestamp !== false) {
            $copyrightStartYear = (int)date('Y', $timestamp);
        }
    }
} catch (Throwable $e) {
}
$currentYear = (int)date('Y');
$copyrightYears = $copyrightStartYear >= $currentYear
    ? (string)$currentYear
    : $copyrightStartYear . '-' . $currentYear;

?>
  <?php $pageType = function_exists('ad_current_page_type') ? ad_current_page_type() : 'home'; ?>
  </div>
  <?php if (site_setting_get('link.rss_display.pc_text_bottom', '1') === '1'): ?>
  <div class="site-main__rss only-pc">
    <?php render_shared_content_ad_row('content_bottom', $pageType); ?>
  </div>
  <?php endif; ?>
  </main>
</div>
<button type="button" class="page-top-button" aria-label="トップに戻る">↑ トップへ</button>
<?php if (function_exists('render_ad') && (!function_exists('should_show_ad') || should_show_ad('sp_footer_above', $pageType, 'sp'))): ?>
<div class="only-sp site-ad site-ad--sp-footer-above"><?php render_ad('sp_footer_above', $pageType, 'sp'); ?></div>
<?php endif; ?>
<?php if (site_setting_get('link.rss_display.sp_footer_above', '1') === '1'): ?>
<div class="site-main__rss only-sp">
  <?php render_shared_mobile_rss_widget(); ?>
</div>
<?php endif; ?>
<footer class="site-footer">
  <div class="site-footer__credit">
    <a href="https://affiliate.dmm.com/api/"><img src="https://p.dmm.co.jp/p/affiliate/web_service/r18_135_17.gif" width="135" height="17" alt="WEB SERVICE BY FANZA" /></a>
  </div>
  <div class="site-footer__copy">© <?= e($copyrightYears) ?> <a href="<?= e(public_url('')) ?>"><?= e($siteName) ?></a></div>
</footer>
<script>
(function () {
  var header = document.querySelector('.site-header');
  var button = document.querySelector('.page-top-button');
  if (!header || !button) return;
  var updateButton = function () { button.classList.toggle('is-visible', header.getBoundingClientRect().bottom <= 0); };
  button.addEventListener('click', function () {
    if ('scrollBehavior' in document.documentElement.style) window.scrollTo({ top: 0, behavior: 'smooth' });
    else window.scrollTo(0, 0);
  });
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) { button.classList.toggle('is-visible', !entries[0].isIntersecting); });
    observer.observe(header);
  } else {
    window.addEventListener('scroll', updateButton);
    window.addEventListener('resize', updateButton);
  }
  updateButton();
}());
</script>
<script>
(function () {
  var cards = document.querySelectorAll('.rail-row--home-actresses .rail-card');
  cards.forEach(function (card) {
    var image = card.querySelector('img.thumb');
    var titleLink = card.querySelector('a.rail-card__title');
    if (!image || !titleLink || image.parentElement.tagName.toLowerCase() === 'a') return;
    var imageLink = document.createElement('a');
    imageLink.href = titleLink.href;
    imageLink.setAttribute('aria-label', titleLink.textContent.trim());
    image.parentNode.insertBefore(imageLink, image);
    imageLink.appendChild(image);
  });
}());
</script>
<script>
(function () {
  var params = new URLSearchParams(window.location.search);
  if (window.location.pathname.indexOf('page.php') === -1 || params.get('slug') !== 'que') return;
  var contactForm = document.querySelector('form.contact-form');
  if (!contactForm) return;

  var blocks = document.querySelectorAll('section.block');
  var introBlock = blocks.length > 0 ? blocks[0] : null;
  var formBlock = contactForm.closest('section.block');
  if (introBlock) {
    var pageTitle = introBlock.querySelector('h1.section-title');
    if (pageTitle) pageTitle.textContent = 'お問い合わせ・掲載削除依頼';
    Array.prototype.slice.call(introBlock.childNodes).forEach(function (node) {
      if (node !== pageTitle) introBlock.removeChild(node);
    });
    var intro = document.createElement('p');
    intro.textContent = 'ご用件に応じて、下記からフォームを選択してください。';
    introBlock.appendChild(intro);
  }
  if (formBlock) {
    var oldHeading = formBlock.querySelector('h2.section-title');
    if (oldHeading) oldHeading.remove();
  }

  var nameLabel = document.querySelector('label[for="contact-name"]');
  var emailLabel = document.querySelector('label[for="contact-email"]');
  if (nameLabel) nameLabel.textContent = '氏名（必須）';
  if (emailLabel) emailLabel.textContent = 'メールアドレス（必須）';

  var token = contactForm.querySelector('input[name="_token"]');
  var tabs = document.createElement('div');
  tabs.className = 'contact-form-tabs';
  tabs.setAttribute('role', 'tablist');
  tabs.innerHTML =
    '<button type="button" class="contact-form-tab is-active" data-form="contact" role="tab" aria-selected="true">一般のお問い合わせ</button>' +
    '<button type="button" class="contact-form-tab" data-form="deletion" role="tab" aria-selected="false"><span>掲載削除依頼</span><small>本人確認書類が必要</small></button>';
  contactForm.parentNode.insertBefore(tabs, contactForm);

  var contactHeading = document.createElement('h2');
  contactHeading.className = 'section-title contact-form-heading';
  contactHeading.textContent = '一般のお問い合わせ';
  contactForm.parentNode.insertBefore(contactHeading, contactForm);

  var contactDescription = document.createElement('p');
  contactDescription.className = 'contact-form-description';
  contactDescription.textContent = 'ご不明な点やご意見・ご要望などをお送りください。';
  contactForm.parentNode.insertBefore(contactDescription, contactForm);

  var deletion = document.createElement('form');
  deletion.method = 'post';
  deletion.action = '<?= e(public_url('deletion_request_submit.php')) ?>';
  deletion.enctype = 'multipart/form-data';
  deletion.className = 'contact-form';
  deletion.style.display = 'none';
  deletion.innerHTML =
    '<input type="hidden" name="_token" value="' + (token ? token.value.replace(/&/g, '&amp;').replace(/"/g, '&quot;') : '') + '">' +
    '<input type="text" name="website" value="" autocomplete="off" tabindex="-1" style="display:none">' +
    '<label>お名前（本名・必須）</label><input name="deletion_name" maxlength="100" placeholder="例：山田 花子" required>' +
    '<label>連絡用メールアドレス（必須）</label><input name="deletion_email" type="email" maxlength="254" placeholder="例：example@example.com" required>' +
    '<label>電話番号（任意）</label><input name="deletion_phone" maxlength="30" placeholder="例：090-1234-5678">' +
    '<label>該当ページURL（必須）</label><textarea name="deletion_urls" rows="5" maxlength="5000" placeholder="複数ある場合は1行ずつ全て記載してください" required></textarea>' +
    '<label>本人確認書類（必須）</label><input name="identity_document" type="file" accept="image/jpeg,image/png,application/pdf" required><small>JPEG・PNG・PDF、5MB以内。受付メールに添付して送信し、当サイトのサーバーには保存しません。</small>' +
    '<label>申請理由（必須）</label><textarea name="deletion_reason" rows="8" maxlength="5000" placeholder="取り消しを希望する理由と経緯をご記入ください" required></textarea>' +
    '<div class="deletion-consent"><input id="deletion-consent" type="checkbox" name="deletion_consent" value="1" required><label for="deletion-consent">プライバシーポリシーを読み、本人確認書類を提出することに同意します（提出書類は本人確認の目的以外には使用しません）。</label></div>' +
    '<button type="submit">掲載削除依頼を送信する</button>';
  contactForm.parentNode.insertBefore(deletion, contactForm.nextSibling);

  var buttons = tabs.querySelectorAll('.contact-form-tab');
  function show(type) {
    var isContact = type === 'contact';
    contactForm.style.display = isContact ? '' : 'none';
    deletion.style.display = isContact ? 'none' : '';
    contactHeading.textContent = isContact ? '一般のお問い合わせ' : '掲載削除依頼';
    contactDescription.textContent = isContact
      ? 'ご不明な点やご意見・ご要望などをお送りください。'
      : '出演者ご本人、正当な代理人または権利者から受け付けます。入力内容と本人確認書類は管理画面・データベースに保存せず、受付用メールにのみ送信します。';
    buttons.forEach(function (button) {
      var active = button.getAttribute('data-form') === type;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  }
  buttons[0].addEventListener('click', function () { show('contact'); });
  buttons[1].addEventListener('click', function () { show('deletion'); });

  var style = document.createElement('style');
  style.textContent =
    '.contact-form-tabs{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 22px}' +
    '.contact-form-tab{min-height:58px;padding:10px 14px;border:2px solid #aeb4bb;border-radius:10px;background:#f7f7f7;color:#333;font-weight:700;cursor:pointer}' +
    '.contact-form-tab small{display:block;margin-top:3px;font-size:11px;font-weight:400;color:#666}' +
    '.contact-form-tab.is-active{background:#555b61;color:#fff;border-color:#555b61;box-shadow:0 3px 10px rgba(0,0,0,.16)}' +
    '.contact-form-tab.is-active small{color:#fff}' +
    '.contact-form-tab:focus-visible{outline:3px solid rgba(85,91,97,.28);outline-offset:2px}' +
    '.contact-form-heading{margin-top:4px}' +
    '.contact-form-description{margin:-4px 0 18px}' +
    '.deletion-consent{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:start;gap:8px;width:100%;max-width:none;margin:16px 0;clear:both}' +
    '.deletion-consent input[type="checkbox"]{width:auto;min-width:0;margin:5px 0 0}' +
    '.deletion-consent label{display:block;width:auto;max-width:none;margin:0;line-height:1.6;white-space:normal;writing-mode:horizontal-tb}' +
    '.contact-form button[type="submit"]{background:#555b61!important;border-color:#555b61!important;color:#fff!important}' +
    '.contact-form button[type="submit"]:hover{background:#44494e!important;border-color:#44494e!important}' +
    '@media(max-width:600px){.contact-form-tabs{grid-template-columns:1fr}.contact-form-tab{width:100%}}';
  document.head.appendChild(style);

  var receipt = params.get('receipt');
  var error = params.get('deletion_error');
  if (receipt || error || params.get('type') === 'deletion') show('deletion');
  if (receipt) {
    var notice = document.createElement('p');
    notice.textContent = '掲載削除依頼を受け付けました。受付番号：' + receipt;
    notice.style.cssText = 'padding:12px;border:1px solid #2d8a47;background:#eefbf1;font-weight:bold';
    deletion.parentNode.insertBefore(notice, deletion);
  } else if (error) {
    var errorNotice = document.createElement('p');
    errorNotice.textContent = error;
    errorNotice.style.cssText = 'padding:12px;border:1px solid #b52b2b;background:#fff0f0';
    deletion.parentNode.insertBefore(errorNotice, deletion);
  }
}());
</script>
<script>
(function () {
  var send = function (url, data) {
    if (navigator.sendBeacon && navigator.sendBeacon(url, data)) return true;
    if (window.fetch) {
      window.fetch(url, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }).catch(function () {});
      return true;
    }
    return false;
  };
  window.__pcfSendBeacon = send;
  if (window.__pcfAnalyticsSent === true) return;
  window.__pcfAnalyticsSent = true;
  var data = new FormData();
  data.append('path', window.location.pathname + window.location.search);
  data.append('referrer', document.referrer || '');
  try {
    var params = new URLSearchParams(window.location.search);
    data.append('ref', params.get('ref') || '');
  } catch (e) {
    data.append('ref', '');
  }
  send('<?= e(public_url('analytics.php')) ?>', data);
}());
</script>
<?php $rankingRefreshQueue = function_exists('pcf_public_ranking_refresh_queue') ? pcf_public_ranking_refresh_queue() : []; ?>
<?php if ($rankingRefreshQueue !== []): ?>
<script>
(function () {
  if (typeof window.__pcfSendBeacon !== 'function') return;
  var endpoint = <?= json_encode(public_url('ranking_refresh.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var queue = <?= json_encode($rankingRefreshQueue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  queue.forEach(function (entry) {
    var data = new FormData();
    data.append('type', entry.type || '');
    data.append('period', entry.period || '');
    window.__pcfSendBeacon(endpoint, data);
  });
}());
</script>
<?php endif; ?>
</body>
</html>

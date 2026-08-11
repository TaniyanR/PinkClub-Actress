(() => {
  'use strict';

  const STORAGE_KEY = 'pcf_recently_viewed_v1';
  const VISIBILITY_KEY = 'pcf_recently_viewed_hidden_v1';
  const MAX_STORED = 20;
  const MAX_RENDERED = 10;

  const storageAvailable = () => {
    try {
      const key = '__pcf_storage_test__';
      localStorage.setItem(key, '1');
      localStorage.removeItem(key);
      return true;
    } catch (_) {
      return false;
    }
  };

  if (!storageAvailable()) return;

  const safeImageUrl = (value) => {
    try {
      const url = new URL(String(value || ''), window.location.origin);
      return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : '';
    } catch (_) {
      return '';
    }
  };

  const itemUrlForId = (id, storedUrl = '') => {
    const numericId = Number.parseInt(String(id || ''), 10);
    if (!Number.isInteger(numericId) || numericId <= 0) return '';

    try {
      const stored = new URL(String(storedUrl || ''), window.location.origin);
      if (stored.pathname.endsWith('/item.php') || stored.pathname.endsWith('item.php')) {
        stored.protocol = window.location.protocol;
        stored.host = window.location.host;
        stored.search = '';
        stored.hash = '';
        stored.searchParams.set('id', String(numericId));
        return stored.href;
      }
    } catch (_) {}

    const sampleLink = document.querySelector('a[href*="item.php?id="]');
    if (sampleLink) {
      try {
        const sample = new URL(sampleLink.href, window.location.origin);
        sample.protocol = window.location.protocol;
        sample.host = window.location.host;
        sample.search = '';
        sample.hash = '';
        sample.searchParams.set('id', String(numericId));
        return sample.href;
      } catch (_) {}
    }

    const path = window.location.pathname;
    const itemPath = path.endsWith('/item.php') || path.endsWith('item.php')
      ? path
      : `${path.replace(/\/[^/]*$/, '').replace(/\/$/, '')}/item.php`;
    const fallback = new URL(itemPath || '/item.php', window.location.origin);
    fallback.searchParams.set('id', String(numericId));
    return fallback.href;
  };

  const normalizeIdList = (value) => {
    if (!Array.isArray(value)) return [];
    const ids = [];
    value.forEach((candidate) => {
      const id = Number.parseInt(String(candidate), 10);
      if (Number.isInteger(id) && id > 0 && !ids.includes(id)) ids.push(id);
    });
    return ids.slice(0, 10);
  };

  const normalizeEntry = (entry) => {
    if (!entry || typeof entry !== 'object') return null;
    const id = Number.parseInt(String(entry.id || ''), 10);
    const title = typeof entry.title === 'string' ? entry.title.trim().slice(0, 300) : '';
    const url = itemUrlForId(id, entry.url);
    if (!Number.isInteger(id) || id <= 0 || title === '' || url === '') return null;

    return {
      version: 1,
      id,
      title,
      image: safeImageUrl(entry.image),
      url,
      viewedAt: Number.isFinite(Number(entry.viewedAt)) ? Number(entry.viewedAt) : 0,
      viewCount: Math.max(1, Math.min(999, Number.parseInt(String(entry.viewCount || 1), 10) || 1)),
      actresses: normalizeIdList(entry.actresses),
      genres: normalizeIdList(entry.genres),
      makers: normalizeIdList(entry.makers),
      series: normalizeIdList(entry.series)
    };
  };

  const readHistory = () => {
    try {
      const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      if (!Array.isArray(parsed)) return [];
      const normalized = [];
      parsed.forEach((entry) => {
        const valid = normalizeEntry(entry);
        if (valid && !normalized.some((item) => item.id === valid.id)) normalized.push(valid);
      });
      return normalized.slice(0, MAX_STORED);
    } catch (_) {
      return [];
    }
  };

  const writeHistory = (history) => {
    try {
      const normalized = [];
      history.forEach((entry) => {
        const valid = normalizeEntry(entry);
        if (valid && !normalized.some((item) => item.id === valid.id)) normalized.push(valid);
      });
      localStorage.setItem(STORAGE_KEY, JSON.stringify(normalized.slice(0, MAX_STORED)));
      return true;
    } catch (_) {
      return false;
    }
  };

  const historyIsHidden = () => localStorage.getItem(VISIBILITY_KEY) === '1';
  const setHistoryHidden = (hidden) => {
    try {
      if (hidden) localStorage.setItem(VISIBILITY_KEY, '1');
      else localStorage.removeItem(VISIBILITY_KEY);
      return true;
    } catch (_) {
      return false;
    }
  };

  const idsFromLinks = (filename) => {
    const ids = [];
    document.querySelectorAll(`a[href*="${filename}?id="]`).forEach((link) => {
      try {
        const url = new URL(link.href, window.location.origin);
        const id = Number.parseInt(url.searchParams.get('id') || '', 10);
        if (Number.isInteger(id) && id > 0 && !ids.includes(id)) ids.push(id);
      } catch (_) {}
    });
    return ids.slice(0, 10);
  };

  const recordCurrentItem = () => {
    const path = window.location.pathname;
    if (!path.endsWith('/item.php') && !path.endsWith('item.php')) return;

    const id = Number.parseInt(new URLSearchParams(window.location.search).get('id') || '', 10);
    if (!Number.isInteger(id) || id <= 0) return;

    const titleMeta = document.querySelector('meta[property="og:title"]');
    const heading = document.querySelector('h1');
    const rawTitle = (titleMeta && titleMeta.content) || (heading && heading.textContent) || document.title;
    const title = String(rawTitle || '').replace(/\s*\|\s*PinkClub.*$/i, '').trim();
    if (!title) return;

    const imageMeta = document.querySelector('meta[property="og:image"]');
    const previous = readHistory();
    const existing = previous.find((entry) => entry.id === id);
    const record = {
      version: 1,
      id,
      title: title.slice(0, 300),
      image: safeImageUrl((imageMeta && imageMeta.content) || ''),
      url: itemUrlForId(id, window.location.href),
      viewedAt: Date.now(),
      viewCount: Math.min(999, Number(existing ? existing.viewCount : 0) + 1),
      actresses: idsFromLinks('actress.php'),
      genres: idsFromLinks('genre.php'),
      makers: idsFromLinks('maker.php'),
      series: idsFromLinks('series_detail.php').concat(idsFromLinks('series_one.php')).slice(0, 10)
    };

    writeHistory([record, ...previous.filter((entry) => entry.id !== id)]);
  };

  const createElement = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  const createNoImage = () => {
    const node = createElement('div', 'pcf-recent__card-image', '画像なし');
    node.style.display = 'grid';
    node.style.placeItems = 'center';
    return node;
  };

  const renderHistory = () => {
    const section = document.getElementById('pcf-recently-viewed');
    const list = document.getElementById('pcf-recent-list');
    const clearButton = document.getElementById('pcf-recent-clear');
    const hideButton = document.getElementById('pcf-recent-hide');
    const restore = document.getElementById('pcf-recent-restore');
    const showButton = document.getElementById('pcf-recent-show');
    if (!section || !list || !clearButton || !hideButton || !restore || !showButton) return;

    hideButton.onclick = () => {
      setHistoryHidden(true);
      renderHistory();
    };
    showButton.onclick = () => {
      setHistoryHidden(false);
      renderHistory();
    };
    clearButton.onclick = () => {
      if (!window.confirm('閲覧履歴をすべて削除しますか？')) return;
      localStorage.removeItem(STORAGE_KEY);
      localStorage.removeItem(VISIBILITY_KEY);
      renderHistory();
    };

    const history = readHistory().slice(0, MAX_RENDERED);
    list.replaceChildren();

    if (history.length === 0) {
      section.hidden = true;
      restore.hidden = true;
      return;
    }

    if (historyIsHidden()) {
      section.hidden = true;
      restore.hidden = false;
      return;
    }

    restore.hidden = true;

    history.forEach((entry) => {
      const article = createElement('article', 'pcf-recent__card');
      const imageLink = createElement('a');
      imageLink.href = entry.url;
      imageLink.setAttribute('aria-label', entry.title);

      if (entry.image) {
        const image = createElement('img', 'pcf-recent__card-image');
        image.src = entry.image;
        image.alt = entry.title;
        image.loading = 'lazy';
        image.decoding = 'async';
        image.addEventListener('error', () => image.replaceWith(createNoImage()), { once: true });
        imageLink.appendChild(image);
      } else {
        imageLink.appendChild(createNoImage());
      }

      const titleLink = createElement('a', 'pcf-recent__card-title', entry.title);
      titleLink.href = entry.url;
      const actions = createElement('div', 'pcf-recent__card-actions');
      const openLink = createElement('a', 'pcf-recent__open', 'もう一度見る');
      openLink.href = entry.url;
      const removeButton = createElement('button', 'pcf-recent__remove', '削除');
      removeButton.type = 'button';
      removeButton.dataset.recentRemoveId = String(entry.id);
      removeButton.setAttribute('aria-label', `${entry.title}を履歴から削除`);
      actions.append(openLink, removeButton);
      article.append(imageLink, titleLink, actions);
      list.appendChild(article);
    });

    section.hidden = false;

    list.querySelectorAll('[data-recent-remove-id]').forEach((button) => {
      button.addEventListener('click', () => {
        const id = Number.parseInt(button.dataset.recentRemoveId || '', 10);
        writeHistory(readHistory().filter((entry) => entry.id !== id));
        renderHistory();
      });
    });
  };

  recordCurrentItem();
  renderHistory();
  window.addEventListener('pageshow', renderHistory);
  window.addEventListener('storage', (event) => {
    if (event.key === STORAGE_KEY || event.key === VISIBILITY_KEY) renderHistory();
  });
})();

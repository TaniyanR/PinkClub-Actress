(() => {
  'use strict';

  const HISTORY_KEY = 'pcf_recently_viewed_v1';
  const HIDDEN_KEY = 'pcf_recommendations_hidden_v1';
  const MIN_HISTORY = 2;
  const MAX_IDS = 10;

  const storageAvailable = () => {
    try {
      const key = '__pcf_recommendations_test__';
      localStorage.setItem(key, '1');
      localStorage.removeItem(key);
      return true;
    } catch (_) {
      return false;
    }
  };

  if (!storageAvailable()) return;

  const readHistory = () => {
    try {
      const value = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
      if (!Array.isArray(value)) return [];
      return value.filter((entry) => entry && Number.isInteger(Number(entry.id)) && Number(entry.id) > 0).slice(0, 20);
    } catch (_) {
      return [];
    }
  };

  const rankedIds = (history, key) => {
    const scores = new Map();
    history.forEach((entry, index) => {
      const ids = Array.isArray(entry[key]) ? entry[key] : [];
      const recency = Math.max(1, history.length - index);
      const views = Math.max(1, Math.min(20, Number(entry.viewCount || 1)));
      ids.forEach((rawId) => {
        const id = Number.parseInt(String(rawId), 10);
        if (!Number.isInteger(id) || id <= 0) return;
        scores.set(id, (scores.get(id) || 0) + recency + views);
      });
    });
    return [...scores.entries()]
      .sort((a, b) => b[1] - a[1] || a[0] - b[0])
      .slice(0, MAX_IDS)
      .map(([id]) => id);
  };

  const createElement = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  const safeUrl = (value, sameOrigin = false) => {
    try {
      const url = new URL(String(value || ''), window.location.origin);
      if (url.protocol !== 'http:' && url.protocol !== 'https:') return '';
      if (sameOrigin && url.origin !== window.location.origin) return '';
      return url.href;
    } catch (_) {
      return '';
    }
  };

  const createNoImage = () => {
    const node = createElement('div', 'pcf-recommendations__image', '画像なし');
    node.style.display = 'grid';
    node.style.placeItems = 'center';
    return node;
  };

  const moveIntoPosition = (element) => {
    const recent = document.getElementById('pcf-recently-viewed');
    const recentRestore = document.getElementById('pcf-recent-restore');
    const anchor = recent && !recent.hidden ? recent : (recentRestore && !recentRestore.hidden ? recentRestore : null);
    if (anchor) {
      anchor.after(element);
      return;
    }

    const body = element.closest('.site-main__body');
    if (!body) return;
    const latest = Array.from(body.querySelectorAll('.rail-section')).filter((section) => {
      const heading = section.querySelector('h2');
      return heading && heading.textContent.trim() === '新着作品';
    });
    const target = latest.length ? latest[latest.length - 1] : null;
    if (target) target.after(element);
  };

  let requestController = null;

  const render = async () => {
    const section = document.getElementById('pcf-recommendations');
    const list = document.getElementById('pcf-recommendations-list');
    const hideButton = document.getElementById('pcf-recommendations-hide');
    const restore = document.getElementById('pcf-recommendations-restore');
    const showButton = document.getElementById('pcf-recommendations-show');
    if (!section || !list || !hideButton || !restore || !showButton) return;

    hideButton.onclick = () => {
      localStorage.setItem(HIDDEN_KEY, '1');
      render();
    };
    showButton.onclick = () => {
      localStorage.removeItem(HIDDEN_KEY);
      render();
    };

    const history = readHistory();
    if (history.length < MIN_HISTORY) {
      section.hidden = true;
      restore.hidden = true;
      list.replaceChildren();
      return;
    }

    if (localStorage.getItem(HIDDEN_KEY) === '1') {
      section.hidden = true;
      moveIntoPosition(restore);
      restore.hidden = false;
      return;
    }

    restore.hidden = true;
    const endpoint = safeUrl(section.dataset.endpoint, true);
    if (!endpoint) {
      section.hidden = true;
      return;
    }

    const params = new URLSearchParams();
    const groups = {
      actresses: rankedIds(history, 'actresses'),
      genres: rankedIds(history, 'genres'),
      makers: rankedIds(history, 'makers'),
      series: rankedIds(history, 'series'),
      viewed: history.map((entry) => Number(entry.id)).filter((id) => Number.isInteger(id) && id > 0).slice(0, 20)
    };

    if (!groups.actresses.length && !groups.genres.length && !groups.makers.length && !groups.series.length) {
      section.hidden = true;
      list.replaceChildren();
      return;
    }

    Object.entries(groups).forEach(([key, ids]) => {
      if (ids.length) params.set(key, ids.join(','));
    });

    if (requestController) requestController.abort();
    requestController = new AbortController();

    try {
      const response = await fetch(`${endpoint}?${params.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal: requestController.signal
      });
      if (!response.ok) throw new Error('recommendations request failed');
      const payload = await response.json();
      const items = Array.isArray(payload.items) ? payload.items : [];
      list.replaceChildren();

      items.slice(0, 10).forEach((item) => {
        const pageUrl = safeUrl(item.url, true);
        const title = typeof item.title === 'string' ? item.title.trim() : '';
        if (!pageUrl || !title) return;

        const card = createElement('article', 'pcf-recommendations__card');
        const imageLink = createElement('a');
        imageLink.href = pageUrl;
        imageLink.setAttribute('aria-label', title);

        const imageUrl = safeUrl(item.image);
        if (imageUrl) {
          const image = createElement('img', 'pcf-recommendations__image');
          image.src = imageUrl;
          image.alt = title;
          image.loading = 'lazy';
          image.decoding = 'async';
          image.addEventListener('error', () => image.replaceWith(createNoImage()), { once: true });
          imageLink.appendChild(image);
        } else {
          imageLink.appendChild(createNoImage());
        }

        const titleLink = createElement('a', 'pcf-recommendations__title', title);
        titleLink.href = pageUrl;
        const reason = createElement('p', 'pcf-recommendations__reason', String(item.reason || '閲覧傾向に近い作品'));
        card.append(imageLink, titleLink, reason);
        list.appendChild(card);
      });

      if (!list.children.length) {
        section.hidden = true;
        return;
      }

      moveIntoPosition(section);
      section.hidden = false;
    } catch (error) {
      if (error && error.name === 'AbortError') return;
      section.hidden = true;
      list.replaceChildren();
    }
  };

  render();
  window.addEventListener('pageshow', render);
  window.addEventListener('storage', (event) => {
    if (event.key === HISTORY_KEY || event.key === HIDDEN_KEY) render();
  });
})();

/**
 * @file
 * Reports clicks on Meilisearch search results.
 */
((Drupal, once) => {
  const COOKIE = 'quebracho_meilisearch_uid';

  /**
   * Makes sure the anonymous visitor has a random ID cookie.
   */
  function ensureVisitorId() {
    if (document.cookie.split('; ').some((c) => c.startsWith(`${COOKIE}=`))) {
      return;
    }
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    const hex = (b) => b.toString(16).padStart(2, '0');
    const id = Array.from(bytes, hex).join('');
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${COOKIE}=${id}; path=/; max-age=31536000; SameSite=Lax${secure}`;
  }

  /**
   * Sends one click, surviving the navigation it triggers.
   */
  function send(row) {
    const body = JSON.stringify({
      index: row.dataset.meilisearchIndex,
      queryUid: row.dataset.meilisearchQueryUid,
      objectId: row.dataset.meilisearchObjectId,
      position: parseInt(row.dataset.meilisearchPosition, 10),
    });
    const url = Drupal.url('meilisearch/analytics/click');
    const blob = new Blob([body], { type: 'application/json' });
    if (!(navigator.sendBeacon && navigator.sendBeacon(url, blob))) {
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
        keepalive: true,
        credentials: 'same-origin',
      });
    }
  }

  Drupal.behaviors.meilisearchClickTracking = {
    attach(context) {
      once(
        'meilisearch-click',
        '[data-meilisearch-object-id]',
        context,
      ).forEach((row) => {
        ensureVisitorId();
        const onClick = (event) => {
          // Left and middle clicks on a link inside the result.
          if (event.button > 1 || !event.target.closest('a')) {
            return;
          }
          send(row);
        };
        row.addEventListener('click', onClick);
        row.addEventListener('auxclick', onClick);
      });
    },
  };
})(Drupal, once);

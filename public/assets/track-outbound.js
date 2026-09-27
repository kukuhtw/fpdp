// Reports clicks on links to other sites for the owner's Analytics page.
// Uses navigator.sendBeacon, so it never delays or blocks the navigation;
// only the link's URL is sent (the server adds a daily-rotating visitor
// hash, never the IP).
(() => {
  if (!navigator.sendBeacon) return;
  document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link) return;
    let url;
    try {
      url = new URL(link.href, location.href);
    } catch (_) {
      return;
    }
    if (!/^https?:$/.test(url.protocol) || url.host === location.host) return;
    const body = new Blob([JSON.stringify({ target_url: url.href })], { type: 'application/json' });
    navigator.sendBeacon('/api/v1/track/outbound-click', body);
  }, { capture: true });
})();

/**
 * "3 minutes ago" from each sale's time, in the page's language: the page
 * is cached until the next sale, so the server cannot say it.
 */
(function (Drupal, once) {
  const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
  Drupal.behaviors.ombuSales = {
    attach(context) {
      const format = new Intl.RelativeTimeFormat(document.documentElement.lang || 'en', { numeric: 'auto' });
      once('ombu-sales', '.ombu-sales time[datetime]', context).forEach((time) => {
        const seconds = (Date.parse(time.getAttribute('datetime')) - Date.now()) / 1000;
        const [unit, size] = units.find(([, s]) => Math.abs(seconds) >= s) || ['second', 1];
        time.title = time.textContent;
        time.textContent = format.format(Math.round(seconds / size), unit);
      });
    },
  };
})(Drupal, once);

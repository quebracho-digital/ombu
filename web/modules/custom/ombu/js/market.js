/**
 * @file
 * Phones: the filters fold under one "Filters" toggle, so listings come
 * first. Desktop: always open, no toggle (CSS hides it).
 */
(function (Drupal, once) {
  // Kept across AJAX refreshes, which rebuild the filters.
  let open = false;

  Drupal.behaviors.ombuMarketFilters = {
    attach(context) {
      once('ombu-filters', '.view-market > .view-filters', context).forEach((filters) => {
        const details = document.createElement('details');
        details.className = 'ombu-filters';
        details.open = open || window.matchMedia('(min-width: 992px)').matches;
        const summary = document.createElement('summary');
        const active = filters.querySelectorAll('input[type=checkbox]:checked').length;
        summary.textContent = active ? Drupal.t('Filters (@count)', { '@count': active }) : Drupal.t('Filters');
        details.append(summary, ...filters.childNodes);
        filters.append(details);
        details.addEventListener('toggle', () => { open = details.open; });
      });
    },
  };
})(Drupal, once);

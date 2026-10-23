/**
 * @file
 * Reports the similar services a person saw and clicked (SimilarLog).
 *
 * "shown" once the block is half on screen, "click" with the card's place.
 * A beacon: the page never waits on it.
 */
(function (Drupal, once) {
  const send = (report) => {
    const url = Drupal.url('similar/event');
    const body = JSON.stringify(report);
    if (!navigator.sendBeacon || !navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }))) {
      fetch(url, { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true }).catch(() => {});
    }
  };

  Drupal.behaviors.ombuSimilarLog = {
    attach(context) {
      once('ombu-similar-log', '[data-ombu-similar]', context).forEach((block) => {
        const listing = Number(block.dataset.ombuSimilar);
        const engine = block.dataset.ombuEngine || '';
        const cards = () => Array.from(block.querySelectorAll('[data-listing]'));
        if (!cards().length) {
          return;
        }
        const observer = new IntersectionObserver((entries) => {
          if (entries.some((entry) => entry.isIntersecting)) {
            observer.disconnect();
            send({ kind: 'shown', listing, engine, items: cards().map((card) => Number(card.dataset.listing)) });
          }
        }, { threshold: 0.5 });
        observer.observe(block);
        block.addEventListener('click', (event) => {
          const card = event.target.closest('[data-listing]');
          if (card && event.target.closest('a')) {
            send({ kind: 'click', listing, engine, target: Number(card.dataset.listing), position: cards().indexOf(card) });
          }
        });
      });
    },
  };
})(Drupal, once);

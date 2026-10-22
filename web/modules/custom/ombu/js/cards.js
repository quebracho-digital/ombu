/**
 * @file
 * A slider of cards (Cards.php): the buttons move it a view at a time, and
 * turn off at either end. Scrolling, touch and keys work without them.
 */
(function (Drupal, once) {
  Drupal.behaviors.ombuCardsSlider = {
    attach(context) {
      once('ombu-cards-slider', '.ombu-cards-block--slider', context).forEach((block) => {
        const list = block.querySelector('.ombu-cards--slider');
        const prev = block.querySelector('.ombu-cards__prev');
        const next = block.querySelector('.ombu-cards__next');
        if (!list || !prev || !next) {
          return;
        }
        const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const step = (direction) => list.scrollBy({ left: direction * list.clientWidth, behavior: smooth ? 'smooth' : 'auto' });
        const update = () => {
          prev.disabled = list.scrollLeft <= 1;
          next.disabled = list.scrollLeft + list.clientWidth >= list.scrollWidth - 1;
        };
        prev.addEventListener('click', () => step(-1));
        next.addEventListener('click', () => step(1));
        list.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
      });
    },
  };
})(Drupal, once);

/**
 * @file
 * The share block's copy button: puts the address on the clipboard and says
 * so to a screen reader. Without clipboard access the button does nothing
 * visible and the links still work.
 */
(function (Drupal, once) {
  Drupal.behaviors.ombuShareCopy = {
    attach(context) {
      once('ombu-share-copy', '.ombu-share__copy', context).forEach((button) => {
        if (!navigator.clipboard) {
          button.hidden = true;
          return;
        }
        button.addEventListener('click', () => {
          const status = button.closest('.ombu-share').querySelector('.ombu-share__status');
          navigator.clipboard.writeText(button.dataset.ombuShareUrl).then(
            () => { status.textContent = Drupal.t('Link copied'); },
            () => { status.textContent = Drupal.t('The link could not be copied'); },
          );
        });
      });
    },
  };
})(Drupal, once);

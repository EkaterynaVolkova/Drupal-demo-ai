/**
 * @file
 * Project JavaScript behaviors.
 */
((Drupal, once) => {
  Drupal.behaviors.oliveroChild = {
    attach(context) {
      once('olivero-child', 'body', context).forEach(() => {
        // Your code here.
      });
    },
  };
})(Drupal, once);

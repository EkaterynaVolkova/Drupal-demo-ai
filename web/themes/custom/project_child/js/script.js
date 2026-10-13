/**
 * @file
 * Project JavaScript behaviors.
 */
((Drupal, once) => {
  Drupal.behaviors.projectChild = {
    attach(context) {
      once('project-child', 'body', context).forEach(() => {
        // Your code here.
      });
    },
  };
})(Drupal, once);

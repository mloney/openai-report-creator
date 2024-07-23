(function ($, Drupal) {
  Drupal.behaviors.customWebformHandler = {
    attach: function (context, settings) {
      $('#edit-submit', context).once('customWebformHandler').each(function () {
        $(this).on('click', function (e) {
          e.preventDefault();
          var $button = $(this);

          // Disable the button
          $button.prop('disabled', true);

          // Show a loading message
          var $loadingMessage = $('<div class="loading-message">Generating report, please wait...</div>');
          $button.after($loadingMessage);

          // Submit the form
          $button.closest('form').submit();
        });
      });
    }
  };
})(jQuery, Drupal);

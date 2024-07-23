<?php

/**
 * Batch processing callback.
 */
function custom_webform_handler_batch_process($values, $user_id, &$context) {
  if (empty($context['sandbox'])) {
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['max'] = 1;
  }

  $context['sandbox']['progress']++;

  // Generate the report.
  $reportController = \Drupal::service('openai_report_controller');
  $report = $reportController->generateReport($values);

  // Get the current user.
  $user = \Drupal\user\Entity\User::load($user_id);
  $user_email = $user->getEmail();

  // Enqueue the email task.
  $queue = \Drupal::queue('openai_report_queue');
  $queue->createItem([
    'email' => $user_email,
    'message' => $report,
  ]);

  $context['results']['email'] = $user_email;

  // Check if we are done.
  if ($context['sandbox']['progress'] >= $context['sandbox']['max']) {
    $context['finished'] = 1;
  } else {
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }
}

/**
 * Batch finished callback.
 */
function custom_webform_handler_batch_finished($success, $results, $operations) {
  if ($success) {
    \Drupal::messenger()->addMessage(t('Report generated and will be emailed to @user.', ['@user' => $results['email']]));
  } else {
    \Drupal::messenger()->addMessage(t('Finished with an error.'));
  }
}

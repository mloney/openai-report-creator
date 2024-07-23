<?php

namespace Drupal\custom_webform_handler\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes OpenAI report queue.
 *
 * @QueueWorker(
 *   id = "openai_report_queue",
 *   title = @Translation("OpenAI Report Queue"),
 *   cron = {"time" = 60}
 * )
 */
class OpenAIReportQueueWorker extends QueueWorkerBase {

  /**
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Constructs a new OpenAIReportQueueWorker object.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(MailManagerInterface $mail_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->mailManager = $mail_manager;
    $this->logger = $logger_factory->get('custom_webform_handler');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Log to ensure the item is being processed.
    $this->logger->notice('Processing item for User ID: @uid', ['@uid' => $data['user_id']]);

    // Generate the report.
    try {
      $reportController = \Drupal::service('openai_report_controller');
      $report = $reportController->generateReport($data['values']);
    } catch (\Exception $e) {
      $this->logger->error('Error generating report: @message', ['@message' => $e->getMessage()]);
      return;
    }

    // Log the generated report.
    $this->logger->notice('Generated report: @report', ['@report' => $report]);

    // Get the current user.
    $user = \Drupal\user\Entity\User::load($data['user_id']);
    if (!$user) {
      $this->logger->error('User ID @uid not found.', ['@uid' => $data['user_id']]);
      return;
    }
    $user_email = $user->getEmail();

    // Log the email address.
    $this->logger->notice('Sending email to: @to', ['@to' => $user_email]);

    // Prepare email.
    $module = 'custom_webform_handler';
    $key = 'report_generated';
    $to = $user_email;
    $params['message'] = $report;
    $params['subject'] = 'Your Generated Report';
    $langcode = $user->getPreferredLangcode();
    $send = true;

    // Send email.
    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    if ($result['result'] !== true) {
      $this->logger->error('Failed to send report email to @to.', ['@to' => $to]);
    } else {
      $this->logger->info('Report email sent to @to.', ['@to' => $to]);
    }
  }

}

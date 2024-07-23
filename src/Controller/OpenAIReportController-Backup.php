<?php

namespace Drupal\custom_webform_handler\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;


class OpenAIReportController extends ControllerBase {

  protected $mailManager;
  protected $logger;
  protected $httpClient;

  public function __construct(MailManagerInterface $mail_manager, LoggerChannelFactoryInterface $logger_factory, Client $http_client) {
    $this->mailManager = $mail_manager;
    $this->logger = $logger_factory->get('custom_webform_handler');
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('logger.factory'),
      $container->get('http_client')
    );
  }

  public function generateReport($values, $api_key, $assistant_id, $prompt, $view_name, $view_display_name) {
    $current_user = $this->currentUser();
    $user_email = $current_user->getEmail();

    // Log the email address to verify
    $this->logger->notice('User email: ' . $user_email);

    // Create the prompt with the provided data.
    $prompt .= "\nView Name: " . $view_name . "\nView Display Name: " . $view_display_name . "\n\n";
    foreach ($values as $key => $value) {
      $prompt .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
    }

    // Log the prompt for debugging
    $this->logger->notice('Prompt: ' . $prompt);

    // Log the view name and display name
    $this->logger->info('View Name: ' . $view_name);
    $this->logger->info('View Display Name: ' . $view_display_name);

    

    try {
      // Step 1: Create a thread
      $thread = $this->createThread($api_key);
      if ($thread === false) {
        $this->logger->error('Failed to create thread.');
        return 'Failed to create thread.';
      }
      $this->logger->notice('Thread created: ' . json_encode($thread));

      // Step 2: Add message to the thread
      $messageAdded = $this->addMessageToThread($thread['id'], $prompt, $api_key);
      if ($messageAdded === false) {
        $this->logger->error('Failed to add message to thread.');
        return 'Failed to add message to thread.';
      }
      $this->logger->notice('Message added to thread: ' . json_encode($messageAdded));

      // Step 3: Run the assistant on the thread
      $runResponse = $this->runThreadWithAssistant($thread['id'], $api_key, $assistant_id);
      if ($runResponse === false) {
        $this->logger->error('Failed to run assistant.');
        return 'Failed to run assistant.';
      }

      // Log the full response for debugging
      $this->logger->notice('Full assistant response: ' . json_encode($runResponse));

      // Step 4: Poll run status until completion
      $pollResponse = $this->pollRunStatus($thread['id'], $runResponse['id'], $api_key);
      if ($pollResponse === false) {
        $this->logger->error('Failed to poll run status.');
        return 'Failed to poll run status. This may be due to network issues or server unavailability. Please try again later.';
      }

      // Log the poll response for debugging
      $this->logger->notice('Poll response: ' . json_encode($pollResponse));

      // Step 5: List messages in the thread to get the assistant's response
      $messagesResponse = $this->listMessagesInThread($thread['id'], $api_key);
      if ($messagesResponse === false) {
        $this->logger->error('Failed to list messages in thread.');
        return 'Failed to list messages in thread. Please check your network connection and try again.';
      }

      // Log the messages for debugging
      $this->logger->notice('Messages in thread: ' . json_encode($messagesResponse));

      // Step 6: Extract and verify the assistant's message
      $responseMessage = $this->extractMessageFromMessagesResponse($messagesResponse);
      if ($responseMessage !== false) {
        $this->logger->notice('OpenAI Response: ' . $responseMessage);
        return $responseMessage;
      } else {
        return 'No reply received from the GPT model. Please try again later.';
      }
    } catch (ConnectException $e) {
      $this->logger->error('Connection to OpenAI API failed: @message', ['@message' => $e->getMessage()]);
      return 'Connection to OpenAI API failed. Please try again later.';
    } catch (RequestException $e) {
      // Log the full response and error details for debugging
      $this->logger->error('OpenAI API call failed: @message', ['@message' => $e->getMessage()]);
      if ($e->hasResponse()) {
        $response_body = $e->getResponse()->getBody()->getContents();
        $this->logger->error('OpenAI API response: @response', ['@response' => $response_body]);
        return 'Failed to get response from OpenAI. Details: ' . $response_body;
      }
      return 'Failed to get response from OpenAI.';
    } catch (\Exception $e) {
      $this->logger->error('An error occurred: @message', ['@message' => $e->getMessage()]);
      return 'An error occurred. Please try again later.';
    }
  }

  protected function createThread($api_key) {
    $client = new Client();
    try {
      $response = $client->post('https://api.openai.com/v1/threads', [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
          'OpenAI-Beta' => 'assistants=v2',
        ],
        'json' => []
      ]);
      return json_decode($response->getBody(), true);
    } catch (\Exception $e) {
      $this->logger->error('Error creating thread: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  protected function addMessageToThread($thread_id, $content, $api_key) {
    $client = new Client();
    try {
      $this->logger->notice('Payload sent to OpenAI: ' . json_encode(['role' => 'user', 'content' => $content]));

      $response = $client->post("https://api.openai.com/v1/threads/{$thread_id}/messages", [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
          'OpenAI-Beta' => 'assistants=v2',
        ],
        'json' => [
          'role' => 'user',
          'content' => $content,
        ],
      ]);
      return json_decode($response->getBody(), true);
    } catch (\Exception $e) {
      $this->logger->error('Error adding message to thread: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  protected function runThreadWithAssistant($thread_id, $api_key, $assistant_id) {
    $client = new Client();
    try {
      $response = $client->post("https://api.openai.com/v1/threads/{$thread_id}/runs", [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
          'OpenAI-Beta' => 'assistants=v2',
        ],
        'json' => [
          'assistant_id' => $assistant_id,
        ],
      ]);
      return json_decode($response->getBody(), true);
    } catch (\Exception $e) {
      $this->logger->error('Error running assistant: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  protected function pollRunStatus($thread_id, $run_id, $api_key) {
    $client = new Client();
    $maxAttempts = 10;
    $attempt = 0;
    $sleepTime = 2; // Time to wait between attempts (in seconds)

    while ($attempt < $maxAttempts) {
      try {
        $response = $client->get("https://api.openai.com/v1/threads/{$thread_id}/runs/{$run_id}", [
          'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
            'OpenAI-Beta' => 'assistants=v2',
          ],
        ]);
        $responseData = json_decode($response->getBody(), true);

        // Check if the run is complete
        if (isset($responseData['status']) && $responseData['status'] === 'completed') {
          return $responseData;
        }

        // If not completed, wait and retry
        sleep($sleepTime);
        $attempt++;
      } catch (\Exception $e) {
        $this->logger->error('Error polling run status: @error', ['@error' => $e->getMessage()]);
        return false;
      }
    }

    $this->logger->error('Max attempts reached while polling run status.');
    return false;
  }

  protected function listMessagesInThread($thread_id, $api_key) {
    $client = new Client();
    try {
      $response = $client->get("https://api.openai.com/v1/threads/{$thread_id}/messages", [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
          'OpenAI-Beta' => 'assistants=v2',
        ],
      ]);
      return json_decode($response->getBody(), true);
    } catch (\Exception $e) {
      $this->logger->error('Error listing messages in thread: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  protected function extractMessageFromMessagesResponse($response) {
    // Log the entire response to inspect its structure
    $this->logger->notice('Extracting message from response: ' . json_encode($response));

    if (isset($response['data'])) {
      foreach ($response['data'] as $message) {
        if ($message['role'] === 'assistant' && isset($message['content'])) {
          // Check if content is an array and concatenate the text parts
          $content = '';
          foreach ($message['content'] as $contentItem) {
            if (is_array($contentItem) && isset($contentItem['text'])) {
              $content .= $contentItem['text']['value'];
            } elseif (is_string($contentItem)) {
              $content .= $contentItem;
            }
          }
          return $content;
        }
      }
    }
    $this->logger->error('No content found in the assistant response. Full response: ' . json_encode($response));
    return false;
  }

  public function sendEmail($response, $user_email, $email_subject) {
    $module = 'custom_webform_handler';
    $key = 'report_generated';
    $to = $user_email;
    $params['message'] = $response;
    $params['subject'] = $email_subject;
    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $send = true;

    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    if ($result['result'] !== true) {
      $this->logger->error('Failed to send report email to @to.', ['@to' => $to]);
    }
  }
}

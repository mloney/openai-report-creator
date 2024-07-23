<?php

namespace Drupal\custom_webform_handler\Service;

use Drupal\views\Views;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Renderer;
use \DOMDocument;

class ViewDataService {
  protected $logger;
  protected $renderer;

  public function __construct(LoggerChannelFactoryInterface $logger_factory, Renderer $renderer) {
    $this->logger = $logger_factory->get('custom_webform_handler');
    $this->renderer = $renderer;
  }

  public function getViewData($view_name, $display_id) {
    $this->logger->notice('Starting getViewData with view_name: ' . $view_name . ' and display_id: ' . $display_id);

    $view = Views::getView($view_name);
    if (is_object($view)) {
      $this->logger->notice('View object found for view_name: ' . $view_name);

      $view->setDisplay($display_id);
      $view->preExecute();
      $view->execute();

      // Render the view to ensure all preprocessing is done
      $render_array = $view->render();
      $html_output = $this->renderer->renderPlain($render_array);

      // Minify HTML output
      $html_output = $this->minifyHtml($html_output);

      $this->logger->info('View HTML extracted and minified: @data', ['@data' => substr($html_output, 0, 1000)]); // Logging a substring of the HTML for brevity
      return (string) $html_output;
    }

    $this->logger->error('No view object found for view_name: ' . $view_name);
    return '';
  }

  private function minifyHtml($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $minified_html = $dom->saveHTML();
    $minified_html = preg_replace('/\s+/', ' ', $minified_html);
    return $minified_html;
  }
}

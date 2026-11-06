<?php

declare(strict_types=1);

namespace Drupal\market_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\market_feed\Service\MarketFeedImporterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller endpoint for receiving external market price feeds.
 */
class MarketFeedImportController extends ControllerBase {

  public function __construct(
    protected MarketFeedImporterInterface $importer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('market_feed.importer'),
    );
  }

  /**
   * Handles incoming JSON stock price ingestion.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Structured import summary response.
   */
  public function handle(Request $request): JsonResponse {
    $rawContent = $request->getContent();
    if (empty($rawContent)) {
      return new JsonResponse([
        'error' => 'Empty request body.',
      ], Response::HTTP_BAD_REQUEST);
    }

    try {
      $payload = json_decode($rawContent, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      return new JsonResponse([
        'error' => 'Invalid JSON syntax: ' . $e->getMessage(),
      ], Response::HTTP_BAD_REQUEST);
    }

    if (!is_array($payload) || !array_is_list($payload)) {
      return new JsonResponse([
        'error' => 'Payload must be a JSON array of price objects.',
      ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // Delegate batching, validation, and database operations to the service.
    $summary = $this->importer->import($payload);

    return new JsonResponse($summary, Response::HTTP_OK);
  }

}

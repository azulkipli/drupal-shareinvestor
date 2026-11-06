<?php

declare(strict_types=1);

namespace Drupal\market_feed\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * High-performance batch importer for market feed stock prices.
 */
class MarketFeedImporter implements MarketFeedImporterInterface {

  /**
   * Number of rows to process per database upsert query and transaction.
   */
  public const CHUNK_SIZE = 1000;

  /**
   * Cache tag invalidated upon successful price updates.
   */
  public const CACHE_TAG = 'market_feed_prices:list';

  public function __construct(
    protected Connection $connection,
    protected CacheTagsInvalidatorInterface $cacheInvalidator,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function import(array $payload): array {
    $validRows = [];
    $errors = [];
    $requestTime = $this->time->getRequestTime();

    // Step 1: In-memory schema validation (fault isolation).
    foreach ($payload as $index => $row) {
      $errorMessage = $this->validateRow($row);
      if ($errorMessage !== NULL) {
        $errors[] = [
          'row' => $index,
          'symbol' => is_array($row) && isset($row['symbol']) ? (string) $row['symbol'] : 'UNKNOWN',
          'error' => $errorMessage,
        ];
        continue;
      }

      $symbol = strtoupper(trim((string) $row['symbol']));

      // In a single snapshot, if the same symbol appears multiple times
      // (as in the exam sample with AAPL), later entries represent newer updates.
      // We retain the latest entry per symbol to ensure query efficiency and avoid
      // MySQL multi-row duplicate key warnings within the same statement.
      $validRows[$symbol] = [
        'symbol' => $symbol,
        'price' => (float) $row['price'],
        'change_amount' => (float) $row['change'],
        'volume' => (int) $row['volume'],
        'updated_at' => $requestTime,
      ];
    }

    $importedCount = 0;

    // Step 2: Chunked batch upsert with transaction boundary.
    if (!empty($validRows)) {
      $chunks = array_chunk(array_values($validRows), self::CHUNK_SIZE);

      foreach ($chunks as $chunk) {
        $transaction = $this->connection->startTransaction();
        try {
          $query = $this->connection->upsert('market_feed_prices')
            ->key('symbol')
            ->fields(['symbol', 'price', 'change_amount', 'volume', 'updated_at']);

          foreach ($chunk as $item) {
            $query->values($item);
          }

          $query->execute();
          $importedCount += count($chunk);
        }
        catch (\Throwable $e) {
          $transaction->rollBack();
          $this->logger->error('Market feed batch upsert failed: @message', [
            '@message' => $e->getMessage(),
          ]);

          $errors[] = [
            'row' => 'batch',
            'symbol' => 'CHUNK_ERROR',
            'error' => 'Database transaction failed: ' . $e->getMessage(),
          ];
        }
      }
    }

    // Step 3: Event-driven cache invalidation.
    if ($importedCount > 0) {
      $this->cacheInvalidator->invalidateTags([self::CACHE_TAG]);
    }

    return [
      'imported' => $importedCount,
      'skipped' => count($errors),
      'errors' => $errors,
    ];
  }

  /**
   * Validates structure and data types for a single payload row.
   *
   * @param mixed $row
   *   The row data.
   *
   * @return string|null
   *   Error message if invalid, or NULL if valid.
   */
  protected function validateRow(mixed $row): ?string {
    if (!is_array($row)) {
      return 'Each item must be an associative array / JSON object.';
    }

    if (!isset($row['symbol']) || !is_string($row['symbol']) || trim($row['symbol']) === '') {
      return 'Field "symbol" is required and must be a non-empty string.';
    }

    if (strlen(trim($row['symbol'])) > 20) {
      return 'Field "symbol" must not exceed 20 characters.';
    }

    if (!isset($row['price']) || !is_numeric($row['price']) || (float) $row['price'] < 0) {
      return 'Field "price" is required and must be a non-negative numeric value.';
    }

    if (!isset($row['change']) || !is_numeric($row['change'])) {
      return 'Field "change" is required and must be a numeric value.';
    }

    if (!isset($row['volume']) || !is_numeric($row['volume']) || (float) $row['volume'] < 0) {
      return 'Field "volume" is required and must be a non-negative integer.';
    }

    return NULL;
  }

}

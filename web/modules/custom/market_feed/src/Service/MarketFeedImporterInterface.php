<?php

declare(strict_types=1);

namespace Drupal\market_feed\Service;

/**
 * Interface for the market feed batch price importer.
 */
interface MarketFeedImporterInterface {

  /**
   * Import stock price rows using chunked upsert transactions.
   *
   * @param array $payload
   *   Raw array of price items from the JSON body.
   * @param callable|null $progressCallback
   *   Optional callback triggered per chunk: fn(int $chunkCount, int $totalProcessed, int $totalChunks).
   *
   * @return array
   *   Summary associative array:
   *   - imported: int (number of rows successfully upserted)
   *   - skipped: int (number of malformed rows skipped)
   *   - errors: array of error details with row index, symbol, and reason
   */
  public function import(array $payload, ?callable $progressCallback = NULL): array;

}

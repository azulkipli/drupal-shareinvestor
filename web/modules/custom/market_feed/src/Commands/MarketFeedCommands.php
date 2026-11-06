<?php

declare(strict_types=1);

namespace Drupal\market_feed\Commands;

use Drupal\market_feed\Service\MarketFeedImporterInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the market_feed module.
 */
final class MarketFeedCommands extends DrushCommands {

  public function __construct(
    protected MarketFeedImporterInterface $importer,
  ) {
    parent::__construct();
  }

  /**
   * Import stock prices from a JSON file into the database.
   */
  #[CLI\Command(name: 'market-feed:import', aliases: ['mf:import'])]
  #[CLI\Argument(name: 'file', description: 'Path to JSON file containing stock prices.')]
  #[CLI\Usage(name: 'drush market-feed:import /path/to/prices.json', description: 'Imports stock prices from a JSON file.')]
  public function import(string $file): int {
    if (!file_exists($file)) {
      $this->io()->error(sprintf('File not found: %s', $file));
      return self::EXIT_FAILURE;
    }

    $content = file_get_contents($file);
    if ($content === FALSE) {
      $this->io()->error(sprintf('Unable to read file: %s', $file));
      return self::EXIT_FAILURE;
    }

    try {
      $payload = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->io()->error(sprintf('Invalid JSON syntax: %s', $e->getMessage()));
      return self::EXIT_FAILURE;
    }

    if (!is_array($payload) || !array_is_list($payload)) {
      $this->io()->error('Invalid JSON structure: Expected a top-level JSON array of price objects.');
      return self::EXIT_FAILURE;
    }

    $totalRows = count($payload);
    $this->io()->section(sprintf('Starting Ingestion of %s records...', number_format($totalRows)));

    // Initialize progress bar for large datasets
    $chunkEstimate = (int) ceil($totalRows / 1000);
    $progressBar = $this->io()->createProgressBar($chunkEstimate);
    $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
    $progressBar->setMessage('Parsing & validating records...');
    $progressBar->start();

    $startTime = microtime(TRUE);

    $result = $this->importer->import($payload, function (int $chunkCount, int $importedTotal, int $totalChunks, int $currentChunk) use ($progressBar): void {
      $progressBar->setMessage(sprintf('Upserting chunk %d/%d (%s records committed)', $currentChunk, $totalChunks, number_format($importedTotal)));
      $progressBar->advance();
    });

    $progressBar->setMessage('Finishing import & invalidating cache tags...');
    $progressBar->finish();
    $this->io()->newLine(2);

    $duration = microtime(TRUE) - $startTime;
    $memoryPeak = memory_get_peak_usage(TRUE) / (1024 * 1024);
    $throughput = $totalRows > 0 ? (int) ($totalRows / max($duration, 0.001)) : 0;

    $this->io()->success(sprintf('Import finished in %.2f seconds! Peak Memory: %.2f MB', $duration, $memoryPeak));

    $this->io()->table(
      ['Metric', 'Value'],
      [
        ['Total Rows in File', number_format($totalRows)],
        ['Successfully Upserted', number_format($result['imported'])],
        ['Skipped (Malformed)', number_format($result['skipped'])],
        ['Execution Duration', sprintf('%.3f seconds', $duration)],
        ['Processing Throughput', sprintf('%s records/second', number_format($throughput))],
      ]
    );

    if (!empty($result['errors'])) {
      $this->io()->warning(sprintf('Encountered %d errors. Displaying first 5:', count($result['errors'])));
      $errorRows = [];
      foreach (array_slice($result['errors'], 0, 5) as $err) {
        $errorRows[] = [$err['row'], $err['symbol'], $err['error']];
      }
      $this->io()->table(['Row', 'Symbol', 'Error Reason'], $errorRows);
    }

    return self::EXIT_SUCCESS;
  }

}

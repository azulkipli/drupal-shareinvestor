<?php

declare(strict_types=1);

namespace Drupal\Tests\market_feed\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Upsert;
use Drupal\Core\Database\Transaction;
use Drupal\market_feed\Service\MarketFeedImporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MarketFeedImporter service.
 *
 * @coversDefaultClass \Drupal\market_feed\Service\MarketFeedImporter
 */
class MarketFeedImporterTest extends TestCase {

  /**
   * Tests row validation, fault isolation, and cache tag invalidation.
   */
  public function testImportValidatesAndIsolatesErrors(): void {
    $connection = $this->createMock(Connection::class);
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    $time->method('getRequestTime')->willReturn(1700000000);

    $upsertQuery = $this->createMock(Upsert::class);
    $upsertQuery->method('key')->willReturnSelf();
    $upsertQuery->method('fields')->willReturnSelf();
    $upsertQuery->method('values')->willReturnSelf();
    $upsertQuery->method('execute')->willReturn(1);

    $transaction = $this->createMock(Transaction::class);
    $connection->method('startTransaction')->willReturn($transaction);
    $connection->method('upsert')->willReturn($upsertQuery);

    // Expect cache tag invalidation since at least one row is valid
    $invalidator->expects($this->once())
      ->method('invalidateTags')
      ->with([MarketFeedImporter::CACHE_TAG]);

    $importer = new MarketFeedImporter($connection, $invalidator, $time, $logger);

    $payload = [
      ['symbol' => 'AAPL', 'price' => 232.15, 'change' => -1.20, 'volume' => 51230000],
      ['symbol' => 'MSFT', 'price' => 415.60, 'change' => 3.45, 'volume' => 22150000],
      ['symbol' => '', 'price' => 10.0, 'change' => 0.0, 'volume' => 100], // invalid empty symbol
      ['symbol' => 'GOOG', 'price' => -5.0, 'change' => 1.0, 'volume' => 1000], // negative price
      ['symbol' => 'TSLA', 'price' => 250.0, 'change' => 'not_a_number', 'volume' => 1000], // non-numeric change
    ];

    $result = $importer->import($payload);

    $this->assertEquals(2, $result['imported']);
    $this->assertEquals(3, $result['skipped']);
    $this->assertCount(3, $result['errors']);
    $this->assertEquals(2, $result['errors'][0]['row']);
    $this->assertEquals(3, $result['errors'][1]['row']);
    $this->assertEquals(4, $result['errors'][2]['row']);
  }

  /**
   * Tests that empty valid rows do not trigger database upsert or cache invalidation.
   */
  public function testImportWithAllMalformedRows(): void {
    $connection = $this->createMock(Connection::class);
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    // No cache invalidation should be called if nothing was imported
    $invalidator->expects($this->never())->method('invalidateTags');
    $connection->expects($this->never())->method('upsert');

    $importer = new MarketFeedImporter($connection, $invalidator, $time, $logger);

    $payload = [
      ['symbol' => '', 'price' => 10.0, 'change' => 0.0, 'volume' => 100],
    ];

    $result = $importer->import($payload);

    $this->assertEquals(0, $result['imported']);
    $this->assertEquals(1, $result['skipped']);
  }

  /**
   * Tests that when duplicate symbols appear in the payload (like AAPL in exam sample),
   * the importer updates to the latest price in the batch.
   */
  public function testImportDeduplicatesSameSymbolKeepingLatestEntry(): void {
    $connection = $this->createMock(Connection::class);
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    $time->method('getRequestTime')->willReturn(1700000000);

    $upsertQuery = $this->createMock(Upsert::class);
    $upsertQuery->method('key')->willReturnSelf();
    $upsertQuery->method('fields')->willReturnSelf();

    // Verify values passed to upsert contains the updated price (233.00, not 232.15)
    $upsertQuery->expects($this->once())
      ->method('values')
      ->with([
        'symbol' => 'AAPL',
        'price' => 233.00,
        'change_amount' => -0.35,
        'volume' => 51500000,
        'updated_at' => 1700000000,
      ])
      ->willReturnSelf();

    $upsertQuery->method('execute')->willReturn(1);

    $transaction = $this->createMock(Transaction::class);
    $connection->method('startTransaction')->willReturn($transaction);
    $connection->method('upsert')->willReturn($upsertQuery);

    $importer = new MarketFeedImporter($connection, $invalidator, $time, $logger);

    $payload = [
      ['symbol' => 'AAPL', 'price' => 232.15, 'change' => -1.20, 'volume' => 51230000],
      ['symbol' => 'AAPL', 'price' => 233.00, 'change' => -0.35, 'volume' => 51500000],
    ];

    $result = $importer->import($payload);

    $this->assertEquals(1, $result['imported']);
    $this->assertEquals(0, $result['skipped']);
  }

}

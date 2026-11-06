<?php

declare(strict_types=1);

namespace Drupal\market_feed\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the /market-feed price display page.
 */
class MarketFeedViewController extends ControllerBase {

  public function __construct(
    protected Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * Builds the market prices view table with symbol filter.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request.
   *
   * @return array
   *   Drupal render array.
   */
  public function build(Request $request): array {
    $symbolFilter = trim((string) $request->query->get('symbol', ''));

    $query = $this->connection->select('market_feed_prices', 'p')
      ->fields('p', ['symbol', 'price', 'change_amount', 'volume', 'updated_at'])
      ->orderBy('symbol', 'ASC')
      ->range(0, 100);

    if ($symbolFilter !== '') {
      $query->condition('symbol', '%' . $this->connection->escapeLike($symbolFilter) . '%', 'LIKE');
    }

    $records = $query->execute()->fetchAll();

    $rows = [];
    foreach ($records as $item) {
      $changeVal = (float) $item->change_amount;
      $changeClass = $changeVal > 0 ? 'color: #0f766e; font-weight: bold;' : ($changeVal < 0 ? 'color: #b91c1c; font-weight: bold;' : '');
      $changePrefix = $changeVal > 0 ? '+' : '';

      $rows[] = [
        ['data' => ['#markup' => '<strong>' . Html::escape($item->symbol) . '</strong>']],
        ['data' => number_format((float) $item->price, 2)],
        [
          'data' => [
            '#markup' => '<span style="' . $changeClass . '">' . $changePrefix . number_format($changeVal, 2) . '</span>',
          ],
        ],
        ['data' => number_format((float) $item->volume)],
        ['data' => date('Y-m-d H:i:s', (int) $item->updated_at)],
      ];
    }

    $build = [];

    // Simple GET filter form
    $escapedSymbol = Html::escape($symbolFilter);
    $resetLink = $symbolFilter !== '' ? ' <a href="/market-feed" style="margin-left: 10px; color: #4b5563; text-decoration: underline;">Reset</a>' : '';

    $build['filter_form'] = [
      '#type' => 'inline_template',
      '#template' => '
        <div class="market-feed-filter" style="margin-bottom: 24px; padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;">
          <form method="get" action="/market-feed" style="display: flex; gap: 10px; align-items: center;">
            <label for="symbol-input" style="font-weight: 600;">Filter by Symbol:</label>
            <input id="symbol-input" type="text" name="symbol" value="{{ current_symbol }}" placeholder="e.g. AAPL, MSFT" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px;" />
            <button type="submit" style="padding: 6px 16px; background: #2563eb; color: #ffffff; border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">Filter</button>
            {{ reset_link|raw }}
          </form>
        </div>
      ',
      '#context' => [
        'current_symbol' => $escapedSymbol,
        'reset_link' => $resetLink,
      ],
    ];

    $build['prices_table'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Symbol'),
        $this->t('Price ($)'),
        $this->t('Change'),
        $this->t('Volume'),
        $this->t('Last Updated'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No stock prices found. Ingest prices via POST /api/market-feed/import.'),
      '#attributes' => [
        'class' => ['market-feed-table'],
        'style' => 'width: 100%; border-collapse: collapse; margin-top: 10px;',
      ],
    ];

    // Deliberate cache metadata
    $build['#cache'] = [
      'tags' => ['market_feed_prices:list'],
      'contexts' => [
        'url.query_args:symbol',
        'user.permissions',
      ],
      'max-age' => Cache::PERMANENT,
    ];

    return $build;
  }

}

<?php

declare(strict_types=1);

/**
 * Generates 50,000 stock price records for benchmark testing.
 * 49,950 valid records + 50 intentionally malformed records.
 */

$totalRows = 50000;
$malformedCount = 50;
$malformedInterval = (int) ($totalRows / $malformedCount);

$records = [];
$prefixes = ['AAPL', 'MSFT', 'GOOG', 'AMZN', 'TSLA', 'META', 'NVDA', 'BBCA', 'BBRI', 'TLKM', 'ASII', 'BMRI', 'BBNI', 'ICBP', 'UNVR'];

for ($i = 0; $i < $totalRows; $i++) {
  // Inject malformed records at predictable intervals
  if ($i % $malformedInterval === 0 && $malformedCount > 0) {
    if ($i % 2 === 0) {
      $records[] = [
        'symbol' => '', // Malformed: empty symbol
        'price' => 150.00,
        'change' => 1.25,
        'volume' => 1000000,
      ];
    } else {
      $records[] = [
        'symbol' => 'BAD' . $i,
        'price' => -10.50, // Malformed: negative price
        'change' => 0.00,
        'volume' => 5000,
      ];
    }
    continue;
  }

  $prefix = $prefixes[$i % count($prefixes)];
  $symbol = $prefix . '_' . (int) ($i / count($prefixes));

  $price = round(10.0 + (mt_rand(1, 100000) / 100.0), 2);
  $change = round((mt_rand(-500, 500) / 100.0), 2);
  $volume = mt_rand(10000, 50000000);

  $records[] = [
    'symbol' => substr($symbol, 0, 20),
    'price' => $price,
    'change' => $change,
    'volume' => $volume,
  ];
}

$outputFile = __DIR__ . '/../benchmark_50k.json';
file_put_contents($outputFile, json_encode($records, JSON_PRETTY_PRINT));

echo sprintf("Successfully generated %s records in %s (Size: %.2f MB)\n",
  number_format(count($records)),
  realpath($outputFile),
  filesize($outputFile) / (1024 * 1024)
);

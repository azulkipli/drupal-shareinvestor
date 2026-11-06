# ShareInvestor — Senior Software Engineer (Drupal/PHP) Technical Submission

This repository contains the implementation of the `market_feed` custom Drupal module, along with architectural documentation, code review, and SQL solutions for the ShareInvestor Senior Software Engineer technical assessment.

---

## 1. Setup Instructions (DDEV Local Environment)

The project is pre-configured with **DDEV** (Drupal 10, PHP 8.2, MariaDB 10.11, Nginx).

### Prerequisites
- Docker / Docker Desktop
- [DDEV](https://ddev.readthedocs.io/) (v1.23+)

### Starting the Environment
1. Start the DDEV containers:
   ```bash
   ddev start
   ```
2. (Optional) Re-install or verify the Drupal site:
   ```bash
   ddev drush status
   ```
3. Ensure the custom module `market_feed` is installed:
   ```bash
   ddev drush pm:install market_feed -y
   ```
4. Verify database schema:
   ```bash
   ddev mysql -e "DESCRIBE market_feed_prices;"
   ```

### Running Automated Tests
Run unit tests inside DDEV:
```bash
ddev exec 'vendor/bin/phpunit web/modules/custom/market_feed/tests/src/Unit/MarketFeedImporterTest.php'
```

### High-Volume Ingestion via Custom Drush Command (`drush mf:import`)
For importing large market feed datasets (e.g. 50,000+ rows) without HTTP request timeouts or gateway limits, the module provides a custom Drush command with an interactive real-time progress bar.

#### 1. Generate Benchmark Data (Optional)
Generate a 50,000-row realistic payload (49,950 valid rows + 50 isolated malformed rows):
```bash
ddev exec php scripts/generate_benchmark_payload.php
```
*(Creates `benchmark_50k.json` ~5.7 MB in project root).*

#### 2. Execute Ingestion with Drush
Run the command by passing the file path (relative to the container `/var/www/html/`):
```bash
# Standard command:
ddev drush market-feed:import /var/www/html/benchmark_50k.json

# Or using the shorthand alias:
ddev drush mf:import /var/www/html/benchmark_50k.json
```

#### 3. CLI Output & Progress Bar Preview
As the command runs, Drush renders a dynamic progress bar per 1,000-row chunk, followed by a performance metrics table and error diagnostics:
```text
Starting Ingestion of 50,000 records...
---------------------------------------
 50/50 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100% -- Upserting chunk 50/50 (49,950 records committed)

 [OK] Import finished in 0.65 seconds! Peak Memory: 66.21 MB

 ----------------------- ----------------------- 
  Metric                  Value                  
 ----------------------- ----------------------- 
  Total Rows in File      50,000                 
  Successfully Upserted   49,950                 
  Skipped (Malformed)     50                     
  Execution Duration      0.648 seconds          
  Processing Throughput   77,163 records/second  
 ----------------------- ----------------------- 

 ! [WARNING] Encountered 50 errors. Displaying first 5:
 ------ -------- ------------------------------------------------------------ 
  Row    Symbol   Error Reason                                                
 ------ -------- ------------------------------------------------------------ 
  0               Field "symbol" is required and must be a non-empty string.  
  1000            Field "symbol" is required and must be a non-empty string.  
 ------ -------- ------------------------------------------------------------ 
```

**Benchmark Performance on 50,000 Rows Payload:**
- **Drush CLI Throughput:** ~80,000+ records/sec (0.61–0.65 seconds, Peak Memory: 66.2 MB)
- **HTTP REST Ingestion:** ~92,000 records/sec (0.54 seconds)
- **Fault Isolation:** 49,950 records successfully upserted, 50 malformed records isolated into structured errors.

---

## 2. Testing the Ingestion API & Display Page

### A. Ingestion Endpoint (`POST /api/market-feed/import`)
The endpoint accepts a JSON array of price items and upserts them into `market_feed_prices`.

**Sample cURL Request:**
```bash
curl -X POST "https://drupal-ptshareinvestor.ddev.site/api/market-feed/import" \
  -H "Content-Type: application/json" \
  -d '[
    {"symbol": "AAPL", "price": 232.15, "change": -1.20, "volume": 51230000},
    {"symbol": "MSFT", "price": 415.60, "change": 3.45, "volume": 22150000},
    {"symbol": "AAPL", "price": 233.00, "change": -0.35, "volume": 51500000},
    {"symbol": "", "price": "invalid", "change": 0, "volume": -10}
  ]'
```

**Expected Response (HTTP 200):**
```json
{
  "imported": 2,
  "skipped": 1,
  "errors": [
    {
      "row": 3,
      "symbol": "",
      "error": "Field \"symbol\" is required and must be a non-empty string."
    }
  ]
}
```
*Note: AAPL is updated to 233.00 (the latest entry in the payload), MSFT is inserted, and the corrupt 4th row is safely isolated into the `errors` array without failing the batch.*

### B. Display Page (`GET /market-feed`)
- Visit `https://drupal-ptshareinvestor.ddev.site/market-feed` in your browser.
- **Filter by symbol:** Use the on-page form or append `?symbol=AAPL` to the URL.
- **Cache Invalidation:** The page utilizes Drupal cache tags (`market_feed_prices:list`). Any new import immediately invalidates the rendered table cache, ensuring no stale data is ever served.

---

## 3. Assumptions and Trade-Offs

### Part A — Custom Module (`market_feed`)
1. **Primary Key Strategy (`symbol` as PK):**
   - *Decision:* We opted for `symbol` as the primary key rather than a synthetic auto-increment `id`.
   - *Reasoning:* The table stores the latest snapshot per ticker. A single stock ticker has exactly one current price. Using `symbol` directly as the clustered index avoids secondary unique index overhead, saves storage, and allows `INSERT ... ON DUPLICATE KEY UPDATE` to lock directly on the primary key index without secondary lookups.
2. **Chunking Size (1,000 rows):**
   - *Decision:* Ingest payloads up to 50,000 rows in chunks of 1,000 using `array_chunk()`.
   - *Reasoning:* Prevents MySQL `max_allowed_packet` limits and memory exhaustion while reducing network round-trips from 50,000 to 50.
3. **Transaction Boundary per Chunk:**
   - *Decision:* Wrapping each chunk in an explicit database transaction (`$connection->startTransaction()`).
   - *Reasoning:* Ensures atomic writes for each chunk without holding an open transaction lock across 50,000 rows for prolonged periods.
4. **Cache Tags vs TTL:**
   - *Decision:* `Cache::PERMANENT` combined with cache tag `market_feed_prices:list` and cache context `url.query_args:symbol`.
   - *Reasoning:* Event-driven cache invalidation is strictly superior to arbitrary TTLs for market data. The cache lives permanently until a price import occurs, at which point it is instantly invalidated across Drupal's render cache, Dynamic Page Cache, and reverse proxies (Varnish).

---

## 4. Machine-to-Machine (M2M) Authentication Strategy

For production M2M ingestion by automated third-party market data vendors:
1. **OAuth2 Client Credentials Grant (Recommended):**
   - Implemented via Drupal's `simple_oauth` module.
   - The data vendor authenticates against `/oauth/token` using a provisioned `client_id` and `client_secret` to obtain short-lived JWT tokens (1 hour expiration). Requests pass `Authorization: Bearer <token>`.
2. **HMAC Signature Verification (`X-Signature`):**
   - The vendor signs the raw JSON body with a shared secret using SHA-256 (`hash_hmac('sha256', $body, $secret)`).
   - An inbound HTTP middleware/event subscriber verifies the signature before parsing the payload, preventing payload tampering in transit.
3. **Network Boundary / Mutual TLS (mTLS):**
   - Terminated at the reverse proxy (Nginx / Cloudflare Ingress). Restricts incoming traffic to known vendor IP CIDR blocks and client certificates.

---

## 5. Automated Test Strategy for Part A

| Test Level | Scope | Tools |
| :--- | :--- | :--- |
| **Unit Test** | Tests `MarketFeedImporter` in isolation: row validation logic, error isolation, symbol deduplication, chunking, and cache tag calls. | PHPUnit (UnitTestCase with mocks) |
| **Kernel Test** | Tests real database schema creation (`market_feed_prices`), upsert queries against an active database, rollbacks on constraint failures, and cache invalidation. | Drupal `KernelTestBase` |
| **Functional / API Test** | Tests the HTTP endpoint `POST /api/market-feed/import` end-to-end: permission checks (403 Forbidden vs 200 OK), JSON content-type negotiation, and cache tags on `/market-feed`. | Drupal `BrowserTestBase` |

---

## 6. Time Spent per Part

- **Part A (Custom Module & Tests):** ~70 minutes
- **Part B (Architecture Write-Up):** ~30 minutes
- **Part C (Code Review & Refactoring):** ~25 minutes
- **Part D (SQL Solutions & Optimizations):** ~20 minutes
- **Total:** ~2.5 hours

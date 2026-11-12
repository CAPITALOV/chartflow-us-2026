#!/usr/bin/env php
<?php
/**
 * Импорт баров из базы StockForecast (stockforecast.ru) в chartflow.
 *
 * Использование (запускать можно из любого каталога):
 *   php /var/www/chartflow.ru/data/www/rus.chartflow.ru/scripts/import_sf.php moex
 *   php /var/www/chartflow.ru/data/www/chartflow.ru/scripts/import_sf.php us
 *
 * Правки от 2026-11-12 (v2):
 *  - normCode срезает и префиксы (US2.AAPL), и суффиксы (AAPL.US), и приводит BRK-B к BRK.B;
 *  - дубли тикеров в stock_map отсекаются GROUP BY (один тикер — один прогон);
 *  - транзакции с откатом, нормализация дат, require вместо include, поточное чтение баров.
 */

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
if ($argc < 2 || !in_array($argv[1], ['moex', 'us'], true)) {
    fwrite(STDERR, "Использование: php import_sf.php [moex|us]\n");
    exit(1);
}
$market = $argv[1];

$ROOTS = [
    'moex' => '/var/www/chartflow.ru/data/www/rus.chartflow.ru',
    'us'   => '/var/www/chartflow.ru/data/www/chartflow.ru',
];
$ROOT    = $ROOTS[$market];
$SF_ROOT = '/var/www/stockforecast/data/www/stockforecast.ru';

if (!is_file($ROOT . '/config/db.php')) { fwrite(STDERR, "Не найден {$ROOT}/config/db.php\n"); exit(1); }
if (!is_file($SF_ROOT . '/config.php')) { fwrite(STDERR, "Не найден {$SF_ROOT}/config.php\n"); exit(1); }

/* ---------- база chartflow ---------- */
$cfg = require $ROOT . '/config/db.php';
preg_match('/host=([^;]+)/',  $cfg['dsn'], $h);
preg_match('/dbname=([^;]+)/', $cfg['dsn'], $d);
$chart = new PDO(
    'mysql:host=' . ($h[1] ?: '127.0.0.1') . ';port=3306;dbname=' . $d[1] . ';charset=utf8',
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/* ---------- база StockForecast ---------- */
require $SF_ROOT . '/config.php';
$pass = defined('DB_PASS') ? constant('DB_PASS')
      : (defined('DB_PASSWORD') ? constant('DB_PASSWORD') : '');
if ($pass === '') { fwrite(STDERR, "Не нашёл пароль в config.php StockForecast\n"); exit(1); }
$sf = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/* ---------- нормализация кодов ---------- */
function normCode(string $c): string {
    $c = strtoupper(trim($c));
    // префиксы рынков: US2.AAPL -> AAPL, ICE.BRN -> BRN, GDAX.BTC-USD -> BTC-USD
    if (preg_match('/^(US\d*|ICE|GDAX|COMEX|SPB)\.(.+)$/', $c, $m)) $c = $m[2];
    // финамовский суффикс рынка: AAPL.US -> AAPL, BRK.A.US -> BRK.A
    if (preg_match('/^(.+)\.(US|ME|L|DE|F|T)$/', $c, $m)) $c = $m[1];
    // BRK-B эквивалентен BRK.B: приводим дефис к точке с обеих сторон одинаково
    $c = str_replace('-', '.', $c);
    return $c;
}

function normDate(?string $d): ?string {
    if ($d === null || $d === '') return null;
    return substr($d, 0, 10);
}

// рынок SF -> «свой» finam_market в chartflow
$pref = ['moex' => 1, 'us' => 2][$market];

// Ручной маппинг: тикер SF => id в cap_stock (id проверены запросом!)
$manual = [
    'us'   => ['GSPC' => 777, 'DJI' => 11, 'NDX' => 721],
    'moex' => [],
];

$stocks = $chart->query('SELECT id, finam_code, finam_market FROM cap_stock')->fetchAll(PDO::FETCH_ASSOC);
$byCode = [];
foreach ($stocks as $s) {
    if ($s['finam_code'] === null || $s['finam_code'] === '') continue;
    $same = ((int)$s['finam_market'] === $pref) ? 1 : 0;
    $byCode[normCode($s['finam_code'])][$same][] = $s;
}

function findStock(array $byCode, string $ticker, array &$dupWarn, array $manual, string $market): ?array {
    if (isset($manual[$market][$ticker])) {
        return ['id' => (int)$manual[$market][$ticker], 'finam_code' => 'manual:'.$ticker, 'finam_market' => 0];
    }
    $c = normCode($ticker);
    if (!isset($byCode[$c])) return null;
    if (!empty($byCode[$c][1])) {
        if (count($byCode[$c][1]) > 1) $dupWarn[] = $c;
        return $byCode[$c][1][0];
    }
    return null; // кросс-рыночный fallback отключён: слишком легко перепутать бумаги
}

// один тикер — один прогон, даже если в stock_map несколько строк
$tickers = $sf->prepare('SELECT MIN(id) AS id, ticker FROM stock_map WHERE market = ? GROUP BY ticker ORDER BY ticker');
$tickers->execute([$market]);

$getLast = $chart->prepare('SELECT MAX(date) FROM cap_stock_kurs WHERE stock_id = ?');
$hasRow  = $chart->prepare('SELECT id FROM cap_stock_kurs WHERE stock_id = ? AND date = ?');
$upd     = $chart->prepare('UPDATE cap_stock_kurs SET kurs = ?, open = ?, high = ?, low = ?, close = ? WHERE stock_id = ? AND date = ?');
$ins     = $chart->prepare('INSERT INTO cap_stock_kurs (stock_id, date, kurs, open, high, low, close, volume) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)');
$bars    = $sf->prepare('SELECT ts, open, high, low, close FROM history_bars WHERE stock_id = ? AND ts >= ? ORDER BY ts');

$unmatched = []; $dupWarn = [];
$imported = 0; $updated = 0; $t0 = time();

while ($t = $tickers->fetch(PDO::FETCH_ASSOC)) {
    $stock = findStock($byCode, $t['ticker'], $dupWarn, $manual, $market);
    if (!$stock) { $unmatched[] = $t['ticker']; continue; }
    $sid = (int)$stock['id'];

    $getLast->execute([$sid]);
    $last = normDate($getLast->fetchColumn());

    $bars->execute([$t['id'], $last ?: '1990-01-01']);

    $nIns = $nUpd = 0;
    try {
        $chart->beginTransaction();
        while ($b = $bars->fetch(PDO::FETCH_ASSOC)) {
            $ts = normDate($b['ts']);
            if ($last !== null && $ts === $last) {
                $hasRow->execute([$sid, $ts]);
                if ($hasRow->fetch()) {
                    $upd->execute([$b['close'], $b['open'], $b['high'], $b['low'], $b['close'], $sid, $ts]);
                    $nUpd++;
                    continue;
                }
            }
            $ins->execute([$sid, $ts, $b['close'], $b['open'], $b['high'], $b['low'], $b['close']]);
            $nIns++;
        }
        $chart->commit();
    } catch (Throwable $e) {
        if ($chart->inTransaction()) $chart->rollBack();
        fwrite(STDERR, "Ошибка на {$t['ticker']} (stock_id={$sid}): " . $e->getMessage() . "\n");
        continue;
    }
    $imported += $nIns; $updated += $nUpd;
    printf("%-12s -> stock_id=%d  +%d новых, %d обновл\n", $t['ticker'], $sid, $nIns, $nUpd);
}

printf("\nИтого: вставлено %d, обновлено %d, за %d сек\n", $imported, $updated, time() - $t0);
if ($dupWarn)   echo "Внимание, несколько совпадений по коду: " . implode(', ', array_unique($dupWarn)) . "\n";
if ($unmatched) echo "Не сопоставлено (" . count($unmatched) . "): " . implode(', ', $unmatched) . "\n";

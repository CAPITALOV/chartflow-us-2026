<?php
declare(strict_types=1);
/* Официальный курс USD/RUB от ЦБ РФ -> cap_stock_kurs.
   Запуск: php scripts/load_cbr_usdrub.php [stock_id] [год_начала] */

$cfg = require __DIR__ . '/../config/db.php';
preg_match('/host=([^;]+)/', $cfg['dsn'], $mH);
preg_match('/dbname=([^;]+)/', $cfg['dsn'], $mD);
$pdo = new PDO('mysql:host=' . $mH[1] . ';dbname=' . $mD[1] . ';charset=utf8mb4',
    $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$stockId   = (int)($argv[1] ?? 755);
$startYear = (int)($argv[2] ?? 2000);
$endYear   = (int)date('Y');

$pdo->prepare("UPDATE cap_stock SET is_enabled = 1 WHERE id = ?")->execute([$stockId]);

$stmt = $pdo->prepare("INSERT INTO cap_stock_kurs (stock_id, date, kurs, open, high, low, close, volume)
    VALUES (?, ?, ?, ?, ?, ?, ?, 0)
    ON DUPLICATE KEY UPDATE kurs = VALUES(kurs), open = VALUES(open), high = VALUES(high),
    low = VALUES(low), close = VALUES(close)");

function fetchXml(string $url): ?SimpleXMLElement {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (chartflow importer)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body) || $body === '') return null;
    $xml = @simplexml_load_string($body);
    return $xml === false ? null : $xml;
}

$total = 0;
for ($y = $startYear; $y <= $endYear; $y++) {
    $from = "01/01/$y";
    $to   = ($y === $endYear) ? date('d/m/Y') : "31/12/$y";
    $xml  = fetchXml("https://www.cbr.ru/scripts/XML_dynamic.asp?date_req1=$from&date_req2=$to&VAL_NM_RQ=R01235");
    if ($xml === null) { echo "  [warn] $y: ЦБ не ответил\n"; continue; }
    $cnt = 0;
    foreach ($xml->Record as $rec) {
        $d  = (string)$rec['Date']; // dd.mm.yyyy
        $dt = substr($d, 6, 4) . '-' . substr($d, 3, 2) . '-' . substr($d, 0, 2);
        $nom = (float)str_replace(',', '.', (string)$rec->Nominal);
        $val = (float)str_replace(',', '.', (string)$rec->Value);
        if ($nom <= 0 || $val <= 0) continue;
        $rate = $val / $nom; // рублей за 1 доллар
        $stmt->execute([$stockId, $dt, $rate, $rate, $rate, $rate, $rate]);
        $cnt++;
    }
    $total += $cnt;
    echo "$y: $cnt\n";
    usleep(300000);
}
echo "Итого строк: $total\n";

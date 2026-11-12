<?php
/**
 * Скачивает логотипы по тикерам и раскладывает в upload/FileUpload2/cap_stock/{id:08d}/small/logo.png
 * Источники: Parqet -> Elbstream (США и др.), Google-favicon по домену (MOEX).
 * Запуск: php download_logos.php [--go] [--limit N]   (без --go — только репетиция)
 */

$cfg = require __DIR__ . '/../config/db.php';
$db = new PDO($cfg['dsn'], $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$go = in_array('--go', $argv ?? []);
$limit = 0;
foreach (($argv ?? []) as $i => $a) if ($a === '--limit' && isset($argv[$i+1])) $limit = (int)$argv[$i+1];

$webRoot = '/var/www/chartflow.ru/data/www/chartflow.ru/public_html';
$base = $webRoot . '/upload/FileUpload2/cap_stock';

// Домены российских компаний (fallback, если по тикеру ничего не найдено)
$domains = [
    'GAZP' => 'gazprom.ru', 'SBER' => 'sberbank.ru', 'LKOH' => 'lukoil.ru',
    'GMKN' => 'nornickel.com', 'ROSN' => 'rosneft.ru', 'VTBR' => 'vtb.ru',
    'TATN' => 'tatneft.ru', 'NVTK' => 'novatek.ru', 'PLZL' => 'polyus.com',
    'MGNT' => 'magnit.ru', 'MOEX' => 'moex.com', 'ALRS' => 'alrosa.ru',
    'SNGS' => 'surgutneftegas.ru', 'PHOR' => 'phosagro.ru', 'AFLT' => 'aeroflot.ru',
    'CHMF' => 'severstal.com', 'RUAL' => 'rusal.com', 'MTSS' => 'mts.ru',
    'YNDX' => 'yandex.com', 'SMLT' => 'samoletgroup.ru',
];

function fetchLogo(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ($code == 200 && $data && str_starts_with($type, 'image/')) ? $data : null;
}

$rows = $db->query("SELECT id, finam_market, finam_code FROM cap_stock
                    WHERE is_enabled = 1 AND finam_code != '' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($limit) $rows = array_slice($rows, 0, $limit);

$ok = $skip = $fail = 0;
foreach ($rows as $r) {
    $code = trim($r['finam_code']);
    $isMoex = $r['finam_market'] == 1;

    // индексы, фьючерсы, валюты и крипта — логотипов не бывает
    if (!preg_match('/^[A-Z][A-Z.]{0,9}$/', $code) || str_starts_with($code, '^')) { $skip++; continue; }

    $data = null; $src = '';
    if ($isMoex) {
        if (isset($domains[$code])) {
            $src = "favicon:{$domains[$code]}";
            $data = fetchLogo("https://www.google.com/s2/favicons?domain={$domains[$code]}&sz=200");
        }
    } else {
        foreach ([
            "https://assets.parqet.com/logos/symbol/{$code}?format=png&size=200",
            "https://api.elbstream.com/logos/symbol/{$code}?format=png&size=200",
        ] as $url) {
            if ($data = fetchLogo($url)) { $src = $url; break; }
        }
    }

    $img = $data ? @imagecreatefromstring($data) : null;
    if (!$img) { $fail++; echo "miss  id={$r['id']} {$code}\n"; continue; }

    // приводим к 200x200 на белом фоне (contain)
    $w = imagesx($img); $h = imagesy($img);
    $k = min(200 / $w, 200 / $h);
    $nw = (int)round($w * $k); $nh = (int)round($h * $k);
    $canvas = imagecreatetruecolor(200, 200);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $img, (int)((200-$nw)/2), (int)((200-$nh)/2), 0, 0, $nw, $nh, $w, $h);

    $dir = sprintf('%s/%08d/small', $base, $r['id']);
    if (!$go) { echo "[dry] id={$r['id']} {$code} <- {$src}\n"; imagedestroy($img); $ok++; continue; }

    if (!is_dir($dir)) mkdir($dir, 0775, true);
    imagepng($canvas, $dir . '/logo.png');
    imagedestroy($img); imagedestroy($canvas);

    $db->prepare("UPDATE cap_stock SET logo = ? WHERE id = ?")
       ->execute(["/upload/FileUpload2/cap_stock/" . sprintf('%08d', $r['id']) . "/small/logo.png", $r['id']]);
    $ok++;
}
echo "Итого: обработано {$ok}, пропущено (не тикер) {$skip}, не найдено {$fail}\n";

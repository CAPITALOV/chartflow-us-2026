<?php
/**
 * Скачивает логотипы по тикерам в upload/FileUpload2/cap_stock/{id:08d}/small/logo.png
 * Источники: Parqet -> Elbstream -> favicon (Яндекс -> Google -> прямая, с ICO->PNG).
 * Запуск: php download_logos.php [--db=chart_sotis_ru] [--root=/путь/к/корню]
 *         [--go] [--limit N] [--all]
 * По умолчанию обрабатываются только записи без логотипа; --all — все.
 */

$cfg = require __DIR__ . '/../config/db.php';

$dbArg = ''; $rootArg = '';
foreach (($argv ?? []) as $a) {
    if (str_starts_with($a, '--db='))   $dbArg   = substr($a, 5);
    if (str_starts_with($a, '--root=')) $rootArg = substr($a, 7);
}
if ($dbArg !== '') $cfg['dsn'] = preg_replace('/dbname=[^;]+/', 'dbname=' . $dbArg, $cfg['dsn']);

$db = new PDO($cfg['dsn'], $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$go = in_array('--go', $argv ?? []);
$all = in_array('--all', $argv ?? []);
$limit = 0;
foreach (($argv ?? []) as $i => $a) if ($a === '--limit' && isset($argv[$i+1])) $limit = (int)$argv[$i+1];

$webRoot = $rootArg !== '' ? rtrim($rootArg, '/')
    : '/var/www/chartflow.ru/data/www/chartflow.ru/public_html';
$base = $webRoot . '/upload/FileUpload2/cap_stock';

// Домены эмитентов (только проверенные; недостоверные — нарочно не вписаны)
$domains = [
    // первый эшелон
    'GAZP' => 'www.gazprom.ru', 'SBER' => 'sberbank.ru',  'LKOH' => 'lukoil.ru',
    'GMKN' => 'nornickel.com',  'ROSN' => 'rosneft.ru',   'VTBR' => 'vtb.ru',
    'TATN' => 'tatneft.ru',     'NVTK' => 'novatek.ru',   'PLZL' => 'polyus.com',
    'MGNT' => 'magnit.ru',      'MOEX' => 'moex.com',     'ALRS' => 'alrosa.ru',
    'SNGS' => 'surgutneftegas.ru', 'PHOR' => 'phosagro.ru', 'AFLT' => 'aeroflot.ru',
    'CHMF' => 'severstal.com',  'RUAL' => 'rusal.com',    'MTSS' => 'mts.ru',
    'YNDX' => 'yandex.ru',      'SMLT' => 'samoletgroup.ru',
    'PIKK' => 'pik.ru',         'LSRG' => 'lsrgroup.ru',  'NLMK' => 'nlmk.com',
    'MAGN' => 'mmk.ru',         'FEES' => 'rosseti.ru',   'HYDR' => 'rushydro.ru',
    'RTKM' => 'rt.ru',          'MTLR' => 'mechel.ru',    'KMAZ' => 'kamaz.ru',
    'IRAO' => 'interrao.ru',    'SELG' => 'seligdar.ru',  'AKRN' => 'akron.ru',
    'BSPB' => 'bspb.ru',        'SGZH' => 'segezha-group.com',
    'LENT' => 'lenta.com',      'SVCB' => 'sovcombank.ru','TCSG' => 'tbank.ru',
    'T'    => 'tbank.ru',       'OZON' => 'ozon.ru',      'YDEX' => 'yandex.ru',
    'FIVE' => 'x5.ru',          'AFKS' => 'afksistema.ru','TRNFP' => 'transneft.ru',
    'SIBN' => 'gazprom-neft.ru','MSNG' => 'mosenergo.ru', 'RBCM' => 'rbc.ru',
    'OKEY' => 'okeydostavka.ru','ETLN' => 'etalongroup.com','UNKL' => 'uralkali.com',
    'KFBA' => 'ingrad.com',     'ELFV' => 'el5energo.ru', 'LMBZ' => 'lambumiz.ru',
    'UGLD' => 'ugold.ru',       'USBN' => 'bankuralsib.ru',
    'RAGR' => 'rusagrogroup.com', 'MSTT' => 'mostotrest.ru',
    // привилегированные
    'GAZPP' => 'www.gazprom.ru','SBERP' => 'sberbank.ru', 'TATNP' => 'tatneft.ru',
    'SNGSP' => 'surgutneftegas.ru', 'ALRSP' => 'alrosa.ru', 'ROSNP' => 'rosneft.ru',
    'MTSSP' => 'mts.ru',        'YNDXP' => 'yandex.ru',   'CHMFP' => 'severstal.com',
    'LKOB' => 'lukoil.ru',      'MTLRP' => 'mechel.ru',   'GMKNP' => 'nornickel.com',
    // ТНС энерго (один домен на группу) + холдинг
    'KBSB' => 'tns-e.ru',       'MRSB' => 'tns-e.ru',     'NNSB' => 'tns-e.ru',
    'RTSB' => 'tns-e.ru',       'VRSB' => 'tns-e.ru',     'YRSB' => 'tns-e.ru',
    'MISB' => 'tns-e.ru',       'MISBP' => 'tns-e.ru',    'TNSE' => 'tns-e.ru',
    // Россети
    'MRKK' => 'rosseti.ru',     'MRKP' => 'rosseti.ru',   'MRKS' => 'rosseti.ru',
    'MRKU' => 'rosseti.ru',     'MRKV' => 'rosseti.ru',   'MRKY' => 'rosseti.ru',
    'MRKZ' => 'rosseti.ru',     'MSRS' => 'rosseti.ru',   'MRKC' => 'rosseti.ru',
    'LSNG' => 'lenenergo.ru',   'LSNGP' => 'lenenergo.ru',
    // Pacific Utilities
    'ASSB' => 'pacificutilities.com', 'KCHE' => 'pacificutilities.com',
    'KCHEP' => 'pacificutilities.com','SLEN' => 'pacificutilities.com',
    'MAGE' => 'pacificutilities.com', 'MAGEP' => 'pacificutilities.com',
    'YKEN' => 'pacificutilities.com', 'YKENP' => 'pacificutilities.com',
    'KLSB' => 'pacificutilities.com', 'SAGO' => 'pacificutilities.com',
    'SAGOP' => 'pacificutilities.com',
    // одиночные эмитенты
    'MVID' => 'mvideo.ru',      'HEAD' => 'hh.ru',        'EVRZ' => 'evraz.com',
    'ENPG' => 'enplusgroup.com','FESH' => 'fesco.ru',     'FIXR' => 'fix-price.com',
    'TTLK' => 'tattelecom.ru',  'UTAR' => 'utair.ru',     'VKCO' => 'vk.company',
    'MBNK' => 'mtsbank.ru',     'MDMG' => 'mdmg.ru',      'MGTS' => 'mgts.ru',
    'MGTSP' => 'mgts.ru',       'SPBE' => 'spbex.ru',     'SVAV' => 'sollers-auto.com',
    'VSMO' => 'vsmo.ru',        'OGKB' => 'ogk2.ru',      'NKNC' => 'nkama.ru',
    'KAZT' => 'kuibyshevazot.ru','KAZTP' => 'kuibyshevazot.ru',
    'RNFT' => 'russneft.ru',    'ROLO' => 'rusolovo.com', 'RENI' => 'renins.com',
    'TGKA' => 'tgc1.ru',        'TGKB' => 'tgc2.ru',      'TGKBP' => 'tgc2.ru',
    'TGKN' => 'tgk14.ru',       'LEAS' => 'europlan.ru',  'NAUK' => 'npo-nauka.ru',
    'NFAZ' => 'nefaz.ru',       'POSI' => 'positiv.tech', 'PRMD' => 'promomed.ru',
    'HIMCP' => 'himprom.ru',    'INCB' => 'incab.ru',     'IRKT' => 'irkut.com',
    'IGST' => 'mechel.ru',      'IGSTP' => 'mechel.ru',
    'KRKN' => 'saratovnpz.rosneft.ru', 'KRKNP' => 'saratovnpz.rosneft.ru',
    'MGNZ' => 'smz.ru',         'OMZZP' => 'omz.ru',      'PAZA' => 'pavavto.ru',
    'UNAC' => 'aviastar-sp.ru', 'URKZ' => 'ukz.ru',       'VSYD' => 'vsz.ru',
    'VSYDP' => 'vsz.ru',        'WTCM' => 'wtcmoscow.ru', 'YAKG' => 'yatek.ru',
    'ZAYM' => 'zaimer.ru',      'ZVEZ' => 'zvezda.spb.ru','KLVZ' => 'kristall-smolensk.ru',
    'UWGN' => 'ovk-rail.com',   'APTK' => '366.ru',       'BANE' => 'bashneft.ru',
    'BANEP' => 'bashneft.ru',   'CHGZ' => 'cherkizovo.com','CHKZ' => 'chkpz.ru',
    'CHMK' => 'mechel.ru',      'DIAS' => 'diasoft.ru',   'DOMRF' => 'domrf.ru',
    'AMEZ' => 'amet.ru',        'LKON' => 'lukoil.ru',    'BELU' => 'belugagroup.ru',
    'HNFG' => 'henderson.ru',   'KMEZ' => 'kumz.ru',      'GCHE' => 'cherkizovo.com',
    'KUZB' => 'kuzbank.ru',
];

function domainFor(string $code, array $domains): ?string {
    if (isset($domains[$code])) return $domains[$code];
    $base = preg_replace('/P$/u', '', $code);      // SBERP -> SBER
    return isset($domains[$base]) ? $domains[$base] : null;
}

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

// ICO -> PNG: вынимаем наибольший PNG-кадр (Vsize/Voff — 4-байтовые поля)
function icoToPng(?string $data): ?string {
    if (!$data || strlen($data) < 22) return null;
    $count = unpack('v', substr($data, 4, 2))[1];
    $best = null; $bestSize = 0;
    for ($i = 0; $i < $count; $i++) {
        $off = 6 + $i * 16;
        if ($off + 16 > strlen($data)) break;
        $e = unpack('Cw/Ch/Cnc/Cr/vplanes/vbits/Vsize/Voff', substr($data, $off, 16));
        if ($e['off'] + $e['size'] > strlen($data)) continue;
        $blob = substr($data, $e['off'], $e['size']);
        if (str_starts_with($blob, "\x89PNG") && $e['size'] > $bestSize) {
            $best = $blob; $bestSize = $e['size'];
        }
    }
    return $best;
}

$only = $all ? '' : " AND (logo IS NULL OR logo = '')";
$rows = $db->query("SELECT id, finam_market, finam_code FROM cap_stock
                    WHERE is_enabled = 1 AND finam_code != ''{$only} ORDER BY id")
             ->fetchAll(PDO::FETCH_ASSOC);
if ($limit) $rows = array_slice($rows, 0, $limit);

$ok = $skip = $fail = 0;
foreach ($rows as $r) {
    $code = trim($r['finam_code']);
    $isMoex = $r['finam_market'] == 1;

    // фьючерсы/валюты/индексы (содержат точку) — логотипов не бывает
    if (!preg_match('/^[A-Z][A-Z0-9\-]{0,9}$/', $code)) { $skip++; continue; }

    $sources = [
        ["Parqet",    "https://assets.parqet.com/logos/symbol/{$code}?format=png&size=200"],
        ["Elbstream", "https://api.elbstream.com/logos/symbol/{$code}?format=png&size=200"],
    ];
    if ($isMoex && ($dom = domainFor($code, $domains))) {
        $sources[] = ["yandex:{$dom}",  "https://favicon.yandex.net/favicon/{$dom}"];
        $sources[] = ["favicon:{$dom}", "https://www.google.com/s2/favicons?domain={$dom}&sz=200"];
        $sources[] = ["favicon:{$dom}", "https://{$dom}/favicon.ico"];
    }

    // GD-проверка внутри цикла: картинка не парсится — пробуем следующий источник
    $img = null; $src = '';
    foreach ($sources as [$label, $url]) {
        $raw = fetchLogo($url);
        if (!$raw) continue;
        if (str_starts_with($raw, "\x00\x00\x01\x00")) $raw = icoToPng($raw);   // ICO -> PNG
        if ($raw && ($img = @imagecreatefromstring($raw))) { $src = $label; break; }
    }
    if (!$img) { $fail++; echo "miss  id={$r['id']} {$code}\n"; continue; }

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

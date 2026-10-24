<?php
/**
 * ScreamingRabbit · Sunucu hazırlık kontrolü
 *
 * Kullanım: cPanel → Dosya Yöneticisi → public_html içine yükle,
 * tarayıcıda https://alanadin.com/sunucu-kontrol.php adresini aç,
 * çıktıyı kopyala, ardından DOSYAYI SİL (sunucu bilgisi gösterir).
 *
 * Hiçbir ayarı değiştirmez, yalnızca okur.
 */

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

function row($label, $value, $ok = null)
{
    $mark = $ok === null ? '  ' : ($ok ? 'OK' : '!!');
    printf("[%s] %-28s %s\n", $mark, $label, $value);
}

function bytes($n)
{
    if ($n === false || $n === null) {
        return '?';
    }
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n /= 1024;
        $i++;
    }
    return round($n, 1) . ' ' . $u[$i];
}

function fn_enabled($name)
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array($name, $disabled, true);
}

echo "ScreamingRabbit sunucu kontrolü · " . date('Y-m-d H:i:s T') . "\n";
echo str_repeat('=', 60) . "\n\n";

echo "## PHP\n";
row('PHP sürümü (web)', PHP_VERSION, version_compare(PHP_VERSION, '8.3.0', '>='));
row('SAPI', PHP_SAPI);
row('İşletim sistemi', php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'));
row('memory_limit', ini_get('memory_limit'));
row('max_execution_time', ini_get('max_execution_time'));
row('upload_max_filesize', ini_get('upload_max_filesize'));

echo "\n## PHP eklentileri\n";
$required = ['curl', 'mbstring', 'intl', 'dom', 'xml', 'zip', 'gd', 'pdo_mysql', 'bcmath', 'fileinfo', 'openssl', 'tokenizer', 'ctype', 'json'];
$missing = [];
foreach ($required as $ext) {
    $has = extension_loaded($ext);
    if (!$has) {
        $missing[] = $ext;
    }
    row($ext, $has ? 'var' : 'YOK', $has);
}
row('opcache (önerilir)', extension_loaded('Zend OPcache') ? 'var' : 'yok');
row('redis (opsiyonel)', extension_loaded('redis') ? 'var' : 'yok');

echo "\n## Sunucu kaynakları\n";
$meminfo = @file_get_contents('/proc/meminfo');
if ($meminfo && preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) {
    $total = $m[1] * 1024;
    preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $a);
    $avail = isset($a[1]) ? $a[1] * 1024 : null;
    row('RAM toplam', bytes($total), $total >= 2 * 1024 ** 3);
    row('RAM kullanılabilir', bytes($avail));
} else {
    row('RAM', 'okunamadı');
}
$cpuinfo = @file_get_contents('/proc/cpuinfo');
row('CPU çekirdek', $cpuinfo ? (string) substr_count($cpuinfo, 'processor') : '?');
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
row('Yük (1/5/15 dk)', $load ? implode(' / ', array_map(fn($x) => round($x, 2), $load)) : '?');
$home = getenv('HOME') ?: __DIR__;
$free = @disk_free_space($home);
row('Boş disk (hesap)', bytes($free), $free !== false && $free >= 10 * 1024 ** 3);

echo "\n## Komut satırı araçları\n";
$canExec = fn_enabled('shell_exec');
row('shell_exec', $canExec ? 'açık' : 'kapalı (normal olabilir)');
if ($canExec) {
    $cmds = [
        'PHP CLI' => 'php -v 2>/dev/null | head -n1',
        'PHP 8.3 CLI (ea)' => '/opt/cpanel/ea-php83/root/usr/bin/php -v 2>/dev/null | head -n1',
        'PHP 8.4 CLI (ea)' => '/opt/cpanel/ea-php84/root/usr/bin/php -v 2>/dev/null | head -n1',
        'Composer' => 'composer --version 2>/dev/null || /opt/cpanel/composer/bin/composer --version 2>/dev/null',
        'Git' => 'git --version 2>/dev/null',
        'MySQL/MariaDB' => 'mysql --version 2>/dev/null',
        'Node.js (opsiyonel)' => 'node -v 2>/dev/null',
        'crontab' => 'command -v crontab 2>/dev/null',
        'Web sunucusu' => '(httpd -v 2>/dev/null || /usr/local/lsws/bin/lshttpd -v 2>/dev/null) | head -n1',
    ];
    foreach ($cmds as $label => $cmd) {
        $out = trim((string) @shell_exec($cmd));
        row($label, $out !== '' ? $out : 'bulunamadı');
    }
}
row('Web sunucusu (header)', $_SERVER['SERVER_SOFTWARE'] ?? '?');

echo "\n## Dışa erişim (crawler için)\n";
if (function_exists('curl_init')) {
    $ch = curl_init('https://www.google.com/robots.txt');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    row('HTTPS çıkış (google.com)', $code ? "HTTP $code" : "hata: $err", $code >= 200 && $code < 400);
    row('curl_multi', function_exists('curl_multi_init') ? 'var' : 'YOK', function_exists('curl_multi_init'));
}

echo "\n## Özet\n";
$okPhp = version_compare(PHP_VERSION, '8.3.0', '>=');
echo $okPhp ? "- PHP sürümü uygun.\n" : "- PHP 8.3+ gerekli: WHM → MultiPHP Manager'dan bu alan adı için PHP 8.3 veya 8.4 seçilmeli.\n";
echo $missing ? "- Eksik eklentiler: " . implode(', ', $missing) . " (WHM → EasyApache 4 → PHP Extensions).\n" : "- Gerekli PHP eklentilerinin hepsi var.\n";
echo "\nBu çıktının tamamını kopyalayıp Claude'a gönder, sonra bu dosyayı sil.\n";

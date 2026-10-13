<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$BASE_URL = 'https://kaleidoscope.mindmove.cz';
$OUTPUT_ROOT = __DIR__ . '/kaleidoscope-offline';
$AUDIO_DIR = $OUTPUT_ROOT . '/audio';
$ASSET_DIR = $OUTPUT_ROOT . '/assets';
$CSS_DIR = $ASSET_DIR . '/css';
$JS_DIR = $ASSET_DIR . '/js';
$IMG_DIR = $ASSET_DIR . '/img';

$MANUAL_MP3_URLS = [
    'https://kaleidoscope.mindmove.cz/lq/VO00001.mp3',
    'https://kaleidoscope.mindmove.cz/lq/Music_00001.mp3',
    'https://kaleidoscope.mindmove.cz/lq/Music_00002.mp3',
    'https://kaleidoscope.mindmove.cz/lq/Music_00003.mp3',
    'https://kaleidoscope.mindmove.cz/lq/Music_00004.mp3',
    'https://kaleidoscope.mindmove.cz/lq/Music_00005.mp3',
    'https://kaleidoscope.mindmove.cz/lq/Music_00006.mp3',
];

$downloadedFiles = [];
$failedRewrites = [];
$missingMp3s = [];

function curlGet($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; OfflineDownloader/1.0)');

    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errNo !== 0 || $body === false) {
        return false;
    }

    if ($status >= 400) {
        return false;
    }

    return $body;
}

function ensureDir($path)
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Nepodařilo se vytvořit složku: ' . $path);
    }
}

function pathBaseName($url)
{
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null || $path === '') {
        return 'asset';
    }
    $name = pathinfo($path, PATHINFO_BASENAME);
    return $name !== '' ? $name : 'asset';
}

function resolveUrl($baseUrl, $url)
{
    if ($url === '' || preg_match('/^(data:|blob:|javascript:|mailto:|tel:)/i', $url)) {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    if (preg_match('/^\/\//', $url)) {
        return 'https:' . $url;
    }

    if (preg_match('/^\//', $url)) {
        return rtrim($baseUrl, '/') . $url;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

function relativePathFromRoot($targetPath, $rootPath)
{
    $targetPath = str_replace('\\', '/', $targetPath);
    $rootPath = str_replace('\\', '/', $rootPath);

    $rel = substr($targetPath, strlen($rootPath));
    $rel = ltrim($rel, '/');
    return $rel;
}

function fileNameFromUrl($url)
{
    $name = pathBaseName($url);
    if ($name === '' || $name === 'asset') {
        $name = 'asset-' . md5($url) . '.bin';
    }

    return $name;
}

function downloadBinaryFile($url, $targetFile, $label = 'soubor')
{
    global $downloadedFiles;

    $data = curlGet($url);
    if ($data === false) {
        return false;
    }

    ensureDir(dirname($targetFile));
    $written = file_put_contents($targetFile, $data);
    if ($written === false) {
        return false;
    }

    $downloadedFiles[] = $targetFile;
    return true;
}

function buildRewriteMapFromMp3s($mp3Urls)
{
    $map = [];
    foreach ($mp3Urls as $index => $url) {
        $local = 'audio/' . ($index + 1) . '.mp3';
        $origName = pathBaseName($url);
        $map[$url] = $local;
        $map[$origName] = $local;
    }
    return $map;
}

function rewriteNodesWithDom($html, $rewriteMap, $baseUrl)
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*[@src or @href]');

    if ($nodes === false) {
        return $html;
    }

    foreach ($nodes as $node) {
        foreach (['src', 'href'] as $attributeName) {
            if (!$node->hasAttribute($attributeName)) {
                continue;
            }

            $value = trim($node->getAttribute($attributeName));
            if ($value === '' || preg_match('/^(data:|javascript:|mailto:|tel:)/i', $value)) {
                continue;
            }

            $resolved = resolveUrl($baseUrl, $value);
            if ($resolved === '') {
                continue;
            }

            $localTarget = null;
            foreach ($rewriteMap as $source => $target) {
                if ($source === $resolved || basename($resolved) === $source) {
                    $localTarget = $target;
                    break;
                }
            }

            if ($localTarget !== null) {
                $node->setAttribute($attributeName, $localTarget);
            }
        }
    }

    return $dom->saveHTML();
}

function rewriteCssUrls($cssFile, $rewriteMap)
{
    $css = file_get_contents($cssFile);
    if ($css === false) {
        return false;
    }

    foreach ($rewriteMap as $source => $target) {
        if ($source === '') {
            continue;
        }

        $css = str_replace($source, $target, $css);
        $css = str_replace(rawurlencode($source), rawurlencode($target), $css);
        $css = str_replace(str_replace('https://kaleidoscope.mindmove.cz/', '', $source), $target, $css);
    }

    return file_put_contents($cssFile, $css) !== false;
}

function scanTextForMp3Urls($text, $baseUrl)
{
    $matches = [];
    preg_match_all('/https?:\/\/[^\s"\'\)>]+\.mp3(?:\?[^\s"\'\)>]+)?/i', $text, $matches);
    if (!empty($matches[0])) {
        return array_values(array_unique($matches[0]));
    }

    preg_match_all('/(?:[A-Za-z0-9_\-]+\/)*[A-Za-z0-9_\-]+\.mp3(?:\?[^\s"\'\)>]+)?/i', $text, $matches);
    $urls = [];
    if (!empty($matches[0])) {
        foreach ($matches[0] as $item) {
            $resolved = resolveUrl($baseUrl, $item);
            if ($resolved !== '') {
                $urls[] = $resolved;
            }
        }
    }

    return array_values(array_unique($urls));
}

function extractAssetUrlsFromHtml($html)
{
    preg_match_all('/(?:href|src)=["\']([^"\']+)["\']/i', $html, $matches);
    $items = [];
    if (!empty($matches[1])) {
        foreach ($matches[1] as $url) {
            $url = trim($url);
            if ($url === '' || preg_match('/^(javascript:|mailto:|tel:|data:)/i', $url)) {
                continue;
            }
            $items[] = $url;
        }
    }

    preg_match_all('/url\((?:["\']?)([^)"\']+)(?:["\']?)\)/i', $html, $cssMatches);
    if (!empty($cssMatches[1])) {
        foreach ($cssMatches[1] as $url) {
            $url = trim($url);
            if ($url === '' || preg_match('/^(javascript:|mailto:|tel:|data:)/i', $url)) {
                continue;
            }
            $items[] = $url;
        }
    }

    return array_values(array_unique($items));
}

function isSameSiteAsset($url, $baseUrl)
{
    $host = parse_url($baseUrl, PHP_URL_HOST);
    $targetHost = parse_url($url, PHP_URL_HOST);
    if ($targetHost === null) {
        return true;
    }
    return strtolower($targetHost) === strtolower($host);
}

function findLocalAssetKind($url)
{
    $lower = strtolower($url);
    if (strpos($lower, '.css') !== false) {
        return 'css';
    }
    if (strpos($lower, '.js') !== false) {
        return 'js';
    }
    if (preg_match('/\.(png|jpg|jpeg|gif|svg|webp|ico)(\?.*)?$/i', $url)) {
        return 'img';
    }
    return 'other';
}

function extForKind($kind)
{
    return match ($kind) {
        'css' => 'css',
        'js' => 'js',
        'img' => 'png',
        default => 'bin'
    };
}

function downloadPageAssets($html, $baseUrl, $outputRoot, &$failedRewrites, &$mp3Urls)
{
    $rewriteMap = [];
    $pageAssets = extractAssetUrlsFromHtml($html);
    $discoveredMp3Urls = scanTextForMp3Urls($html, $baseUrl);
    $mp3Urls = array_values(array_unique(array_merge($mp3Urls, array_intersect($discoveredMp3Urls, $mp3Urls))));

    foreach ($pageAssets as $assetUrl) {
        $raw = trim($assetUrl);
        if ($raw === '') {
            continue;
        }

        $resolved = resolveUrl($baseUrl, $raw);
        if ($resolved === '' || !isSameSiteAsset($resolved, $baseUrl)) {
            continue;
        }

        $kind = findLocalAssetKind($resolved);
        if ($kind === 'other') {
            continue;
        }

        $basename = fileNameFromUrl($resolved);
        $destinationDir = $outputRoot . '/assets/' . $kind . 's';
        if ($kind === 'img') {
            $destinationDir = $outputRoot . '/assets/img';
        }
        if ($kind === 'css') {
            $destinationDir = $outputRoot . '/assets/css';
        }
        if ($kind === 'js') {
            $destinationDir = $outputRoot . '/assets/js';
        }

        ensureDir($destinationDir);
        $targetFile = $destinationDir . '/' . $basename;

        if (file_exists($targetFile)) {
            $rewriteMap[$resolved] = str_replace($outputRoot . '/', '', $targetFile);
            continue;
        }

        if (!downloadBinaryFile($resolved, $targetFile)) {
            $failedRewrites[] = $resolved;
            continue;
        }

        $rewriteMap[$resolved] = str_replace($outputRoot . '/', '', $targetFile);
    }

    foreach ($rewriteMap as $oldUrl => $newPath) {
        $html = str_replace($oldUrl, $newPath, $html);
    }

    $cssFiles = glob($outputRoot . '/assets/css/*.css');
    if (is_array($cssFiles)) {
        foreach ($cssFiles as $cssFile) {
            rewriteCssUrls($cssFile, $rewriteMap);
        }
    }

    $jsFiles = glob($outputRoot . '/assets/js/*.js');
    if (is_array($jsFiles)) {
        foreach ($jsFiles as $jsFile) {
            $jsContent = file_get_contents($jsFile);
            if ($jsContent !== false) {
                $discoveredJsMp3Urls = scanTextForMp3Urls($jsContent, $baseUrl);
                $mp3Urls = array_values(array_unique(array_merge($mp3Urls, array_intersect($discoveredJsMp3Urls, $mp3Urls))));
            }
        }
    }

    return $html;
}

function fetchHtmlPage($url)
{
    $html = curlGet($url);
    if ($html === false) {
        throw new RuntimeException('Nepodařilo se stáhnout HTML stránku: ' . $url);
    }
    return $html;
}

function finalDownloadSummary($missingMp3s, $failedRewrites, $filesCount)
{
    echo "\n=== Shrnutí ===\n";
    echo 'Staženo celkem: ' . $filesCount . " souborů\n";
    echo 'Chybějící MP3: ' . (empty($missingMp3s) ? 'žádné' : implode(', ', $missingMp3s)) . "\n";
    echo 'Nepodařilo se přepsat: ' . (empty($failedRewrites) ? 'žádné' : implode(', ', $failedRewrites)) . "\n";
    echo "\nKopírovat do telefonu:\n";
    echo "1. Zkopírujte složku kaleidoscope-offline/ do telefonu.\n";
    echo "2. Otevřete index.html v prohlížeči nebo spusťte lokální PHP server.\n";
    echo "3. Pokud prohlížeč blokuje audio přes file://, otevřete stránku přes lokální HTTP server.\n";
    echo "\nPříklad pro lokální server:\n";
    echo "php -S 0.0.0.0:8000 -t kaleidoscope-offline\n";
    echo "Potom otevřete http://localhost:8000/\n";
}

try {
    ensureDir($OUTPUT_ROOT);
    ensureDir($AUDIO_DIR);
    ensureDir($CSS_DIR);
    ensureDir($JS_DIR);
    ensureDir($IMG_DIR);

    foreach (glob($AUDIO_DIR . '/*') as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    $html = fetchHtmlPage($BASE_URL);
    $discoveredMp3Urls = scanTextForMp3Urls($html, $BASE_URL);
    $mp3UrlsToDownload = array_values(array_unique(array_merge($MANUAL_MP3_URLS, array_intersect($discoveredMp3Urls, $MANUAL_MP3_URLS))));
    $mp3Map = buildRewriteMapFromMp3s($mp3UrlsToDownload);

    $missingMp3s = [];
    $savedMp3s = [];

    $html = downloadPageAssets($html, $BASE_URL, $OUTPUT_ROOT, $failedRewrites, $mp3UrlsToDownload);

    foreach ($mp3UrlsToDownload as $index => $mp3Url) {
        $targetFile = $AUDIO_DIR . '/' . ($index + 1) . '.mp3';

        if (!downloadBinaryFile($mp3Url, $targetFile, 'MP3')) {
            $missingMp3s[] = $mp3Url;
            continue;
        }

        $savedMp3s[] = $targetFile;
    }

    $mp3Map = buildRewriteMapFromMp3s($mp3UrlsToDownload);
    $html = rewriteNodesWithDom($html, $mp3Map, $BASE_URL);

    $iframeFallback = "\n<script>\n(function(){\n  if (location.protocol === 'file:') {\n    var box = document.createElement('div');\n    box.style.position = 'fixed';\n    box.style.left = '12px';\n    box.style.right = '12px';\n    box.style.bottom = '12px';\n    box.style.zIndex = '99999';\n    box.style.background = '#111';\n    box.style.color = '#fff';\n    box.style.padding = '12px 14px';\n    box.style.borderRadius = '8px';\n    box.style.fontFamily = 'sans-serif';\n    box.style.fontSize = '14px';\n    box.style.lineHeight = '1.5';\n    box.style.boxShadow = '0 12px 24px rgba(0,0,0,0.25)';\n    box.innerHTML = 'Offline verze je načtena z lokální složky. Pro plnou funkci přehrajte stránku přes lokální HTTP server. Příklad: <code>php -S 0.0.0.0:8000 -t kaleidoscope-offline</code> a následně otevřete <code>http://localhost:8000/</code>.';\n    document.body.appendChild(box);\n  }\n})();\n</script>\n";

    if (strpos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $iframeFallback . '</body>', $html);
    } else {
        $html .= $iframeFallback;
    }

    $indexPath = $OUTPUT_ROOT . '/index.html';
    if (file_put_contents($indexPath, $html) === false) {
        throw new RuntimeException('Nepodařilo se uložit index.html');
    }

    $allFiles = array_merge($savedMp3s, $downloadedFiles, [$indexPath]);
    $fileCount = count(array_unique($allFiles));

    echo "\nStahování dokončeno.\n";
    finalDownloadSummary($missingMp3s, $failedRewrites, $fileCount);
} catch (Throwable $e) {
    echo 'Chyba: ' . $e->getMessage() . "\n";
    exit(1);
}

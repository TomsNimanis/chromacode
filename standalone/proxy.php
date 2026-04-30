<?php
/**
 * ChromaCode Favicon Proxy
 * Fetches favicons server-side to bypass CORS restrictions.
 * Deploy alongside index.html on qr.itlinden.lv
 * 
 * Usage: /proxy.php?domain=lego.com
 *        /proxy.php?url=https://example.com/logo.png
 */

header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=86400');

// Favicon by domain
if (!empty($_GET['domain'])) {
    $domain = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_GET['domain']);
    
    $sources = [
        "https://t1.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&url=https://{$domain}&size=256",
        "https://www.google.com/s2/favicons?domain={$domain}&sz=256",
        "https://icons.duckduckgo.com/ip3/{$domain}.ico",
        "https://{$domain}/apple-touch-icon.png",
        "https://{$domain}/favicon.ico",
    ];
    
    foreach ($sources as $url) {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $img = @file_get_contents($url, false, $ctx);
        if ($img && strlen($img) > 100) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($img);
            if (strpos($mime, 'image') !== false) {
                header('Content-Type: ' . $mime);
                echo $img;
                exit;
            }
        }
    }
    
    http_response_code(404);
    echo 'Favicon not found';
    exit;
}

// Proxy arbitrary image URL
if (!empty($_GET['url'])) {
    $url = filter_var($_GET['url'], FILTER_VALIDATE_URL);
    if (!$url) { http_response_code(400); echo 'Invalid URL'; exit; }
    
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $img = @file_get_contents($url, false, $ctx);
    if ($img && strlen($img) > 100) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($img);
        if (strpos($mime, 'image') !== false) {
            header('Content-Type: ' . $mime);
            echo $img;
            exit;
        }
    }
    
    http_response_code(404);
    echo 'Image not found';
    exit;
}

http_response_code(400);
echo 'Provide ?domain= or ?url= parameter';

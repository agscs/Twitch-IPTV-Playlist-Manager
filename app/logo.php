<?php
// app/logo.php - Logo serving and caching endpoint

ob_start();

$channel = strtolower(trim($_GET['channel'] ?? ''));
$offline = isset($_GET['offline']) && $_GET['offline'] == 1;
$viewers = isset($_GET['viewers']) ? (int)$_GET['viewers'] : 0;
$defaultLogo = 'https://static-cdn.jtvnw.net/user-default-pictures-uv/ce57700a-def9-11e9-842d-784f43822e80-profile_image-300x300.png';

if (empty($channel)) {
    header("Location: $defaultLogo");
    exit();
}

// Require configuration bootstrap
require_once __DIR__ . '/init.php';
$logoUrl = $channelsConfig[$channel]['logo'] ?? null;

if (!$logoUrl) {
    header("Location: $defaultLogo");
    exit();
}

// Pre-cache or load cached logo image
cacheChannelLogo($channel, $logoUrl, $offline, $viewers);

// Construct cache file path relative to root
$cacheDir = dirname(__DIR__) . '/storage/logo_cache';
$cacheFile = $cacheDir . '/' . $channel . ($offline ? '_offline' : '_online_counter') . '.png';

if (file_exists($cacheFile)) {
    header('Content-Type: image/png');
    readfile($cacheFile);
    exit();
} else {
    // Graceful fallback to raw logo URL
    header("Location: $logoUrl");
    exit();
}

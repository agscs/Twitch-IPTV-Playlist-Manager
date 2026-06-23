<?php
// app/streamsM3U.php - Twitch HLS stream playlist resolver

require_once __DIR__ . '/init.php';

$clientId = getTwitchClientId();
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 OPR/131.0.0.0';

// Get input from $_GET or CLI
parse_str(implode('&', array_slice($argv ?? [], 1)), $cliArgs);
$params = array_merge($_GET, $cliArgs);

// Extract channel from PATH_INFO (e.g. /hollywoodbob247.m3u8) or parameters
$channel = null;
if (!empty($_SERVER['PATH_INFO'])) {
    $path = trim($_SERVER['PATH_INFO'], '/');
    $parts = explode('.', $path);
    if (!empty($parts[0])) {
        $channel = $parts[0];
    }
}
if (!$channel) {
    $channel = $params['channel'] ?? null;
}

$format = $params['format'] ?? null;
$isValidChannel = is_string($channel) && preg_match('/^[a-zA-Z0-9_]{4,25}$/', $channel);

if (!$isValidChannel) {
    $error = 'Invalid channel name. Channel name must be 4-25 characters long and can only contain letters, numbers, and underscores.';
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode([ 'success' => false, 'channel' => $channel, 'error' => $error ]);
    }
    else echo "Error: " . $error;
    exit();
}

// Resolve specific quality if requested (defaults to global settings quality, or '1080p')
$defaultQuality = $settings['stream_quality'] ?? '1080p';
$quality = isset($params['quality']) ? strtolower(trim($params['quality'])) : $defaultQuality;

$resolvedUrl = resolveTwitchStreamUrl($channel, $quality, $clientId);

if ($format === 'json') {
    header('Content-Type: application/json');
    if ($resolvedUrl) {
        echo json_encode([ 'success' => true, 'channel' => $channel, 'url' => $resolvedUrl ]);
    } else {
        echo json_encode([ 'success' => false, 'channel' => $channel, 'error' => 'Channel does not exist or stream is not available/forbidden from your location.' ]);
    }
    exit();
}

if ($resolvedUrl) {
    header("Location: $resolvedUrl", true, 302);
} else {
    header("HTTP/1.0 404 Not Found");
    echo "Error: Stream playlist content could not be retrieved. Channel may be offline.";
}
ob_end_flush();

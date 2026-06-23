<?php
require_once __DIR__ . '/app/init.php';

// Filter enabled channels
$enabledChannels = [];
foreach ($channelsConfig as $chanName => $data) {
    if (!empty($data['enabled'])) {
        $enabledChannels[] = $chanName;
    }
}

// Generate base streamsM3U.php URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$dir = dirname($_SERVER['SCRIPT_NAME']);
$dir = str_replace('\\', '/', $dir);
if ($dir !== '/') {
    $dir = rtrim($dir, '/');
}
$streamsM3uUrl = $protocol . $host . $dir . '/app/streamsM3U.php';

// Load stream quality setting
$streamQuality = $settings['stream_quality'] ?? '1080p';
$qualitySuffix = '?quality=' . urlencode($streamQuality);

// Set headers based on format
if (isset($_GET['download']) && $_GET['download'] == 1) {
    header('Content-Type: application/x-mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="twitch_channels.m3u"');
} else {
    header('Content-Type: text/plain; charset=utf-8');
}

// Print M3U Header
$defaultEpg = $protocol . $host . $dir . '/epg.php';
$epgUrl = isset($_GET['epg']) ? $_GET['epg'] : $defaultEpg;
$epgUrl = str_replace(["\r", "\n", '"'], '', $epgUrl);
echo "#EXTM3U x-tvg-url=\"{$epgUrl}\"\n";

if (!empty($enabledChannels)) {
    // Fetch live data for grouping
    $clientId = getTwitchClientId();
    $statuses = fetchChannelsStatus($enabledChannels, $clientId);
    // Save metadata back to channels.json
    $configUpdated = false;
    foreach ($statuses as $login => $info) {
        if ($info['exists']) {
            if (!isset($channelsConfig[$login]['displayName']) || $channelsConfig[$login]['displayName'] !== $info['displayName'] ||
                !isset($channelsConfig[$login]['logo']) || $channelsConfig[$login]['logo'] !== $info['profileImage']) {
                $channelsConfig[$login]['displayName'] = $info['displayName'];
                $channelsConfig[$login]['logo'] = $info['profileImage'];
                $configUpdated = true;
            }
        }
    }
    if ($configUpdated) {
        @file_put_contents($channelsFile, json_encode($channelsConfig, JSON_PRETTY_PRINT));
    }
    // Dynamic logo URL generator
    $logoPhpUrl = $protocol . $host . $dir . '/app/logo.php';
    // Sort channels: Live channels first, sorted by game name, then offline
    $liveChannels = [];
    $offlineChannels = [];
    foreach ($enabledChannels as $chanName) {
        $login = strtolower($chanName);
        $status = $statuses[$login] ?? [
            'exists' => false,
            'login' => $login,
            'displayName' => $chanName,
            'profileImage' => 'https://static-cdn.jtvnw.net/user-default-pictures-uv/ce57700a-def9-11e9-842d-784f43822e80-profile_image-300x300.png',
            'isLive' => false,
            'title' => '',
            'viewers' => 0,
            'game' => 'Offline'
        ];
        if (!$status['exists']) {
            continue; // Skip channels that do not exist or have 0 followers
        }
        if ($status['isLive']) {
            $liveChannels[] = $status;
        } else {
            $offlineChannels[] = $status;
        }
    }
    // Sort live channels by game name, then display name
    usort($liveChannels, function($a, $b) {
        $gameCmp = strcmp($a['game'], $b['game']);
        if ($gameCmp !== 0) return $gameCmp;
        return strcmp($a['displayName'], $b['displayName']);
    });
    // Sort offline channels by display name
    usort($offlineChannels, function($a, $b) {
        return strcmp($a['displayName'], $b['displayName']);
    });
    $chno = 1;
    // Print live streams first
    foreach ($liveChannels as $chan) {
        $login = $chan['login'];
        $logo = $logoPhpUrl . '?channel=' . urlencode($login) . '&viewers=' . $chan['viewers'];
        $displayName = $chan['displayName'];
        $game = $chan['game'];
        $viewers = number_format($chan['viewers']);
        $titleClean = str_replace(['"', "\r", "\n"], ["'", " ", " "], $chan['title']);
        // M3U output entry
        echo "#EXTINF:-1 tvg-id=\"{$login}\" tvg-name=\"{$displayName} ({$viewers})\" tvg-logo=\"{$logo}\" group-title=\"{$game}\" tvg-chno=\"{$chno}\" tvg-language=\"en\",{$displayName} ({$viewers}) - playing {$game}: {$titleClean}\n";
        echo "{$streamsM3uUrl}/{$login}.m3u8{$qualitySuffix}\n";
        $chno++;
    }
    // Print offline streams
    foreach ($offlineChannels as $chan) {
        $login = $chan['login'];
        $logo = $logoPhpUrl . '?channel=' . urlencode($login) . '&offline=1';
        $displayName = $chan['displayName'];
        // M3U output entry grouped as Offline
        echo "#EXTINF:-1 tvg-id=\"{$login}\" tvg-name=\"{$displayName}\" tvg-logo=\"{$logo}\" group-title=\"Offline\" tvg-chno=\"{$chno}\" tvg-language=\"en\",{$displayName} (Offline)\n";
        echo "{$streamsM3uUrl}/{$login}.m3u8{$qualitySuffix}\n";
        $chno++;
    }
}
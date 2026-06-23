<?php
// app/dashboard.php - Dashboard logic, AJAX endpoints, and UI view

// OAuth Callback handling from Twitch in browser
if (isset($_GET['code'])) {
    $code = trim($_GET['code']);
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $creds = $settings['twitch_creds'] ?? [];
        if (!empty($creds['client_id']) && !empty($creds['client_secret'])) {
            $redirectUri = getTwitchRedirectUri();
            
            $ch = curl_init('https://id.twitch.tv/oauth2/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'client_id' => $creds['client_id'],
                    'client_secret' => $creds['client_secret'],
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUri
                ])
            ]);
            $tokenResponse = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            if (!empty($tokenResponse['access_token'])) {
                $creds['access_token'] = $tokenResponse['access_token'];
                $creds['refresh_token'] = $tokenResponse['refresh_token'] ?? '';
                
                $ch = curl_init('https://api.twitch.tv/helix/users');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Client-Id: " . $creds['client_id'],
                        "Authorization: Bearer " . $creds['access_token']
                    ]
                ]);
                $userResponse = json_decode(curl_exec($ch), true);
                curl_close($ch);
                
                if (!empty($userResponse['data'][0]['id'])) {
                    $creds['user_id'] = $userResponse['data'][0]['id'];
                    $creds['username'] = $userResponse['data'][0]['display_name'];
                    
                    $settings['twitch_creds'] = $creds;
                    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
                    
                    syncFollowedChannels($creds, $settingsFile, $channelsFile);
                }
            }
        }
    }
    
    // Redirect to clean dashboard URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    header("Location: " . $protocol . $host . $script);
    exit();
}

// Handle AJAX Post Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle') {
        $channel = strtolower(trim($_POST['channel'] ?? ''));
        $enabled = ($_POST['enabled'] ?? 'false') === 'true';
        $channels = [];
        if (file_exists($channelsFile)) {
            $channels = json_decode(file_get_contents($channelsFile), true) ?: [];
        }
        if (isset($channels[$channel])) {
            $channels[$channel]['enabled'] = $enabled;
            file_put_contents($channelsFile, json_encode($channels, JSON_PRETTY_PRINT));
            generateStaticM3UPlaylist($channels, $settings);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Channel not found in list']);
        }
        exit();
    }
    
    if ($action === 'toggle_all') {
        $enabled = ($_POST['enabled'] ?? 'false') === 'true';
        $channels = [];
        if (file_exists($channelsFile)) {
            $channels = json_decode(file_get_contents($channelsFile), true) ?: [];
        }
        foreach ($channels as $chan => $data) {
            $channels[$chan]['enabled'] = $enabled;
        }
        file_put_contents($channelsFile, json_encode($channels, JSON_PRETTY_PRINT));
        generateStaticM3UPlaylist($channels, $settings);
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($action === 'delete') {
        $channel = strtolower(trim($_POST['channel'] ?? ''));
        $channels = [];
        if (file_exists($channelsFile)) {
            $channels = json_decode(file_get_contents($channelsFile), true) ?: [];
        }
        if (isset($channels[$channel])) {
            unset($channels[$channel]);
            file_put_contents($channelsFile, json_encode($channels, JSON_PRETTY_PRINT));
            generateStaticM3UPlaylist($channels, $settings);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Channel not found in list']);
        }
        exit();
    }
    
    if ($action === 'add') {
        $channel = strtolower(trim($_POST['channel'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_]{4,25}$/', $channel)) {
            echo json_encode(['success' => false, 'error' => 'Invalid username. Must be 4-25 characters (letters, numbers, underscores).']);
            exit();
        }
        $channels = [];
        if (file_exists($channelsFile)) {
            $channels = json_decode(file_get_contents($channelsFile), true) ?: [];
        }
        if (isset($channels[$channel])) {
            echo json_encode(['success' => false, 'error' => 'Channel already exists in your list.']);
            exit();
        }
        // Verify channel existence with Twitch GQL
        $clientId = getTwitchClientId();
        $status = fetchChannelsStatus([$channel], $clientId);
        if (empty($status[$channel]) || !$status[$channel]['exists']) {
            echo json_encode(['success' => false, 'error' => 'Channel does not exist on Twitch.']);
            exit();
        }
        $channels[$channel] = [
            'enabled' => true,
            'displayName' => $status[$channel]['displayName'],
            'logo' => $status[$channel]['profileImage']
        ];
        file_put_contents($channelsFile, json_encode($channels, JSON_PRETTY_PRINT));
        generateStaticM3UPlaylist($channels, $settings);
        echo json_encode(['success' => true, 'displayName' => $status[$channel]['displayName']]);
        exit();
    }
    
    if ($action === 'save_creds') {
        $clientId = trim($_POST['client_id'] ?? '');
        $clientSecret = trim($_POST['client_secret'] ?? '');
        if (empty($clientId) || empty($clientSecret)) {
            echo json_encode(['success' => false, 'error' => 'Client ID and Client Secret are required.']);
            exit();
        }
        $creds = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'access_token' => '',
            'refresh_token' => '',
            'user_id' => '',
            'username' => ''
        ];
        $settings = [];
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        }
        $settings['twitch_creds'] = $creds;
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
        
        $redirectUri = getTwitchRedirectUri();
        $authUrl = "https://id.twitch.tv/oauth2/authorize"
                 . "?client_id=" . urlencode($clientId)
                 . "&redirect_uri=" . urlencode($redirectUri)
                 . "&response_type=code"
                 . "&scope=" . urlencode('user:read:follows');
        echo json_encode(['success' => true, 'auth_url' => $authUrl]);
        exit();
    }
    
    if ($action === 'disconnect') {
        $settings = [];
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        }
        if (isset($settings['twitch_creds'])) {
            unset($settings['twitch_creds']);
            file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
        }
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($action === 'sync') {
        $settings = [];
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        }
        $creds = $settings['twitch_creds'] ?? [];
        if (empty($creds['client_id'])) {
            echo json_encode(['success' => false, 'error' => 'Twitch credentials not configured.']);
            exit();
        }
        if (empty($creds['access_token'])) {
            echo json_encode(['success' => false, 'error' => 'Not authenticated with Twitch.']);
            exit();
        }
        if (syncFollowedChannels($creds, $settingsFile, $channelsFile)) {
            $channels = json_decode(file_get_contents($channelsFile), true) ?: [];
            generateStaticM3UPlaylist($channels, $settings);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to sync followed channels. Check credentials or try re-connecting.']);
        }
        exit();
    }
    
    if ($action === 'refresh_channel') {
        $channel = strtolower(trim($_POST['channel'] ?? ''));
        if (empty($channel)) {
            echo json_encode(['success' => false, 'error' => 'Channel name is required']);
            exit();
        }
        $channels = [];
        if (file_exists($channelsFile)) {
            $channels = json_decode(file_get_contents($channelsFile), true) ?: [];
        }
        if (!isset($channels[$channel])) {
            echo json_encode(['success' => false, 'error' => 'Channel not found in list']);
            exit();
        }
        $clientId = getTwitchClientId();
        $status = fetchChannelsStatus([$channel], $clientId);
        if (empty($status[$channel]) || !$status[$channel]['exists']) {
            echo json_encode(['success' => false, 'error' => 'Channel does not exist on Twitch.']);
            exit();
        }
        $channels[$channel]['displayName'] = $status[$channel]['displayName'];
        $channels[$channel]['logo'] = $status[$channel]['profileImage'];
        file_put_contents($channelsFile, json_encode($channels, JSON_PRETTY_PRINT));
        $cacheDir = dirname(__DIR__) . '/storage/logo_cache';
        $onlineCache = $cacheDir . '/' . $channel . '_online_counter.png';
        $offlineCache = $cacheDir . '/' . $channel . '_offline.png';
        if (file_exists($onlineCache)) @unlink($onlineCache);
        if (file_exists($offlineCache)) @unlink($offlineCache);
        echo json_encode([
            'success' => true, 
            'displayName' => $status[$channel]['displayName'],
            'logo' => $status[$channel]['profileImage']
        ]);
        exit();
    }
    
    if ($action === 'sync_selected_logos') {
        $channels = [];
        if (file_exists($channelsFile)) {
            $channels = json_decode(file_get_contents($channelsFile), true) ?: [];
        }
        $selectedChans = [];
        foreach ($channels as $chanName => $data) {
            if (!empty($data['enabled'])) {
                $selectedChans[] = $chanName;
            }
        }
        if (empty($selectedChans)) {
            echo json_encode(['success' => false, 'error' => 'No channels are selected. Please select at least one channel.']);
            exit();
        }
        $clientId = getTwitchClientId();
        $statuses = fetchChannelsStatus($selectedChans, $clientId);
        $configUpdated = false;
        $syncedCount = 0;
        foreach ($statuses as $login => $info) {
            if ($info['exists']) {
                $logoUrl = $info['profileImage'];
                $displayName = $info['displayName'];
                if (!isset($channels[$login]['displayName']) || $channels[$login]['displayName'] !== $displayName ||
                    !isset($channels[$login]['logo']) || $channels[$login]['logo'] !== $logoUrl) {
                    $channels[$login]['displayName'] = $displayName;
                    $channels[$login]['logo'] = $logoUrl;
                    $configUpdated = true;
                }
                // Pre-cache offline logo (gray ring, grayscaled logo, offline pill)
                cacheChannelLogo($login, $logoUrl, true);
                // Pre-cache online logo if channel is live (red ring, colored logo, viewer count pill)
                if ($info['isLive']) {
                    cacheChannelLogo($login, $logoUrl, false, $info['viewers']);
                }
                $syncedCount++;
            }
        }
        if ($configUpdated) {
            file_put_contents($channelsFile, json_encode($channels, JSON_PRETTY_PRINT));
        }
        generateStaticM3UPlaylist($channels, $settings);
        echo json_encode([
            'success' => true,
            'count' => $syncedCount
        ]);
        exit();
    }
    
    if ($action === 'clear_cache') {
        $cacheDir = dirname(__DIR__) . '/storage/logo_cache';
        $files = glob($cacheDir . '/*.png');
        $deletedCount = 0;
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (@unlink($file)) {
                        $deletedCount++;
                    }
                }
            }
        }
        echo json_encode(['success' => true, 'count' => $deletedCount]);
        exit();
    }
    
    if ($action === 'save_settings') {
        $quality = trim($_POST['stream_quality'] ?? '1080p');
        $validQualities = ['1080p', '720p', '480p', '360p', '160p', 'adaptive'];
        if (!in_array($quality, $validQualities)) {
            $quality = '1080p';
        }
        $settings['stream_quality'] = $quality;
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
        $channels = [];
        if (file_exists($channelsFile)) {
            $channels = json_decode(file_get_contents($channelsFile), true) ?: [];
        }
        generateStaticM3UPlaylist($channels, $settings);
        echo json_encode(['success' => true]);
        exit();
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit();
}

// Load Twitch credentials
$creds = $settings['twitch_creds'] ?? [];
$isTwitchConnected = !empty($creds['access_token']) && !empty($creds['username']);
$twitchUsername = $creds['username'] ?? '';
$streamQuality = $settings['stream_quality'] ?? '1080p';

$channelNames = array_keys($channelsConfig);
$totalChannels = count($channelNames);
$selectedCount = 0;
foreach ($channelsConfig as $c) {
    if (!empty($c['enabled'])) {
        $selectedCount++;
    }
}

// Fetch states
$clientId = getTwitchClientId();
$statuses = fetchChannelsStatus($channelNames, $clientId);

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
    generateStaticM3UPlaylist($channelsConfig, $settings);
}

// Group streams
$liveGroups = [];
$offlineStreams = [];
$liveCount = 0;

foreach ($statuses as $login => $info) {
    $isEnabled = $channelsConfig[$login]['enabled'] ?? false;
    $info['enabled'] = $isEnabled;
    if ($info['isLive']) {
        $liveCount++;
        $game = $info['game'] ?: 'Just Chatting';
        if (!isset($liveGroups[$game])) {
            $liveGroups[$game] = [];
        }
        $liveGroups[$game][] = $info;
    } else {
        $offlineStreams[] = $info;
    }
}

// Sort games alphabetically, then sort channels in those games
ksort($liveGroups);
foreach ($liveGroups as $game => &$chans) {
    usort($chans, function($a, $b) {
        return strcmp($a['displayName'], $b['displayName']);
    });
}
unset($chans);

// Sort offline channels alphabetically
usort($offlineStreams, function($a, $b) {
    return strcmp($a['displayName'], $b['displayName']);
});

// Dynamic base URLs (playlist.php is in the root, so it's directly accessible relative to this script host)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$dir = dirname($_SERVER['SCRIPT_NAME']);
$dir = str_replace('\\', '/', $dir);
if ($dir !== '/') {
    $dir = rtrim($dir, '/');
}
$playlistUrl = $protocol . $host . $dir . '/playlist.php';
$epgUrl = $protocol . $host . $dir . '/epg.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Twitch IPTV Playlist Manager</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Link Stylesheet -->
    <link rel="stylesheet" href="assets/index.css">
</head>
<body>
    
    <div class="app-container">
        
        <!-- Sidebar Controls -->
        <aside class="sidebar">
            <div class="brand" style="margin-bottom: 2rem;">
                <i class="fa-brands fa-twitch brand-icon"></i>
                <h1 style="font-size: 1.3rem; line-height: 1.2;">Twitch IPTV<span style="display: block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--color-text-muted); margin-top: 0.15rem;">Control Panel</span></h1>
            </div>
            
            <!-- Twitch API Sync Widget -->
            <div class="playlist-widget" style="margin-bottom: 1.5rem; background: rgba(145, 70, 255, 0.05); border-color: rgba(145, 70, 255, 0.25);">
                <span class="section-title"><i class="fa-solid fa-sync" style="color: var(--color-primary);"></i> Twitch Follows Sync</span>
                <?php if ($isTwitchConnected): ?>
                    <p style="font-size: 0.8rem; margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                        <span style="display:inline-block; width:8px; height:8px; background:var(--color-success); border-radius:50%; box-shadow:0 0 6px var(--color-success);"></span>
                        Connected: <strong><?php echo htmlspecialchars($twitchUsername); ?></strong>
                    </p>
                    <p style="font-size: 0.7rem; color: var(--color-text-muted); margin-bottom: 0.75rem;">
                        Status: <span style="color: var(--color-success); font-weight: 600;">Authorized</span>
                    </p>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-primary" onclick="syncTwitchFollows()" style="flex: 1; padding: 0.5rem 0.75rem; font-size: 0.85rem;" id="btn-sync-follows">
                            <i class="fa-solid fa-rotate"></i> Sync Follows
                        </button>
                        <button class="btn btn-secondary" onclick="disconnectTwitch()" style="padding: 0.5rem 0.75rem; font-size: 0.85rem;" title="Disconnect from Twitch">
                            <i class="fa-solid fa-unlink"></i>
                        </button>
                    </div>
                <?php else: ?>
                    <p style="font-size: 0.75rem; color: var(--color-text-muted); margin-bottom: 0.75rem; line-height: 1.3;">
                        Connect your Twitch Developer credentials to automatically import and refresh followed channels.
                    </p>
                    <button class="btn btn-secondary" onclick="document.getElementById('twitch-config-form').style.display='block'; document.getElementById('twitch-helper-steps').style.display='block'; this.style.display='none';" style="width: 100%; padding: 0.5rem 0.75rem; font-size: 0.85rem; margin-bottom: 0.5rem;" id="btn-show-config">
                        <i class="fa-solid fa-plug"></i> Connect Twitch App
                    </button>
                    
                    <!-- Step-by-Step Help Guide -->
                    <div id="twitch-helper-steps" style="display: none; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.75rem; margin-bottom: 0.75rem;">
                        <span style="font-size: 0.75rem; font-weight: 700; color: var(--color-text-main); display: block; margin-bottom: 0.4rem;"><i class="fa-solid fa-circle-info" style="color: var(--color-primary);"></i> How to get credentials:</span>
                        <ol style="font-size: 0.7rem; color: var(--color-text-muted); padding-left: 1rem; line-height: 1.45; display: flex; flex-direction: column; gap: 0.35rem;">
                            <li>Log in to the <a href="https://dev.twitch.tv/console" target="_blank" style="color: var(--color-primary); text-decoration: none;">Twitch Developer Console</a>.</li>
                            <li>Register a <strong>New Application</strong> (e.g. "My IPTV Playlist").</li>
                            <li>Set the <strong>OAuth Redirect URL</strong> exactly to:<br>
                                <div style="display: flex; gap: 0.25rem; margin-top: 0.2rem; align-items: center;">
                                    <code id="redirect-uri-text" style="background: rgba(0,0,0,0.4); padding: 2px 4px; border-radius: 4px; border: 1px solid var(--border-color); font-family: monospace; font-size: 0.65rem; color: var(--color-primary); word-break: break-all; flex: 1;"><?php echo htmlspecialchars(getTwitchRedirectUri()); ?></code>
                                    <button type="button" class="btn-copy" onclick="copyRedirectUri()" style="padding: 2px 6px; font-size: 0.6rem; border-radius: 4px; height: 18px; border: none; background: rgba(255,255,255,0.05); color: var(--color-text-main); cursor: pointer;" title="Copy to clipboard"><i class="fa-regular fa-copy"></i></button>
                                </div>
                            </li>
                            <li>Select Category <strong>Application Integration</strong> and click <strong>Create</strong>.</li>
                            <li>Copy the <strong>Client ID</strong> and generate a new <strong>Client Secret</strong> to copy.</li>
                        </ol>
                    </div>
                    
                    <form id="twitch-config-form" style="display: none;" onsubmit="connectTwitch(event)">
                        <input type="text" id="twitch-client-id" class="input-text" placeholder="Client ID" required style="width:100%; margin-bottom: 0.5rem; padding: 0.4rem 0.6rem; font-size: 0.8rem;">
                        <input type="password" id="twitch-client-secret" class="input-text" placeholder="Client Secret" required style="width:100%; margin-bottom: 0.75rem; padding: 0.4rem 0.6rem; font-size: 0.8rem;">
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="submit" class="btn btn-primary" style="flex: 1; padding: 0.4rem; font-size: 0.8rem;" id="btn-connect-submit">Connect App</button>
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('twitch-config-form').style.display='none'; document.getElementById('twitch-helper-steps').style.display='none'; document.getElementById('btn-show-config').style.display='block';" style="padding: 0.4rem; font-size: 0.8rem;">Cancel</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            
            <!-- Stream Settings Widget -->
            <div class="playlist-widget" style="margin-bottom: 1.5rem;">
                <span class="section-title"><i class="fa-solid fa-sliders" style="color: var(--color-primary);"></i> Stream Quality</span>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="select-quality" style="font-size: 0.75rem; color: var(--color-text-muted); display: block; margin-bottom: 0.4rem;">Preferred Stream Quality</label>
                    <select id="select-quality" class="input-text" onchange="saveStreamQuality(this.value)" style="width: 100%; padding: 0.4rem 0.6rem; font-size: 0.8rem; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); color: var(--color-text); border-radius: 4px; cursor: pointer;">
                        <option value="1080p" <?php echo $streamQuality === '1080p' ? 'selected' : ''; ?>>1080p (Source / Best)</option>
                        <option value="720p" <?php echo $streamQuality === '720p' ? 'selected' : ''; ?>>720p (High)</option>
                        <option value="480p" <?php echo $streamQuality === '480p' ? 'selected' : ''; ?>>480p (Medium)</option>
                        <option value="360p" <?php echo $streamQuality === '360p' ? 'selected' : ''; ?>>360p (Low)</option>
                        <option value="160p" <?php echo $streamQuality === '160p' ? 'selected' : ''; ?>>160p (Mobile)</option>
                        <option value="adaptive" <?php echo $streamQuality === 'adaptive' ? 'selected' : ''; ?>>Adaptive (Multi-quality)</option>
                    </select>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="val" id="stat-total"><?php echo $totalChannels; ?></span>
                    <span class="lbl">Channels</span>
                </div>
                <div class="stat-card">
                    <span class="val" id="stat-live" style="color: var(--color-success);"><?php echo $liveCount; ?></span>
                    <span class="lbl">Live</span>
                </div>
                <div class="stat-card">
                    <span class="val" id="stat-selected"><?php echo $selectedCount; ?></span>
                    <span class="lbl">Selected</span>
                </div>
            </div>
            
            <!-- Playlists & EPG URLs -->
            <div class="playlist-widget">
                <span class="section-title"><i class="fa-solid fa-network-wired" style="color: var(--color-primary);"></i> Playlists & EPG</span>
                
                <!-- Dynamic Playlist Row -->
                <div class="playlist-item">
                    <div class="playlist-item-header">
                        <span class="playlist-item-title"><i class="fa-solid fa-bolt" style="color: var(--color-primary);"></i> Dynamic Playlist</span>
                        <span class="playlist-item-badge">Live status</span>
                    </div>
                    <div class="playlist-row-controls">
                        <div class="playlist-link-box">
                            <div class="playlist-url-text" id="playlist-url-copy"><?php echo htmlspecialchars($playlistUrl); ?></div>
                            <button class="btn-copy" onclick="copyPlaylistUrl()" title="Copy to Clipboard">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <a href="playlist.php?download=1" class="btn btn-icon-only btn-primary" title="Download Dynamic .m3u">
                            <i class="fa-solid fa-download"></i>
                        </a>
                    </div>
                </div>

                <!-- Cached Playlist Row -->
                <div class="playlist-item">
                    <div class="playlist-item-header">
                        <span class="playlist-item-title"><i class="fa-solid fa-box-archive" style="color: var(--color-success);"></i> Cached Playlist</span>
                        <span class="playlist-item-badge badge-success">Static file</span>
                    </div>
                    <div class="playlist-row-controls">
                        <?php $cachedPlaylistUrl = $protocol . $host . $dir . '/playlist.m3u'; ?>
                        <div class="playlist-link-box">
                            <div class="playlist-url-text" id="cached-playlist-url-copy"><?php echo htmlspecialchars($cachedPlaylistUrl); ?></div>
                            <button class="btn-copy" onclick="copyCachedPlaylistUrl()" title="Copy to Clipboard">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <a href="playlist.m3u" download="playlist.m3u" class="btn btn-icon-only btn-secondary" title="Download Cached .m3u">
                            <i class="fa-solid fa-download"></i>
                        </a>
                    </div>
                </div>

                <!-- XMLTV EPG Row -->
                <div class="playlist-item">
                    <div class="playlist-item-header">
                        <span class="playlist-item-title"><i class="fa-solid fa-calendar-days" style="color: var(--color-indigo);"></i> XMLTV EPG</span>
                        <span class="playlist-item-badge badge-indigo">TV Guide</span>
                    </div>
                    <div class="playlist-row-controls">
                        <div class="playlist-link-box">
                            <div class="playlist-url-text" id="epg-url-copy"><?php echo htmlspecialchars($epgUrl); ?></div>
                            <button class="btn-copy" onclick="copyEpgUrl()" title="Copy to Clipboard">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <a href="epg.php" download="epg.xml" class="btn btn-icon-only btn-indigo" title="Download EPG XML">
                            <i class="fa-solid fa-download"></i>
                        </a>
                    </div>
                </div>
            </div>
            
        </aside>
        
        <!-- Main Dashboard View -->
        <main class="content-area">
            
            <!-- Redesigned Main Controls Bar (replacing old toolbar) -->
            <div class="dashboard-controls-bar">
                
                <!-- Add Channel Widget -->
                <div class="control-box add-channel-box">
                    <span class="control-label"><i class="fa-solid fa-plus-circle"></i> Add Channel</span>
                    <form id="add-channel-form" onsubmit="addChannel(event)" class="form-input-wrapper">
                        <input type="text" id="new-channel-input" class="input-text" placeholder="Twitch username" required style="padding: 0.45rem 0.75rem; font-size: 0.85rem;">
                        <button type="submit" class="btn btn-primary" id="btn-add-submit" title="Add Channel" style="padding: 0.45rem 0.75rem;">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </form>
                </div>

                <!-- Filter/Search Channels Widget -->
                <div class="control-box filter-channel-box">
                    <span class="control-label"><i class="fa-solid fa-magnifying-glass"></i> Filter Channels</span>
                    <div class="form-input-wrapper">
                        <input type="text" id="search-input" class="input-text" placeholder="Search name or game..." oninput="filterChannels()" style="padding: 0.45rem 0.75rem; font-size: 0.85rem;">
                    </div>
                </div>

                <!-- Actions Widget -->
                <div class="control-box bulk-actions-box">
                    <span class="control-label"><i class="fa-solid fa-gears"></i> Channel Actions</span>
                    <div class="control-actions-wrapper">
                        <!-- Bulk select/clear -->
                        <div class="btn-group">
                            <button class="btn btn-secondary btn-compact" onclick="toggleAllChannels(true)" title="Select All Channels">
                                <i class="fa-solid fa-check-double"></i> Select All
                            </button>
                            <button class="btn btn-secondary btn-compact" onclick="toggleAllChannels(false)" title="Clear All Selection">
                                <i class="fa-solid fa-square"></i> Clear All
                            </button>
                        </div>
                        
                        <!-- Maintenance operations -->
                        <div class="btn-group">
                            <button class="btn btn-primary btn-compact" onclick="syncAllSelectedLogos()" id="btn-sync-cache-logos" title="Sync metadata and pre-cache logos for selected channels">
                                <i class="fa-solid fa-arrows-rotate"></i> Sync Logos
                            </button>
                            <button class="btn btn-secondary btn-compact btn-danger-text" onclick="clearLogoCache()" id="btn-clear-cache" title="Clear cached logo files from storage">
                                <i class="fa-solid fa-trash-can"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>

            </div>
            
            <!-- Live Channels Section -->
            <?php if (!empty($liveGroups)): ?>
                <?php foreach ($liveGroups as $game => $chans): ?>
                    <section class="game-group" data-game-name="<?php echo htmlspecialchars($game); ?>">
                        <h2 class="game-title">
                            <i class="fa-solid fa-gamepad game-title-icon"></i>
                            <span class="game-name-text"><?php echo htmlspecialchars($game); ?></span>
                            <span class="game-title-count"><?php echo count($chans); ?></span>
                        </h2>
                        
                        <div class="cards-grid">
                            <?php foreach ($chans as $chan): ?>
                                <div class="channel-card live-border" data-channel-name="<?php echo htmlspecialchars($chan['login']); ?>">
                                    <!-- Delete Button -->
                                    <div class="delete-action">
                                        <button class="btn-danger-outline" onclick="deleteChannel('<?php echo htmlspecialchars($chan['login']); ?>')" title="Delete channel">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Avatar -->
                                    <div class="avatar-container">
                                        <img class="avatar" src="<?php echo htmlspecialchars($chan['profileImage']); ?>" alt="<?php echo htmlspecialchars($chan['displayName']); ?> profile pic">
                                    </div>
                                    
                                    <!-- Details -->
                                    <div class="card-details">
                                        <div class="card-header">
                                            <a class="channel-name" href="https://twitch.tv/<?php echo htmlspecialchars($chan['login']); ?>" target="_blank">
                                                <?php echo htmlspecialchars($chan['displayName']); ?>
                                            </a>
                                            <span class="live-badge">
                                                <span class="pulse-dot"></span> LIVE
                                            </span>
                                            <span class="viewers-badge">
                                                <i class="fa-regular fa-user"></i> <?php echo number_format($chan['viewers']); ?>
                                            </span>
                                        </div>
                                        
                                        <span class="game-tag" title="<?php echo htmlspecialchars($chan['game']); ?>">
                                            <?php echo htmlspecialchars($chan['game']); ?>
                                        </span>
                                        
                                        <span class="stream-title" title="<?php echo htmlspecialchars($chan['title']); ?>">
                                            <?php echo htmlspecialchars($chan['title']); ?>
                                        </span>
                                        
                                        <!-- Actions Row -->
                                        <div style="display: flex; align-items: center; justify-content: flex-end; margin-top: auto; padding-top: 0.5rem; gap: 0.5rem;">
                                            <button class="btn btn-secondary" onclick="copyStreamUrl('<?php echo htmlspecialchars($chan['login']); ?>', this)" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; font-weight: 500;">
                                                <i class="fa-solid fa-play"></i> Get Link
                                            </button>
                                            <button class="btn btn-secondary" onclick="refreshChannel('<?php echo htmlspecialchars($chan['login']); ?>', this)" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; font-weight: 500;" title="Refresh metadata and recreate logo cache">
                                                <i class="fa-solid fa-sync"></i>
                                            </button>
                                            <!-- Selection Checkbox -->
                                            <label class="custom-checkbox">
                                                <input type="checkbox" <?php echo $chan['enabled'] ? 'checked' : ''; ?> onchange="toggleChannel('<?php echo htmlspecialchars($chan['login']); ?>', this)">
                                                <span class="checkmark"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Fallback when no live channels are available -->
                <div class="game-group" id="no-live-channels" style="text-align: center; padding: 3rem; color: var(--color-text-muted);">
                    <i class="fa-solid fa-tv" style="font-size: 3rem; margin-bottom: 1rem; color: var(--color-primary); opacity: 0.5;"></i>
                    <h3>No channels are currently live</h3>
                    <p style="font-size: 0.9rem; margin-top: 0.5rem;">Offline channels can still be configured and exported in the accordion below.</p>
                </div>
            <?php endif; ?>
            
            <!-- Offline Channels Accordion Section -->
            <section class="offline-accordion">
                <button class="accordion-trigger" onclick="toggleAccordion()">
                    <span class="accordion-title">
                        <i class="fa-solid fa-moon" style="color: var(--color-text-muted);"></i>
                        Offline Channels (<?php echo count($offlineStreams); ?>)
                    </span>
                    <i class="fa-solid fa-chevron-down accordion-icon"></i>
                </button>
                
                <div class="accordion-content" id="offline-accordion-content">
                    <?php if (!empty($offlineStreams)): ?>
                        <div class="cards-grid" style="margin-top: 1rem;">
                            <?php foreach ($offlineStreams as $chan): ?>
                                <div class="channel-card offline-card" data-channel-name="<?php echo htmlspecialchars($chan['login']); ?>">
                                    <!-- Delete Button -->
                                    <div class="delete-action">
                                        <button class="btn-danger-outline" onclick="deleteChannel('<?php echo htmlspecialchars($chan['login']); ?>')" title="Delete channel">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Avatar -->
                                    <div class="avatar-container">
                                        <img class="avatar" src="<?php echo htmlspecialchars($chan['profileImage']); ?>" alt="<?php echo htmlspecialchars($chan['displayName']); ?> profile pic">
                                    </div>
                                    
                                    <!-- Details -->
                                    <div class="card-details">
                                        <div class="card-header">
                                            <a class="channel-name" href="https://twitch.tv/<?php echo htmlspecialchars($chan['login']); ?>" target="_blank">
                                                <?php echo htmlspecialchars($chan['displayName']); ?>
                                            </a>
                                            <span class="offline-badge">Offline</span>
                                        </div>
                                        
                                        <!-- Actions Row -->
                                        <div style="display: flex; align-items: center; justify-content: flex-end; margin-top: auto; padding-top: 0.5rem; gap: 0.5rem;">
                                            <button class="btn btn-secondary" onclick="refreshChannel('<?php echo htmlspecialchars($chan['login']); ?>', this)" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; font-weight: 500;" title="Refresh metadata and recreate logo cache">
                                                <i class="fa-solid fa-sync"></i>
                                            </button>
                                            <!-- Selection Checkbox -->
                                            <label class="custom-checkbox">
                                                <input type="checkbox" <?php echo $chan['enabled'] ? 'checked' : ''; ?> onchange="toggleChannel('<?php echo htmlspecialchars($chan['login']); ?>', this)">
                                                <span class="checkmark"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--color-text-muted); padding: 2rem 0; font-size: 0.9rem;">
                            No offline channels. Add channels in the sidebar to populate.
                        </p>
                    <?php endif; ?>
                </div>
            </section>
            
        </main>
        
    </div>

    <!-- Toast Notifications Container -->
    <div id="toast-container"></div>

    <!-- Client Scripts -->
    <script>
        // Helper to copy text to clipboard with fallback for non-secure HTTP contexts
        function copyToClipboard(text, successMessage) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    showToast(successMessage, 'success');
                }).catch(() => {
                    fallbackCopyToClipboard(text, successMessage);
                });
            } else {
                fallbackCopyToClipboard(text, successMessage);
            }
        }

        function fallbackCopyToClipboard(text, successMessage) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.top = "0";
            textArea.style.left = "0";
            textArea.style.opacity = "0";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showToast(successMessage, 'success');
                } else {
                    showToast('Failed to copy to clipboard.', 'error');
                }
            } catch (err) {
                showToast('Failed to copy to clipboard.', 'error');
            }
            document.body.removeChild(textArea);
        }

        // Copy Playlist URL to Clipboard
        function copyPlaylistUrl() {
            const urlText = document.getElementById('playlist-url-copy').innerText;
            copyToClipboard(urlText, 'Playlist URL copied to clipboard!');
        }

        // Copy Cached Playlist URL to Clipboard
        function copyCachedPlaylistUrl() {
            const urlText = document.getElementById('cached-playlist-url-copy').innerText;
            copyToClipboard(urlText, 'Cached Playlist URL copied to clipboard!');
        }

        // Copy EPG URL to Clipboard
        function copyEpgUrl() {
            const urlText = document.getElementById('epg-url-copy').innerText;
            copyToClipboard(urlText, 'EPG XMLTV URL copied to clipboard!');
        }

        // Show Toast Notification
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            
            const icon = document.createElement('div');
            icon.className = 'toast-icon';
            if (type === 'success') {
                icon.innerHTML = '<i class="fa-solid fa-circle-check" style="color: var(--color-success);"></i>';
            } else {
                icon.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="color: var(--color-danger);"></i>';
            }
            
            const msg = document.createElement('div');
            msg.className = 'toast-message';
            msg.innerText = message;
            
            toast.appendChild(icon);
            toast.appendChild(msg);
            container.appendChild(toast);
            
            // Trigger animation
            setTimeout(() => toast.classList.add('show'), 50);
            
            // Remove after 3.5s
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // Toggle Accordion Collapse state
        function toggleAccordion() {
            const trigger = document.querySelector('.accordion-trigger');
            const content = document.getElementById('offline-accordion-content');
            
            trigger.classList.toggle('active');
            content.classList.toggle('open');
        }

        // Search/Filter Channels
        function filterChannels() {
            const query = document.getElementById('search-input').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.channel-card');
            
            cards.forEach(card => {
                const name = card.getAttribute('data-channel-name');
                const displayName = card.querySelector('.channel-name').innerText.toLowerCase();
                const gameTag = card.querySelector('.game-tag') ? card.querySelector('.game-tag').innerText.toLowerCase() : '';
                
                if (name.includes(query) || displayName.includes(query) || gameTag.includes(query)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Hide section headings if all cards inside them are hidden
            document.querySelectorAll('.game-group').forEach(group => {
                const grid = group.querySelector('.cards-grid');
                if (!grid) return; // Skip fallback banner
                
                const visibleCards = Array.from(grid.children).filter(c => c.style.display !== 'none');
                if (visibleCards.length === 0) {
                    group.style.display = 'none';
                } else {
                    group.style.display = 'block';
                }
            });
        }

        // AJAX Toggle Channel Selection
        function toggleChannel(channelName, checkbox) {
            checkbox.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'toggle');
            formData.append('channel', channelName);
            formData.append('enabled', checkbox.checked);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                checkbox.disabled = false;
                if (data.success) {
                    showToast(`${checkbox.checked ? 'Enabled' : 'Disabled'} stream for ${channelName}!`, 'success');
                    
                    // Update stats counters
                    const statSelected = document.getElementById('stat-selected');
                    let count = parseInt(statSelected.innerText);
                    statSelected.innerText = checkbox.checked ? count + 1 : count - 1;
                } else {
                    showToast(data.error || 'Failed to update channel status.', 'error');
                    checkbox.checked = !checkbox.checked; // Revert checkbox state
                }
            })
            .catch(() => {
                checkbox.disabled = false;
                checkbox.checked = !checkbox.checked; // Revert
                showToast('A network error occurred while updating channel status.', 'error');
            });
        }

        // AJAX Toggle All Channels
        function toggleAllChannels(enable) {
            if (!confirm(`Are you sure you want to ${enable ? 'enable' : 'disable'} all channels in your list?`)) return;
            
            const formData = new FormData();
            formData.append('action', 'toggle_all');
            formData.append('enabled', enable);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(`Successfully ${enable ? 'selected' : 'cleared'} all channels!`, 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast('Failed to bulk toggle channels.', 'error');
                }
            })
            .catch(() => {
                showToast('A network error occurred during the bulk action.', 'error');
            });
        }

        // AJAX Add Channel
        function addChannel(event) {
            event.preventDefault();
            const input = document.getElementById('new-channel-input');
            const channelName = input.value.trim();
            const submitBtn = document.getElementById('btn-add-submit');
            
            if (!channelName) return;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span>';
            
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('channel', channelName);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-plus"></i>';
                
                if (data.success) {
                    showToast(`Added ${data.displayName} to your list!`, 'success');
                    input.value = '';
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to add channel.', 'error');
                }
            })
            .catch(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-plus"></i>';
                showToast('A network error occurred while adding the channel.', 'error');
            });
        }

        // AJAX Delete Channel
        function deleteChannel(channelName) {
            if (!confirm(`Are you sure you want to remove ${channelName} from your list?`)) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('channel', channelName);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(`Removed ${channelName} from your list!`, 'success');
                    const card = document.querySelector(`.channel-card[data-channel-name="${channelName}"]`);
                    if (card) {
                        card.style.transform = 'scale(0.8)';
                        card.style.opacity = '0';
                        setTimeout(() => {
                            location.reload();
                        }, 300);
                    } else {
                        location.reload();
                    }
                } else {
                    showToast(data.error || 'Failed to delete channel.', 'error');
                }
            })
            .catch(() => {
                showToast('A network error occurred while deleting the channel.', 'error');
            });
        }

        // AJAX Connect Twitch
        function connectTwitch(event) {
            event.preventDefault();
            const clientId = document.getElementById('twitch-client-id').value.trim();
            const clientSecret = document.getElementById('twitch-client-secret').value.trim();
            const submitBtn = document.getElementById('btn-connect-submit');
            const originalText = submitBtn.innerText;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Connecting...';
            
            const formData = new FormData();
            formData.append('action', 'save_creds');
            formData.append('client_id', clientId);
            formData.append('client_secret', clientSecret);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.auth_url) {
                    showToast('Credentials saved! Redirecting to Twitch...', 'success');
                    setTimeout(() => {
                        window.location.href = data.auth_url;
                    }, 1000);
                } else {
                    showToast(data.error || 'Failed to save credentials.', 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerText = originalText;
                }
            })
            .catch(() => {
                showToast('Network error while connecting.', 'error');
                submitBtn.disabled = false;
                submitBtn.innerText = originalText;
            });
        }

        // AJAX Disconnect Twitch
        function disconnectTwitch() {
            if (!confirm('Are you sure you want to disconnect your Twitch account?')) return;
            
            const formData = new FormData();
            formData.append('action', 'disconnect');
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Disconnected from Twitch.', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to disconnect.', 'error');
                }
            })
            .catch(() => {
                showToast('Network error while disconnecting.', 'error');
            });
        }

        // AJAX Sync Twitch Follows
        function syncTwitchFollows() {
            const syncBtn = document.getElementById('btn-sync-follows');
            const originalHtml = syncBtn.innerHTML;
            
            syncBtn.disabled = true;
            syncBtn.innerHTML = '<i class="fa-solid fa-sync fa-spin"></i> Syncing...';
            
            const formData = new FormData();
            formData.append('action', 'sync');
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Followed channels synced successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to sync follows.', 'error');
                    syncBtn.disabled = false;
                    syncBtn.innerHTML = originalHtml;
                }
            })
            .catch(() => {
                showToast('Network error while syncing followed channels.', 'error');
                syncBtn.disabled = false;
                syncBtn.innerHTML = originalHtml;
            });
        }

        // AJAX Copy Direct Stream HLS Link from app/streamsM3U.php
        function copyStreamUrl(channelName, button) {
            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Link';
            
            fetch(`app/streamsM3U.php?channel=${channelName}&format=json`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.url) {
                    navigator.clipboard.writeText(data.url).then(() => {
                        showToast(`Direct HLS stream URL for ${channelName} copied to clipboard!`, 'success');
                    }).catch(() => {
                        showToast('Failed to copy link to clipboard.', 'error');
                    });
                } else {
                    showToast(data.error || 'Failed to resolve stream link.', 'error');
                }
                button.disabled = false;
                button.innerHTML = originalHtml;
            })
            .catch(() => {
                showToast('A network error occurred while getting the stream URL.', 'error');
                button.disabled = false;
                button.innerHTML = originalHtml;
            });
        }

        // AJAX Refresh Channel Metadata and Logo Cache
        function refreshChannel(channelName, button) {
            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-sync fa-spin"></i> Sync';
            
            const formData = new FormData();
            formData.append('action', 'refresh_channel');
            formData.append('channel', channelName);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(`Refreshed ${data.displayName}'s logo and metadata!`, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to refresh channel info.', 'error');
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                }
            })
            .catch(() => {
                showToast('A network error occurred while syncing channel info.', 'error');
                button.disabled = false;
                button.innerHTML = originalHtml;
            });
        }

        // AJAX Sync and Pre-cache Selected Channel Logos
        function syncAllSelectedLogos() {
            const btn = document.getElementById('btn-sync-cache-logos');
            if (!btn) return;
            const originalHtml = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Syncing...';
            
            const formData = new FormData();
            formData.append('action', 'sync_selected_logos');
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(`Successfully synced and cached logos for ${data.count} selected channels!`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Failed to sync selected channels.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            })
            .catch(() => {
                showToast('A network error occurred while syncing selected channels.', 'error');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
        }

        // AJAX Clear Logo Cache
        function clearLogoCache() {
            if (!confirm('Are you sure you want to clear all cached logo images? This will force them to regenerate on the next load.')) return;
            
            const btn = document.getElementById('btn-clear-cache');
            if (!btn) return;
            const originalHtml = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing...';
            
            const formData = new FormData();
            formData.append('action', 'clear_cache');
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(`Successfully cleared ${data.count} cached logo files!`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Failed to clear logo cache.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            })
            .catch(() => {
                showToast('A network error occurred while clearing cache.', 'error');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
        }

        // AJAX Save Stream Quality Setting
        function saveStreamQuality(quality) {
            const formData = new FormData();
            formData.append('action', 'save_settings');
            formData.append('stream_quality', quality);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Preferred stream quality updated to ' + quality + '!', 'success');
                } else {
                    showToast('Failed to save preferred stream quality.', 'error');
                }
            })
            .catch(() => {
                showToast('A network error occurred while updating settings.', 'error');
            });
        }

        // Copy Redirect URI helper
        function copyRedirectUri() {
            const uriText = document.getElementById('redirect-uri-text').innerText;
            copyToClipboard(uriText, 'Redirect URI copied to clipboard!');
        }
    </script>
</body>
</html>

<?php
// app/core.php - Core helper functions and Twitch GQL/Helix API handlers

// Helper function to format viewer count for badges
function twitch_format_viewer_count($count) {
    if ($count >= 1000000) {
        return round($count / 1000000, 1) . 'M';
    }
    if ($count >= 1000) {
        return round($count / 1000, 1) . 'K';
    }
    return (string)$count;
}

// Helper function to get TTF font path
function twitch_get_font_path() {
    $paths = [
        'C:\Windows\Fonts\arialbd.ttf',
        'C:\Windows\Fonts\arial.ttf',
        'C:\Windows\Fonts\segoeuib.ttf',
        'C:\Windows\Fonts\SegoeUI.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf'
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return null;
}

// Helper function to draw rounded pill badges
function twitch_draw_filled_pill($im, $x1, $y1, $x2, $y2, $color) {
    $height = $y2 - $y1;
    $radius = $height / 2;
    imagefilledrectangle($im, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledellipse($im, $x1 + $radius, $y1 + $radius, $height, $height, $color);
    imagefilledellipse($im, $x2 - $radius, $y1 + $radius, $height, $height, $color);
}

// Helper function to get client ID
function getTwitchClientId() {
    $cacheFile = dirname(__DIR__) . '/storage/client_id.cache';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        $cachedId = trim(@file_get_contents($cacheFile));
        if (!empty($cachedId)) {
            return $cachedId;
        }
    }
    $ch = curl_init("https://www.twitch.tv/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 OPR/131.0.0.0');
    $html = curl_exec($ch);
    curl_close($ch);
    if ($html && preg_match('/clientId\s*=\s*"([0-9a-z]+)/is', $html, $auth)) {
        $clientId = array_pop($auth);
        if (!empty($clientId)) {
            @file_put_contents($cacheFile, $clientId);
            return $clientId;
        }
    }
    return 'kimne78kx3ncx6brgo4mv6wki5h1ko'; // Stable fallback client ID
}

// Helper to batch query channel statuses
function fetchChannelsStatus($channels, $clientId) {
    if (empty($channels)) return [];
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 OPR/131.0.0.0';
    $chunks = array_chunk($channels, 30);
    $allResults = [];
    foreach ($chunks as $chunk) {
        $batch = [];
        foreach ($chunk as $chan) {
            $batch[] = [
                'operationName' => 'GetChannelInfo',
                'variables' => [ 'login' => strtolower($chan) ],
                'query' => 'query GetChannelInfo($login: String!) {
                    user(login: $login) {
                        login
                        displayName
                        profileImageURL(width: 150)
                        followers {
                            totalCount
                        }
                        stream {
                            id
                            title
                            viewersCount
                            game {
                                name
                            }
                        }
                    }
                }'
            ];
        }
        $ch = curl_init('https://gql.twitch.tv/gql');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Client-ID: $clientId",
                "User-Agent: $userAgent",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($batch)
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        if (is_array($data)) {
            foreach ($data as $index => $item) {
                $user = $item['data']['user'] ?? null;
                $chanName = $chunk[$index] ?? '';
                $login = strtolower($chanName);
                // Reject channel if it has 0 followers (often a bot/placeholder/deactivated account)
                if ($user && isset($user['followers']['totalCount']) && $user['followers']['totalCount'] === 0) {
                    $user = null;
                }
                if ($user) {
                    $allResults[$login] = [
                        'exists' => true,
                        'login' => $user['login'],
                        'displayName' => $user['displayName'],
                        'profileImage' => $user['profileImageURL'] ?? 'https://static-cdn.jtvnw.net/user-default-pictures-uv/ce57700a-def9-11e9-842d-784f43822e80-profile_image-300x300.png',
                        'isLive' => !empty($user['stream']),
                        'title' => $user['stream']['title'] ?? '',
                        'viewers' => $user['stream']['viewersCount'] ?? 0,
                        'game' => $user['stream']['game']['name'] ?? 'Offline'
                    ];
                } else {
                    $allResults[$login] = [
                        'exists' => false,
                        'login' => $login,
                        'displayName' => $chanName,
                        'profileImage' => 'https://static-cdn.jtvnw.net/user-default-pictures-uv/ce57700a-def9-11e9-842d-784f43822e80-profile_image-300x300.png',
                        'isLive' => false,
                        'title' => '',
                        'viewers' => 0,
                        'game' => 'Offline'
                    ];
                }
            }
        } else {
            // GraphQL API request failed, populate with fallbacks
            foreach ($chunk as $chan) {
                $login = strtolower($chan);
                $allResults[$login] = [
                    'exists' => true,
                    'login' => $login,
                    'displayName' => $chan,
                    'profileImage' => 'https://static-cdn.jtvnw.net/user-default-pictures-uv/ce57700a-def9-11e9-842d-784f43822e80-profile_image-300x300.png',
                    'isLive' => false,
                    'title' => '',
                    'viewers' => 0,
                    'game' => 'Offline'
                ];
            }
        }
    }
    return $allResults;
}

// Get playback token from Twitch GQL API
function getTwitchToken($channel, $clientId, $userAgent) {
    $query = [
        'operationName' => 'PlaybackAccessToken_Template',
        'query' => 'query PlaybackAccessToken_Template($login: String!, $isLive: Boolean!, $vodID: ID!, $isVod: Boolean!, $playerType: String!, $platform: String!) {
            streamPlaybackAccessToken(channelName: $login, params: {platform: $platform, playerBackend: "mediaplayer", playerType: $playerType}) @include(if: $isLive) {
                value
                signature
                authorization { isForbidden forbiddenReasonCode }
                __typename
            }
            videoPlaybackAccessToken(id: $vodID, params: {platform: $platform, playerBackend: "mediaplayer", playerType: $playerType}) @include(if: $isVod) {
                value
                signature
                __typename
            }
        }',
        'variables' => [
            'isLive' => true,
            'login' => $channel,
            'isVod' => false,
            'vodID' => '',
            'playerType' => 'twilight',
            'platform' => 'web'
        ]
    ];

    $headers = [ "Client-ID: $clientId", "User-Agent: $userAgent", "Content-Type: application/json" ];
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($clientIp) && !in_array($clientIp, ['127.0.0.1', '::1']) && !preg_match('/^(127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1]))/', $clientIp)) {
        $headers[] = "X-Forwarded-For: $clientIp";
        $headers[] = "X-Real-IP: $clientIp";
        $headers[] = "Client-IP: $clientIp";
    }

    $ch = curl_init('https://gql.twitch.tv/gql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($query)
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Helper function to refresh token
function refreshTwitchToken(&$creds, $settingsFile) {
    $ch = curl_init('https://id.twitch.tv/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $creds['refresh_token'],
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret']
        ])
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (!empty($response['access_token'])) {
        $creds['access_token'] = $response['access_token'];
        if (!empty($response['refresh_token'])) {
            $creds['refresh_token'] = $response['refresh_token'];
        }
        $settings = [];
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        }
        $settings['twitch_creds'] = $creds;
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
        return true;
    }
    return false;
}

// Helper function to get normalized Twitch Redirect URI (forces localhost on HTTP)
function getTwitchRedirectUri() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    if ($protocol === 'http://') {
        if ($host === '127.0.0.1' || strpos($host, 'localhost') === false) {
            $port = parse_url('http://' . $host, PHP_URL_PORT);
            $host = 'localhost' . ($port ? ':' . $port : '');
        }
    }
    $script = $_SERVER['SCRIPT_NAME'];
    return $protocol . $host . $script;
}

// Helper function to sync channels from Twitch Helix API
function syncFollowedChannels(&$creds, $settingsFile, $channelsFile) {
    $clientId = $creds['client_id'];
    $accessToken = $creds['access_token'];
    $userId = $creds['user_id'];

    $followedChannels = [];
    $cursor = '';
    $attempts = 0;
    
    do {
        $url = "https://api.twitch.tv/helix/channels/followed?user_id=" . urlencode($userId) . "&first=100";
        if (!empty($cursor)) {
            $url .= "&after=" . urlencode($cursor);
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Client-Id: $clientId",
                "Authorization: Bearer $accessToken"
            ]
        ]);
        
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 401 && $attempts === 0) {
            $attempts++;
            if (refreshTwitchToken($creds, $settingsFile)) {
                $accessToken = $creds['access_token'];
                continue;
            }
        }
        
        $response = json_decode($res, true);
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $follow) {
                $login = strtolower($follow['broadcaster_login']);
                $displayName = $follow['broadcaster_name'];
                $followedChannels[$login] = [
                    'displayName' => $displayName
                ];
            }
            $cursor = $response['pagination']['cursor'] ?? '';
        } else {
            return false;
        }
    } while (!empty($cursor));
    
    if (empty($followedChannels)) {
        return false;
    }
    
    $existing = [];
    if (file_exists($channelsFile)) {
        $existing = json_decode(file_get_contents($channelsFile), true) ?: [];
    }
    
    // Merge: preserve checked/enabled status and logo for existing channels
    $newConfig = [];
    foreach ($followedChannels as $login => $data) {
        $newConfig[$login] = [
            'enabled' => $existing[$login]['enabled'] ?? true,
            'displayName' => $data['displayName'],
            'logo' => $existing[$login]['logo'] ?? ''
        ];
    }
    
    file_put_contents($channelsFile, json_encode($newConfig, JSON_PRETTY_PRINT));
    return true;
}

// Helper function to pre-cache channel logos in both online and offline variants
function cacheChannelLogo($channel, $logoUrl, $offline = false, $viewers = 0) {
    if (empty($logoUrl)) return;
    $cacheDir = dirname(__DIR__) . '/storage/logo_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/' . $channel . ($offline ? '_offline' : '_online_counter') . '.png';
    
    // Only generate if cache file doesn't exist or is older than 3 days (offline) or 5 minutes (live)
    $cacheLifetime = $offline ? 259200 : 300;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheLifetime)) {
        return;
    }
    
    $imgData = @file_get_contents($logoUrl);
    if (!$imgData) return;
    
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng') || !function_exists('imagecreatetruecolor')) {
        @file_put_contents($cacheFile, $imgData);
        return;
    }
    
    $srcImg = @imagecreatefromstring($imgData);
    if (!$srcImg) return;
    
    $srcWidth = imagesx($srcImg);
    $srcHeight = imagesy($srcImg);
    
    $mainCanvas = imagecreatetruecolor(300, 300);
    imagealphablending($mainCanvas, false);
    imagesavealpha($mainCanvas, true);
    $transparent = imagecolorallocatealpha($mainCanvas, 0, 0, 0, 127);
    imagefill($mainCanvas, 0, 0, $transparent);
    
    $avatarSize = 246;
    $avatarCanvas = imagecreatetruecolor($avatarSize, $avatarSize);
    imagealphablending($avatarCanvas, false);
    imagesavealpha($avatarCanvas, true);
    imagefill($avatarCanvas, 0, 0, $transparent);
    
    imagecopyresampled($avatarCanvas, $srcImg, 0, 0, 0, 0, $avatarSize, $avatarSize, $srcWidth, $srcHeight);
    
    $r = $avatarSize / 2;
    $r2 = $r * $r;
    $rInner2 = ($r - 1.5) * ($r - 1.5);
    for ($x = 0; $x < $avatarSize; $x++) {
        for ($y = 0; $y < $avatarSize; $y++) {
            $dx = $x - $r;
            $dy = $y - $r;
            $d2 = $dx * $dx + $dy * $dy;
            if ($d2 > $r2) {
                imagesetpixel($avatarCanvas, $x, $y, $transparent);
            } else if ($d2 > $rInner2) {
                $dist = sqrt($d2);
                $alpha = 127 - (int)((127 * ($r - $dist)) / 1.5);
                if ($alpha < 0) $alpha = 0;
                if ($alpha > 127) $alpha = 127;
                
                $rgb = imagecolorat($avatarCanvas, $x, $y);
                $colors = imagecolorsforindex($avatarCanvas, $rgb);
                $newColor = imagecolorallocatealpha($avatarCanvas, $colors['red'], $colors['green'], $colors['blue'], $alpha);
                imagesetpixel($avatarCanvas, $x, $y, $newColor);
            }
        }
    }
    
    if ($offline && function_exists('imagefilter')) {
        imagefilter($avatarCanvas, IMG_FILTER_GRAYSCALE);
        imagefilter($avatarCanvas, IMG_FILTER_CONTRAST, 10);
        imagefilter($avatarCanvas, IMG_FILTER_BRIGHTNESS, -35);
    }
    
    $ringRed = $offline ? 124 : 69;
    $ringGreen = $offline ? 124 : 143;
    $ringBlue = $offline ? 130 : 255;
    
    $cx = 150;
    $cy = 150;
    $rOut = 135;
    $rIn = 123;
    $rOut2 = $rOut * $rOut;
    $rIn2 = $rIn * $rIn;
    $rOutOuter2 = ($rOut + 1) * ($rOut + 1);
    $rInInner2 = ($rIn - 1) * ($rIn - 1);
    
    for ($x = 0; $x < 300; $x++) {
        for ($y = 0; $y < 300; $y++) {
            $dx = $x - $cx;
            $dy = $y - $cy;
            $d2 = $dx * $dx + $dy * $dy;
            
            if ($d2 >= $rInInner2 && $d2 <= $rOutOuter2) {
                $dist = sqrt($d2);
                $alpha = 0;
                if ($dist > $rOut) {
                    $alpha = (int)(127 * ($dist - $rOut));
                } else if ($dist < $rIn) {
                    $alpha = (int)(127 * ($rIn - $dist));
                }
                if ($alpha < 0) $alpha = 0;
                if ($alpha > 127) $alpha = 127;
                
                $ringColor = imagecolorallocatealpha($mainCanvas, $ringRed, $ringGreen, $ringBlue, $alpha);
                imagesetpixel($mainCanvas, $x, $y, $ringColor);
            }
        }
    }
    
    imagealphablending($mainCanvas, true);
    imagecopy($mainCanvas, $avatarCanvas, 27, 27, 0, 0, $avatarSize, $avatarSize);
    
    // Draw the badge at the bottom center (viewer count for live, "OFFLINE" for offline)
    $drawBadge = (!$offline && $viewers > 0) || $offline;
    
    if ($drawBadge) {
        $text = $offline ? 'OFFLINE' : twitch_format_viewer_count($viewers);
        $fontPath = twitch_get_font_path();
        $fontSize = $offline ? 15 : 22; // Slightly smaller font for "OFFLINE" to fit nicely
        
        if ($fontPath && function_exists('imagettfbbox')) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $text);
            $textWidth = abs($bbox[2] - $bbox[0]);
            $textHeight = abs($bbox[7] - $bbox[1]);
        } else {
            $textWidth = strlen($text) * ($offline ? 9 : 14);
            $textHeight = $offline ? 12 : 18;
        }
        
        $paddingX = $offline ? 18 : 24;
        $paddingY = $offline ? 8 : 10;
        $pillWidth = $textWidth + $paddingX * 2;
        $pillHeight = $textHeight + $paddingY * 2;
        
        $x1 = 150 - ($pillWidth / 2);
        $x2 = 150 + ($pillWidth / 2);
        $y1 = 265 - ($pillHeight / 2);
        $y2 = 265 + ($pillHeight / 2);
        
        $pillColor = imagecolorallocate($mainCanvas, $ringRed, $ringGreen, $ringBlue);
        $textColor = imagecolorallocate($mainCanvas, 255, 255, 255);
        
        twitch_draw_filled_pill($mainCanvas, $x1, $y1, $x2, $y2, $pillColor);
        
        if ($fontPath && function_exists('imagettftext')) {
            $tx = 150 - ($textWidth / 2);
            $ty = 265 + ($textHeight / 2) - ($offline ? 2 : 3);
            imagettftext($mainCanvas, $fontSize, 0, $tx, $ty, $textColor, $fontPath, $text);
        } else {
            $tx = 150 - ($textWidth / 2);
            $ty = 265 - ($textHeight / 2);
            imagestring($mainCanvas, 5, $tx, $ty, $text, $textColor);
        }
    }
    
    // Write image to cache file
    imagealphablending($mainCanvas, false);
    imagesavealpha($mainCanvas, true);
    imagepng($mainCanvas, $cacheFile);
    
    // Cleanup old online cache files of this channel to prevent storage bloat
    if (!$offline) {
        foreach (glob($cacheDir . '/' . $channel . '_online_*.png') as $oldFile) {
            if ($oldFile !== $cacheFile) {
                @unlink($oldFile);
            }
        }
    }
    
    imagedestroy($srcImg);
    imagedestroy($avatarCanvas);
    imagedestroy($mainCanvas);
}

// Helper to resolve Twitch CDN Stream URL
function resolveTwitchStreamUrl($channel, $quality, $clientId = null) {
    if (!$clientId) {
        $clientId = getTwitchClientId();
    }
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 OPR/131.0.0.0';
    
    $cacheDir = __DIR__ . '/../storage/stream_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/' . md5($channel . '_' . $quality) . '.cache';
    
    // Cache is valid for 120 seconds (2 minutes)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 120)) {
        $resolvedUrl = trim(@file_get_contents($cacheFile));
        if ($resolvedUrl) {
            return $resolvedUrl;
        }
    }
    
    $response = getTwitchToken($channel, $clientId, $userAgent);
    $data = $response['data']['streamPlaybackAccessToken'] ?? null;
    
    if ($data == null || $data['authorization']['isForbidden']) {
        return null;
    }
    
    $sig = urlencode($data['signature']);
    $token = urlencode($data['value']);
    $sessionId = md5(time());
    $usherUrl = "https://usher.ttvnw.net/api/channel/hls/$channel.m3u8?"
            . "acmb=$sessionId&allow_source=true&browser_family=chrome&browser_version=147.0"
            . "&cdm=wv&enable_score=true&fast_bread=true&os_name=Windows&os_version=11"
            . "&p=1337&platform=web&play_session_id=$sessionId&player_backend=mediaplayer"
            . "&player_version=1.41.0-rc.1&playlist_include_framerate=true"
            . "&reassignments_supported=true&sig=$sig&supported_codecs=av1,h265,vp9,h264"
            . "&token=$token&transcode_mode=cbr_v1";

    if ($quality === 'adaptive') {
        $resolvedUrl = $usherUrl;
    } else {
        $headers = [
            "User-Agent: $userAgent",
            "Cache-Control: no-cache, no-store, must-revalidate",
            "Pragma: no-cache",
            "Expires: 0"
        ];
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($clientIp) && !in_array($clientIp, ['127.0.0.1', '::1']) && !preg_match('/^(127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1]))/', $clientIp)) {
            $headers[] = "X-Forwarded-For: $clientIp";
            $headers[] = "X-Real-IP: $clientIp";
            $headers[] = "Client-IP: $clientIp";
        }

        $ch = curl_init($usherUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers
        ]);
        $masterM3u = curl_exec($ch);
        curl_close($ch);
        
        if ($masterM3u) {
            $lines = explode("\n", $masterM3u);
            $selectedUrl = null;
            
            if ($quality === 'best' || $quality === 'source' || $quality === '1080p') {
                // 1. Try to find 1080p resolution specifically
                foreach ($lines as $i => $line) {
                    if (strpos($line, '#EXT-X-STREAM-INF') !== false && (strpos($line, '1080p') !== false || strpos($line, '1920x1080') !== false)) {
                        for ($j = $i + 1; $j < count($lines); $j++) {
                            $trimmed = trim($lines[$j]);
                            if ($trimmed && strpos($trimmed, '#') !== 0) {
                                $selectedUrl = $trimmed;
                                break 2;
                            }
                        }
                    }
                }

                // 2. Fallback: Find the highest quality (chunked / Source) stream URL
                if (!$selectedUrl) {
                    foreach ($lines as $i => $line) {
                        if (strpos($line, '#EXT-X-STREAM-INF') !== false && (strpos($line, 'VIDEO="chunked"') !== false || strpos($line, 'NAME="Source"') !== false)) {
                            for ($j = $i + 1; $j < count($lines); $j++) {
                                $trimmed = trim($lines[$j]);
                                if ($trimmed && strpos($trimmed, '#') !== 0) {
                                    $selectedUrl = $trimmed;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            
                // 3. Fallback: get the first HTTP URL in the playlist (usually the best quality)
                if (!$selectedUrl) {
                    foreach ($lines as $line) {
                        $trimmed = trim($line);
                        if (strpos($trimmed, 'http') === 0) {
                            $selectedUrl = $trimmed;
                            break;
                        }
                    }
                }
            } else {
                // Find quality matching specific string (e.g. '720p', '480p')
                foreach ($lines as $i => $line) {
                    if (strpos($line, '#EXT-X-STREAM-INF') !== false && strpos(strtolower($line), $quality) !== false) {
                        for ($j = $i + 1; $j < count($lines); $j++) {
                            $trimmed = trim($lines[$j]);
                            if ($trimmed && strpos($trimmed, '#') !== 0) {
                                $selectedUrl = $trimmed;
                                break 2;
                            }
                        }
                    }
                }
            }
            
            $resolvedUrl = $selectedUrl ? $selectedUrl : $usherUrl;
        } else {
            $resolvedUrl = $usherUrl;
        }
    }
    
    @file_put_contents($cacheFile, $resolvedUrl);
    return $resolvedUrl;
}

// Helper to generate a static cached M3U playlist file (playlist.m3u) at the root level
function generateStaticM3UPlaylist($channelsConfig, $settings) {
    $enabledChannels = [];
    foreach ($channelsConfig as $chanName => $data) {
        if (!empty($data['enabled'])) {
            $enabledChannels[] = $chanName;
        }
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', $dir);
    if ($dir !== '/') {
        $dir = rtrim($dir, '/');
    }
    
    $streamsM3uUrl = $protocol . $host . $dir . '/app/streamsM3U.php';
    $logoPhpUrl = $protocol . $host . $dir . '/app/logo.php';
    $defaultEpg = $protocol . $host . $dir . '/epg.php';

    $streamQuality = $settings['stream_quality'] ?? '1080p';
    $qualitySuffix = '?quality=' . urlencode($streamQuality);

    $m3uContent = "#EXTM3U x-tvg-url=\"{$defaultEpg}\"\n";

    if (!empty($enabledChannels)) {
        $clientId = getTwitchClientId();
        $statuses = fetchChannelsStatus($enabledChannels, $clientId);

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
                continue;
            }
            if ($status['isLive']) {
                $liveChannels[] = $status;
            } else {
                $offlineChannels[] = $status;
            }
        }

        // Sort live channels: by game name, then display name
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
        // Output live streams
        foreach ($liveChannels as $chan) {
            $login = $chan['login'];
            $logo = $logoPhpUrl . '?channel=' . urlencode($login) . '&viewers=' . $chan['viewers'];
            $displayName = $chan['displayName'];
            $game = $chan['game'];
            $viewers = number_format($chan['viewers']);
            $titleClean = str_replace(['"', "\r", "\n"], ["'", " ", " "], $chan['title']);
            
            $m3uContent .= "#EXTINF:-1 tvg-id=\"{$login}\" tvg-name=\"{$displayName} ({$viewers})\" tvg-logo=\"{$logo}\" group-title=\"{$game}\" tvg-chno=\"{$chno}\" tvg-language=\"en\",{$displayName} ({$viewers}) - playing {$game}: {$titleClean}\n";
            
            // Resolve direct Twitch CDN link
            $resolved = resolveTwitchStreamUrl($login, $streamQuality, $clientId);
            if ($resolved) {
                $m3uContent .= "{$resolved}\n";
            } else {
                $m3uContent .= "{$streamsM3uUrl}/{$login}.m3u8{$qualitySuffix}\n";
            }
            $chno++;
        }

        // Output offline streams
        foreach ($offlineChannels as $chan) {
            $login = $chan['login'];
            $logo = $logoPhpUrl . '?channel=' . urlencode($login) . '&offline=1';
            $displayName = $chan['displayName'];
            
            $m3uContent .= "#EXTINF:-1 tvg-id=\"{$login}\" tvg-name=\"{$displayName}\" tvg-logo=\"{$logo}\" group-title=\"Offline\" tvg-chno=\"{$chno}\" tvg-language=\"en\",{$displayName} (Offline)\n";
            $m3uContent .= "{$streamsM3uUrl}/{$login}.m3u8{$qualitySuffix}\n";
            $chno++;
        }
    }

    $outputFile = dirname(__DIR__) . '/playlist.m3u';
    @file_put_contents($outputFile, $m3uContent);
}

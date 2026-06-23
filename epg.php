<?php
require_once __DIR__ . '/app/init.php';
header("Content-Type: text/xml; charset=utf-8");

// Helper to batch query channel statuses
function fetchEPGChannelsStatus($channels, $clientId) {
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
                            createdAt
                            game {
                                name
                            }
                        }
                        channel {
                            schedule {
                                segments {
                                    id
                                    title
                                    startAt
                                    endAt
                                    isCancelled
                                    categories {
                                        name
                                    }
                                }
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
                $chanName = $chunk[$index];
                $login = strtolower($chanName);
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
                        'game' => $user['stream']['game']['name'] ?? 'Offline',
                        'stream_created_at' => $user['stream']['createdAt'] ?? null,
                        'schedule' => $user['channel']['schedule']['segments'] ?? []
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
                        'game' => 'Offline',
                        'stream_created_at' => null,
                        'schedule' => []
                    ];
                }
            }
        } else {
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
                    'game' => 'Offline',
                    'schedule' => []
                ];
            }
        }
    }
    return $allResults;
}

// Filter enabled channels
$enabledChannels = [];
foreach ($channelsConfig as $chanName => $data) {
    if (!empty($data['enabled'])) {
        $enabledChannels[] = $chanName;
    }
}

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n";
echo '<tv generator-info-name="Twitch-M3U-EPG">' . "\n";

if (!empty($enabledChannels)) {
    $clientId = getTwitchClientId();
    $statuses = fetchEPGChannelsStatus($enabledChannels, $clientId);
    
    // Dynamic logo URL generator path
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $dir = dirname($_SERVER['SCRIPT_NAME']);
    $dir = str_replace('\\', '/', $dir);
    if ($dir !== '/') {
        $dir = rtrim($dir, '/');
    }
    $logoPhpUrl = $protocol . $host . $dir . '/app/logo.php';

    // 1. Output Channel Nodes
    foreach ($enabledChannels as $chanName) {
        $login = strtolower($chanName);
        $status = $statuses[$login] ?? null;
        if (!$status || !$status['exists']) {
            continue; // Skip invalid channels
        }
        
        $displayNameEsc = htmlspecialchars($status['displayName'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $logoUrl = $logoPhpUrl . '?channel=' . urlencode($login) . '&amp;offline=1';
        
        echo "  <channel id=\"{$login}\">\n";
        echo "    <display-name>{$displayNameEsc}</display-name>\n";
        echo "    <icon src=\"{$logoUrl}\" />\n";
        echo "  </channel>\n";
    }

    // 2. Output Program Schedule Nodes
    $now = time();
    $windowStart = strtotime('yesterday midnight');
    $windowEnd = $windowStart + (3 * 86400); // 3 days total (72 hours)
    
    foreach ($enabledChannels as $chanName) {
        $login = strtolower($chanName);
        $status = $statuses[$login] ?? null;
        if (!$status || !$status['exists']) {
            continue; // Skip invalid channels
        }

        $displayNameEsc = htmlspecialchars($status['displayName'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        
        // Gather all known events for this channel in the window
        $events = [];
        
        // Live stream event
        if ($status['isLive']) {
            $liveStart = !empty($status['stream_created_at']) ? strtotime($status['stream_created_at']) : ($now - 7200);
            if ($liveStart < $windowStart) {
                $liveStart = $windowStart;
            }
            $liveEnd = max($now + 14400, $liveStart + 14400);
            
            $events[] = [
                'type' => 'live',
                'start' => $liveStart,
                'end' => $liveEnd,
                'title' => '[LIVE] ' . $status['title'],
                'category' => $status['game'],
                'desc' => "Playing: " . $status['game'] . " with " . number_format($status['viewers']) . " viewers. Title: " . $status['title']
            ];
        }
        
        // Always On stream event (covers the whole window if live and category is Always On)
        $isAlwaysOn = ($status['isLive'] && strcasecmp($status['game'], 'Always On') === 0);
        if ($isAlwaysOn) {
            $events = [[
                'type' => 'live',
                'start' => $windowStart,
                'end' => $windowEnd,
                'title' => '[LIVE] ' . $status['title'],
                'category' => $status['game'],
                'desc' => "Playing: " . $status['game'] . " with " . number_format($status['viewers']) . " viewers. Title: " . $status['title']
            ]];
        }
        
        // Scheduled stream events
        if (!$isAlwaysOn && !empty($status['schedule'])) {
            foreach ($status['schedule'] as $segment) {
                if (empty($segment['startAt']) || !empty($segment['isCancelled'])) {
                    continue;
                }
                $segStart = strtotime($segment['startAt']);
                $segEnd = !empty($segment['endAt']) ? strtotime($segment['endAt']) : ($segStart + 14400); // Default to 4 hours if end time is missing
                
                // Check if segment falls within our window
                if ($segStart < $windowEnd && $segEnd > $windowStart) {
                    $segTitle = trim($segment['title']);
                    $segCategory = !empty($segment['categories'][0]['name']) ? $segment['categories'][0]['name'] : 'Gaming';
                    if (empty($segTitle)) {
                        $segTitle = $status['displayName'] . " - " . $segCategory;
                    }
                    
                    $events[] = [
                        'type' => 'scheduled',
                        'start' => $segStart,
                        'end' => $segEnd,
                        'title' => '[SCHEDULED] ' . $segTitle,
                        'category' => $segCategory,
                        'desc' => "Scheduled broadcast on Twitch: " . $segTitle . " (Category: " . $segCategory . ")"
                    ];
                }
            }
        }
        
        // Sort events by start time. If start times are equal, live takes precedence
        usort($events, function($a, $b) {
            if ($a['start'] === $b['start']) {
                if ($a['type'] === 'live') return -1;
                if ($b['type'] === 'live') return 1;
                return 0;
            }
            return $a['start'] < $b['start'] ? -1 : 1;
        });
        
        // Generate EPG programs by sweeping the timeline
        $t = $windowStart;
        while ($t < $windowEnd) {
            // Find if any event covers the current time pointer
            $selected = null;
            // Prefer live event covering $t
            foreach ($events as $e) {
                if ($e['type'] === 'live' && $e['start'] <= $t && $e['end'] > $t) {
                    $selected = $e;
                    break;
                }
            }
            // Fallback to scheduled event covering $t
            if (!$selected) {
                foreach ($events as $e) {
                    if ($e['type'] === 'scheduled' && $e['start'] <= $t && $e['end'] > $t) {
                        $selected = $e;
                        break;
                    }
                }
            }
            
            if ($selected) {
                // Determine the end of this block
                $blockEnd = min($selected['end'], $windowEnd);
                
                // Let other events interrupt the current block if they start earlier
                foreach ($events as $e) {
                    if ($e['start'] > $t && $e['start'] < $blockEnd) {
                        // A scheduled block can be interrupted by a live block or by another scheduled block
                        if ($selected['type'] === 'scheduled') {
                            $blockEnd = $e['start'];
                        }
                    }
                }
                
                // Avoid zero-duration blocks
                if ($blockEnd <= $t) {
                    $blockEnd = $t + 1800; // minimum 30 min duration to prevent infinite loop
                }
                
                $title = $selected['title'];
                $category = $selected['category'];
                $desc = $selected['desc'];
                
                $xmlStart = date('YmdHis O', $t);
                $xmlEnd = date('YmdHis O', $blockEnd);
                
                $titleEsc = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $descEsc = htmlspecialchars($desc, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $categoryEsc = htmlspecialchars($category, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                
                echo "  <programme start=\"{$xmlStart}\" stop=\"{$xmlEnd}\" channel=\"{$login}\">\n";
                echo "    <title lang=\"en\">{$titleEsc}</title>\n";
                echo "    <desc lang=\"en\">{$descEsc}</desc>\n";
                echo "    <category lang=\"en\">{$categoryEsc}</category>\n";
                echo "  </programme>\n";
                
                $t = $blockEnd;
            } else {
                // Gap - skip outputting anything, just advance the time pointer
                $gapEnd = $windowEnd;
                foreach ($events as $e) {
                    if ($e['start'] > $t && $e['start'] < $gapEnd) {
                        $gapEnd = $e['start'];
                    }
                }
                $t = $gapEnd;
            }
        }
    }
}

echo '</tv>' . "\n";
ob_end_flush();

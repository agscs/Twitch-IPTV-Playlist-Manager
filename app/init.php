<?php
// app/init.php - Shared configuration and bootstrap logic

ob_start();

// Enable CORS and set default timezone
date_default_timezone_set('Europe/London');
if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET");
}

$rootDir = dirname(__DIR__);
$storageDir = $rootDir . '/storage';
$channelsFile = $storageDir . '/channels.json';
$settingsFile = $storageDir . '/settings.json';

// Ensure the storage directory exists
if (!file_exists($storageDir)) {
    @mkdir($storageDir, 0755, true);
}

// Copy default configuration files from templates if they don't exist
if (!file_exists($settingsFile) && file_exists($settingsFile . '.example')) {
    @copy($settingsFile . '.example', $settingsFile);
}
if (!file_exists($channelsFile) && file_exists($channelsFile . '.example')) {
    @copy($channelsFile . '.example', $channelsFile);
}

// Load core library containing Twitch GQL/Helix API helper functions
require_once __DIR__ . '/core.php';

// Load configurations
$settings = [];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
}
$channelsConfig = [];
if (file_exists($channelsFile)) {
    $channelsConfig = json_decode(file_get_contents($channelsFile), true) ?: [];
}

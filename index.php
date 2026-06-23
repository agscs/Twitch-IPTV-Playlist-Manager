<?php
// index.php - Entry point, boots application and loads dashboard controller/view

require_once __DIR__ . '/app/init.php';

// Handle AJAX Post Actions (pointing to index.php from JS)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/app/dashboard.php';
    exit();
}

// OAuth Callback handling from Twitch in browser (redirects here with ?code=...)
if (isset($_GET['code'])) {
    require __DIR__ . '/app/dashboard.php';
    exit();
}

// Default display: load the dashboard view
require_once __DIR__ . '/app/dashboard.php';

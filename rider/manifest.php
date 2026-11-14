<?php
// rider/manifest.php — lets the rider "Add to Home Screen" so the page opens like an app.
// (It's a .php file so it deploys like the rest of the site; no separate .json file needed.)
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name'             => 'HATCH Rider',
    'short_name'       => 'HATCH Rider',
    'start_url'        => 'index.php',
    'scope'            => './',
    'display'          => 'standalone',
    'background_color' => '#f7f5f2',
    'theme_color'      => '#e8761e',
    'icons'            => [['src' => '../assets/images/hineys_logo.png', 'sizes' => 'any', 'type' => 'image/png', 'purpose' => 'any']],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

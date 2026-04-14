<?php
declare(strict_types=1);

/**
 * PHP built-in server router.
 *
 * Lets you run the project from repo root without `-t public`:
 *   php -S localhost:8080 router.php
 */

$publicDir = __DIR__ . '/public';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uriPath = '/' . ltrim($uriPath, '/');

// If a file exists under public/, serve it from there (even though docroot is repo root).
$publicReal = realpath($publicDir) ?: $publicDir;
$candidate = realpath($publicDir . $uriPath);
if ($candidate !== false && str_starts_with($candidate, $publicReal . DIRECTORY_SEPARATOR) && is_file($candidate)) {
    if (str_ends_with($candidate, '.php')) {
        require $candidate;
        exit;
    }

    // Basic static file serving for dev
    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    readfile($candidate);
    exit;
}

// Route directory requests to public/index.php
if ($uriPath === '/' || str_ends_with($uriPath, '/')) {
    require $publicDir . '/index.php';
    exit;
}

// Route PHP pages (and any other paths) into public/
$target = $publicDir . $uriPath;
if (is_file($target)) {
    require $target;
    exit;
}

// Fallback: index renders a friendly empty state/search
require $publicDir . '/index.php';
exit;


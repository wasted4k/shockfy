<?php
// bootstrap.php
if (!function_exists('env')) {
    function env($key, $default = null) {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($v === false || $v === null) ? $default : $v;
    }
}

// Carga sencilla de .env si existe (para hosting sin “environment variables”)
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '#') || !str_contains($t, '=')) continue;
        [$k, $val] = array_map('trim', explode('=', $t, 2));
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$val");
            $_ENV[$k] = $val;
            $_SERVER[$k] = $val;
        }
    }
}

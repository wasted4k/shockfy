<?php
// db.php robusto (funciona con o sin Composer/Dotenv)
declare(strict_types=1);

// 1) Autoload (Composer) anclado al directorio de este archivo
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload; // carga Dotenv si está instalado
}

// 2) Cargar variables de entorno (.env) si existe
$env = [];
$envPath = __DIR__ . '/.env';

if (class_exists('Dotenv\\Dotenv')) {
    // Con vlucas/phpdotenv
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad(); // no rompe si faltan variables
    $env = $_ENV;
} elseif (file_exists($envPath)) {
    // Sin Composer/Dotenv: leer .env como INI simple
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: [];
}

// 3) Credenciales con fallback para dev local
$host    = $env['DB_HOST']     ?? '127.0.0.1';
$db      = $env['DB_DATABASE'] ?? 'test';
$user    = $env['DB_USERNAME'] ?? 'root';
$pass    = $env['DB_PASSWORD'] ?? '';
$charset = 'utf8mb4';

// 4) Conexión PDO
$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Throwable $e) {
    // NO imprimas HTML aquí (rompe JSON en endpoints). Solo log y corta.
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

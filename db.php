<?php
// db.php
$host = env('DB_HOST', 'projects_shockfy_db');
$db   = env('DB_DATABASE', 'shockfy_db');
$user = env('DB_USERNAME', 'shockfy');
$pass = env('DB_PASSWORD', 'de2bc37d2748ee654a16'); // si tienes contraseña en root, ponla aquí
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    exit('Error de conexión a la base de datos: ' . $e->getMessage());
}

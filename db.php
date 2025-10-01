<?php
// db.php
$host = env('DB_HOST');
$db   = env('DB_DATABASE');
$user = env('DB_USERNAME');
$pass = env('DB_PASSWORD'); // si tienes contraseña en root, ponla aquí
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

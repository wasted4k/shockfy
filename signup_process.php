<?php
// signup_process.php — compatible con tu esquema (username/password NOT NULL) + DEBUG opcional + AUTO-LOGIN
session_start();

define('DEV_MODE', true); // pon false en producción

function back_to($path, $type, $text, $old = array(), $debug = ''){
    if (DEV_MODE && $debug) {
        $text .= " [DEBUG: " . $debug . "]";
    }
    $_SESSION['flash'] = array('type' => $type, 'text' => $text);
    if (!empty($old)) {
        if (isset($old['password'])) { unset($old['password']); }
        $_SESSION['old'] = $old;
    }
    header("Location: " . $path);
    exit;
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_to('signup.php', 'error', 'Método inválido.');
}

// Inputs
$full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$email     = isset($_POST['email']) ? trim($_POST['email']) : '';
$country   = isset($_POST['country']) ? trim($_POST['country']) : '';
$phone     = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$password  = isset($_POST['password']) ? $_POST['password'] : '';

if ($full_name === '' || $email === '' || $country === '' || $phone === '' || $password === '') {
    back_to('signup.php', 'error', 'Completa todos los campos requeridos.', array(
        'full_name' => $full_name,
        'email'     => $email,
        'country'   => $country,
        'phone'     => $phone
    ));
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_to('signup.php', 'error', 'El email no es válido.', array(
        'full_name' => $full_name,
        'country'   => $country,
        'phone'     => $phone
    ));
}
if (mb_strlen($password) < 6) {
    back_to('signup.php', 'error', 'La contraseña debe tener al menos 6 caracteres.', array(
        'full_name' => $full_name,
        'email'     => $email,
        'country'   => $country,
        'phone'     => $phone
    ));
}

$hash = password_hash($password, PASSWORD_DEFAULT);

// username = email (recortado a 50 chars para tu schema)
$username = mb_substr($email, 0, 50);

// Fechas de prueba (UTC)
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$trial_start = $nowUtc->format('Y-m-d H:i:s');
$trial_end   = (clone $nowUtc)->modify('+30 days')->format('Y-m-d H:i:s');


// Moneda por país (tu columna currency_pref es VARCHAR(10) con default "S/.")
$currency_map = array(
    'Perú' => 'S/.', 'México' => 'MXN', 'Colombia' => 'COP', 'Chile' => 'CLP', 'Argentina' => 'ARS', 'España' => 'EUR',
    'Estados Unidos' => 'USD', 'Canadá' => 'CAD', 'Brasil' => 'BRL', 'Ecuador' => 'USD', 'Bolivia' => 'BOB', 'Paraguay' => 'PYG',
    'Uruguay' => 'UYU', 'Venezuela' => 'VES', 'Guatemala' => 'GTQ', 'Honduras' => 'HNL', 'El Salvador' => 'USD', 'Nicaragua' => 'NIO',
    'Costa Rica' => 'CRC', 'Panamá' => 'USD', 'República Dominicana' => 'DOP', 'Puerto Rico' => 'USD'
);
$currency_pref = isset($currency_map[$country]) ? $currency_map[$country] : 'S/.';

// Conexión (db.php en misma carpeta)
$path = __DIR__ . '/db.php';
if (!file_exists($path)) {
    back_to('signup.php', 'error', 'No se encontró db.php', array(
        'full_name' => $full_name,
        'email'     => $email,
        'country'   => $country,
        'phone'     => $phone
    ), $path);
}
require_once $path;

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Conexión PDO no disponible.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET time_zone = '+00:00'");

    // Verifica duplicado por email
    $st = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
    $st->execute(array($email));
    if ($st->fetchColumn()) {
        back_to('signup.php', 'error', 'Este email ya está registrado. Intenta iniciar sesión.', array(
            'full_name' => $full_name,
            'country'   => $country,
            'phone'     => $phone
        ));
    }

    // Verifica duplicado por username
    $st = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    $st->execute(array($username));
    if ($st->fetchColumn()) {
        // genera uno único basado en la parte antes de @
        $base = preg_replace('/@.*/', '', $email);
        $base = mb_substr($base, 0, 46);
        $username = $base . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    // Inserta cubriendo tus columnas NOT NULL (username y password) y las nuevas
    $sql = "INSERT INTO users
            (username, password, full_name, email, country, phone, password_hash, currency_pref, trial_start, trial_end, created_at)
            VALUES
            (:username, :password, :full_name, :email, :country, :phone, :password_hash, :currency_pref, :trial_start, :trial_end, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':username'      => $username,
        // Por compatibilidad: guardamos el mismo hash también en 'password' (no texto plano)
        ':password'      => $hash,
        ':full_name'     => $full_name,
        ':email'         => $email,
        ':country'       => $country,
        ':phone'         => $phone,
        ':password_hash' => $hash,
        ':currency_pref' => $currency_pref,
        ':trial_start'   => $trial_start,
        ':trial_end'     => $trial_end
    ));

    // ===== AUTO-LOGIN tras el registro =====
    $user_id = $pdo->lastInsertId(); // PK auto_increment
    // (Opcional) volver a leer al usuario, por si quieres traer role/timezone/etc desde DB
    $stmtU = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmtU->execute(array(':id' => $user_id));
    $user = $stmtU->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        // fallback si no se pudo leer (igualmente iniciamos sesión con lo que tenemos)
        $user = array(
            'id'            => $user_id,
            'full_name'     => $full_name,
            'role'          => 'user',
            'currency_pref' => $currency_pref,
            'country'       => $country,
            'timezone'      => 'UTC',
            'time_format'   => '24h'
        );
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['full_name'] = isset($user['full_name']) ? $user['full_name'] : $full_name;
    $_SESSION['role']      = isset($user['role']) ? $user['role'] : 'user';
    $_SESSION['currency']  = isset($user['currency_pref']) ? $user['currency_pref'] : 'S/.';
    $_SESSION['country']   = isset($user['country']) ? $user['country'] : $country;
    $_SESSION['timezone']  = isset($user['timezone']) ? $user['timezone'] : 'UTC';
    $_SESSION['timefmt']   = isset($user['time_format']) ? $user['time_format'] : '24h';
    $_SESSION['logged_in'] = true;

    $_SESSION['flash'] = array(
        'type' => 'success',
        'text' => '¡Cuenta creada y sesión iniciada! Bienvenido a ShockFy.'
    );
    header('Location: welcome.php?step=1'); // redirige al usuario a welcome.php y al paso numero 1 (elegir su moneda preferida)
    exit;

} catch (Throwable $e) {
    error_log('[signup_process] ' . $e->getMessage());
    back_to('signup.php', 'error', 'No pudimos crear la cuenta. Inténtalo más tarde o contacta soporte.', array(
        'full_name' => $full_name,
        'email'     => $email,
        'country'   => $country,
        'phone'     => $phone
    ), $e->getMessage());
}

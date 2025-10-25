<?php
// onboarding_save.php — simple, sin JSON, con redirects a welcome.php
session_start();
if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
  header('Location: login.php'); exit;
}
$uid   = (int)$_SESSION['user_id'];
$next  = isset($_GET['next']) ? (int)$_GET['next'] : 1;

require_once __DIR__ . '/db.php';
if (!isset($pdo)) { die('db.php sin $pdo'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

$action = $_POST['action'] ?? '';
$current_step = 1; // Variable para saber a dónde volver si hay un error

try {
  if ($action === 'save_currency') {
    $current_step = 1;
    $currency = trim($_POST['currency'] ?? '');
    if ($currency === '') throw new Exception('Moneda requerida');
    $allowed = ['S/.','USD','EUR','MXN','COP','CLP','ARS','BRL','PEN','CAD','DOP','UYU','VES'];
    if (!in_array($currency, $allowed, true)) throw new Exception('Moneda inválida');

    $st = $pdo->prepare("UPDATE users SET currency_pref = :c, updated_at = NOW() WHERE id = :id");
    $st->execute([':c'=>$currency, ':id'=>$uid]);
    $_SESSION['currency'] = $currency;

    header('Location: welcome.php?step=' . max(2, $next)); exit;

  } elseif ($action === 'save_timezone') {
    $current_step = 2;
    $timezone = trim($_POST['timezone'] ?? '');
    if ($timezone === '') throw new Exception('Zona horaria requerida');
    if ($timezone !== 'UTC' && strpos($timezone, '/') === false) throw new Exception('Zona horaria inválida');

    $st = $pdo->prepare("UPDATE users SET timezone = :tz, updated_at = NOW() WHERE id = :id");
    $st->execute([':tz'=>$timezone, ':id'=>$uid]);
    $_SESSION['timezone'] = $timezone;

    header('Location: welcome.php?step=' . max(3, $next)); exit;

  // --- INICIO: NUEVO BLOQUE PARA GUARDAR EL NEGOCIO ---
  } elseif ($action === 'save_business_name') {
    $current_step = 4;
    $business_name = trim($_POST['business_name'] ?? '');
    if ($business_name === '') throw new Exception('El nombre del negocio es requerido');
    
    // Asumimos que la columna se llama 'nombre_negocio'
    $st = $pdo->prepare("UPDATE users SET nombre_negocio = :name, updated_at = NOW() WHERE id = :id");
    $st->execute([':name' => $business_name, ':id' => $uid]);
    
    // Opcional: Guardar en sesión
    $_SESSION['business_name'] = $business_name;
    
    // Redirigir al paso final (5)
    header('Location: welcome.php?step=' . max(5, $next)); exit;
  // --- FIN: NUEVO BLOQUE ---

  } else {
    header('Location: welcome.php'); exit;
  }

} catch (Throwable $e) {
  // Vuelve al paso actual (CORREGIDO) con el error en querystring
  $msg = urlencode($e->getMessage());
  header("Location: welcome.php?step={$current_step}&err={$msg}");
  exit;
}
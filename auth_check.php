<?php
// auth_check.php — exige login + email verificado + estado activo
// Gate por estado pending_payment y por plan/trial.
// Premium (starter) válido solo si premium_expires_at > NOW() (UTC).

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';

// ===== 1) Requiere login =====
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  header('Location: home.php');
  exit;
}

// ===== 2) Cargar datos del usuario (incluye campos premium) =====
$st = $pdo->prepare("
  SELECT id, username, full_name, status, role,
         email_verified_at, plan, trial_started_at, trial_ends_at, trial_cancelled_at,
         account_state,
         premium_started_at, premium_expires_at
  FROM users
  WHERE id = ?
  LIMIT 1
");
$st->execute([$user_id]);
$currentUser = $st->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
  // Sesión huérfana
  session_destroy();
  header('Location: login.php');
  exit;
}

// ===== 3) Debe estar activo =====
if ((int)$currentUser['status'] !== 1) {
  // Usuario desactivado por admin
  header('Location: logout.php');
  exit;
}

// ===== 4) Debe tener email verificado (ajusta si tu flujo no lo requiere) =====
if (empty($currentUser['email_verified_at'])) {
  header('Location: welcome.php?step=3');
  exit;
}

// ===== 4.1) Gate por estado de cuenta: pending_payment =====
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$accountState  = $currentUser['account_state'] ?? 'active';

if ($accountState === 'pending_confirmation') {
  // Solo permitir estas páginas mientras esté pendiente:
  $allowed_when_pending = [
    'waiting_confirmation.php',
    'logout.php',
  ];
  if (!in_array($currentScript, $allowed_when_pending, true)) {
    header('Location: waiting_confirmation.php');
    exit;
  }
  // Importante: no seguimos con otros gates (trial/plan) en este estado.
  // Simplemente dejamos continuar la ejecución del script actual permitido.
}

// ====================================================================
// 5) Gate de acceso por plan/trial (EXCEPTO en páginas de billing/pagos)
// ====================================================================

// Páginas que SIEMPRE deben poder verse aunque el trial esté vencido
// (manteniendo login + verificación + usuario activo):
$GATE_EXCEPTIONS = [
  'billing.php',
  'pago_tarjeta.php',
  'orden_compra.php',
  'checkout_create.php',
  'billing_success.php',
  'billing_cancel.php',
  'trial_expired.php',
  'waiting_confirmation.php', // clave para no chocar con pending_payment
  'logout.php',
  'login.php',
  'send_verification.php',
  'verify.php',
  'welcome.php',
];

$isGateExempt = in_array($currentScript, $GATE_EXCEPTIONS, true);

// ===== Calcular trial activo (UTC) =====
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$trialActive = false;
if (!empty($currentUser['trial_ends_at']) && empty($currentUser['trial_cancelled_at'])) {
  try {
    $trialEnd = new DateTimeImmutable($currentUser['trial_ends_at'], new DateTimeZone('UTC'));
    $trialActive = ($now < $trialEnd);
  } catch (Throwable $e) { /* ignorar parseo */ }
}

// ===== Calcular premium vigente (30 días) =====
$premiumActive = false;
if (!empty($currentUser['premium_expires_at'])) {
  try {
    $premiumUntil = new DateTimeImmutable($currentUser['premium_expires_at'], new DateTimeZone('UTC'));
    $premiumActive = ($now < $premiumUntil);
  } catch (Throwable $e) { /* ignorar parseo */ }
}

$plan = strtolower($currentUser['plan'] ?? 'free');
// Premium SOLO si el plan es starter y no está vencido
$hasPremium = ($plan === 'starter' && $premiumActive);

/*
 // (Opcional) Auto-downgrade cuando esté vencido:
 if ($plan === 'starter' && !$premiumActive) {
   $pdo->prepare("UPDATE users SET plan='free' WHERE id=?")->execute([$currentUser['id']]);
   $currentUser['plan'] = 'free';
   $plan = 'free';
 }
*/

// ===== Gate principal =====
if (!$isGateExempt) {
  if (!$hasPremium && !$trialActive) {
    // Trial vencido y sin premium vigente => bloquear
    $next = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
    header("Location: trial_expired.php?next={$next}");
    exit;
  }
}

// Si llegaste aquí, la página pasa todos los checks.
// $currentUser queda disponible para el resto del script.

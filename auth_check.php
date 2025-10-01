<?php
// auth_check.php — exige login + email verificado + estado activo
// Gate por estado pending_* y por plan/trial.
// Premium (starter) válido solo si premium_expires_at > NOW() (UTC).

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';

/** Util: basename robusto (REQUEST_URI -> PATH -> basename) */
function _current_script_base(): string {
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
  return basename($path);
}

/** Util: redirect seguro con fallback a JS si headers ya se enviaron */
function _safe_redirect(string $url): void {
  if (!headers_sent()) {
    header('Location: ' . $url, true, 302);
  } else {
    echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
  }
  exit;
}

// ===== 1) Requiere login =====
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  _safe_redirect('login.php');
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
  _safe_redirect('login.php');
}

// ===== 3) Debe estar activo =====
if ((int)$currentUser['status'] !== 1) {
  // Usuario desactivado por admin
  _safe_redirect('logout.php');
}

// ===== 4) Debe tener email verificado (ajusta si tu flujo no lo requiere) =====
if (empty($currentUser['email_verified_at'])) {
  _safe_redirect('welcome.php?step=3');
}

// ===== 4.0) Sincronizar sesión con estado actual de DB (ayuda al gate en otras páginas) =====
if (!empty($currentUser['account_state'])) {
  $_SESSION['account_state'] = $currentUser['account_state'];
}

// ===== 4.1) Gate por estado de cuenta: pending_* (compat: confirmation / payment) =====
$currentScript = _current_script_base();
$accountState  = $currentUser['account_state'] ?? 'active';

// Acepta ambos estados para compatibilidad entre entornos
$PENDING_STATES = ['pending_confirmation', 'pending_payment'];

// Restricciones cuando está en estado pendiente
if (in_array($accountState, $PENDING_STATES, true)) {
  // Solo permitir estas páginas mientras esté pendiente:
  $allowed_when_pending = [
    'waiting_confirmation.php',
    'logout.php',
    'login.php', // por si el usuario abre login en otra pestaña
  ];

  if (!in_array($currentScript, $allowed_when_pending, true)) {
    // Redirigir siempre a la waiting
    _safe_redirect('waiting_confirmation.php');
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
  'waiting_confirmation.php', // clave para no chocar con pending_*
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
    _safe_redirect("trial_expired.php?next={$next}");
  }
}

// Si llegaste aquí, la página pasa todos los checks.
// $currentUser queda disponible para el resto del script.

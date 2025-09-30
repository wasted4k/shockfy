<?php
// billing_activate.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php'; // exige login + email verificado
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: billing.php'); exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  header('Location: login.php'); exit;
}

$plan = $_POST['plan'] ?? '';
$allowed = ['starter','free'];
if (!in_array($plan, $allowed, true)) {
  $_SESSION['billing_err'] = 'Plan inválido.';
  header('Location: billing.php'); exit;
}

// Carga datos necesarios (trial y plan actual)
$stmt = $pdo->prepare("
  SELECT plan, trial_started_at, trial_ends_at, trial_cancelled_at
  FROM users
  WHERE id = :id
  LIMIT 1
");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  $_SESSION['billing_err'] = 'Usuario no encontrado.';
  header('Location: billing.php'); exit;
}

// Reglas:
$nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')));

$trialEndsAt = $user['trial_ends_at'] ?? null;
$trialCancelledAt = $user['trial_cancelled_at'] ?? null;

$trialActive = false;
if ($trialEndsAt && !$trialCancelledAt) {
  try {
    $trialEnd = new DateTimeImmutable($trialEndsAt, new DateTimeZone('UTC'));
    $trialActive = ($nowUtc < $trialEnd);
  } catch (Throwable $e) { /* ignore */ }
}

try {
  $pdo->beginTransaction();

  // Cambiar plan
  $sql = "UPDATE users SET plan = :plan WHERE id = :id";
  $params = ['plan' => $plan, 'id' => $user_id];

  // Si eligen "starter" durante el trial, opcionalmente marcamos trial_cancelled_at
  // (para que la UI ya no lo muestre como "trial activo", aunque siga dentro de fechas)
  if ($plan === 'starter' && $trialActive && !$trialCancelledAt) {
    $sql = "UPDATE users SET plan = :plan, trial_cancelled_at = :tc WHERE id = :id";
    $params['tc'] = $nowUtc->format('Y-m-d H:i:s');
  }

  $upd = $pdo->prepare($sql);
  $upd->execute($params);

  $pdo->commit();

  // Reflejar plan en la sesión (si quieres leerlo rápido en UI)
  $_SESSION['plan'] = $plan;

  $_SESSION['billing_ok'] = ($plan === 'starter')
    ? '¡Listo! Activaste el plan Starter.'
    : 'Ahora estás en Free. Algunas funciones quedarán limitadas.';

  header('Location: billing.php'); exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['billing_err'] = 'No se pudo actualizar tu plan. Intenta otra vez.';
  header('Location: billing.php'); exit;
}

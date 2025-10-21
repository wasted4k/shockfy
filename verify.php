<?php
// verify.php — Valida el código y marca el email como verificado. Inicia trial de 15 días si no existe. Redirige de vuelta.
session_start();
if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
  header('Location: login.php'); exit;
}
$uid = (int)$_SESSION['user_id'];

$back = isset($_GET['back']) && $_GET['back'] !== '' ? $_GET['back'] : 'welcome.php?step=4';

require_once __DIR__ . '/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

try {
  $code = trim($_POST['code'] ?? '');
  if ($code === '') throw new Exception('Ingresa tu código.');

  // Traer código + expiración de BD
  $st = $pdo->prepare("SELECT email_verification_code, email_verification_expires_at 
                       FROM users WHERE id = ? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new Exception('Usuario no encontrado.');

  $dbCode = (string)($row['email_verification_code'] ?? '');
  $dbExp  = $row['email_verification_expires_at'] ?? null;
  if ($dbCode === '') throw new Exception('No hay código activo. Reenvía el correo.');

  // Validar expiración (UTC)
  if ($dbExp) {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $exp = new DateTime($dbExp, new DateTimeZone('UTC'));
    if ($now > $exp) throw new Exception('El código expiró. Solicita uno nuevo.');
  }

  // Comparar códigos (estricto)
  if ($code !== $dbCode) throw new Exception('Código inválido.');

  // Marcar verificado (y limpiar código)
  $pdo->beginTransaction();

  $upd = $pdo->prepare("UPDATE users 
                        SET email_verification_code = NULL,
                            email_verification_expires_at = NULL,
                            email_verified_at = NOW()
                        WHERE id = :id");
  $upd->execute([':id' => $uid]);

  // Iniciar trial de 30 días si aún no existe
  $trialCheck = $pdo->prepare("SELECT trial_started_at, trial_ends_at FROM users WHERE id = ? LIMIT 1");
  $trialCheck->execute([$uid]);
  $t = $trialCheck->fetch(PDO::FETCH_ASSOC);

  if (empty($t['trial_started_at']) && empty($t['trial_ends_at'])) {
    $nowUtc   = new DateTime('now', new DateTimeZone('UTC'));
    $endsUtc  = (clone $nowUtc)->modify('+30 days');

    $trialUpd = $pdo->prepare("UPDATE users 
                               SET trial_started_at = :ts,
                                   trial_ends_at    = :te
                               WHERE id = :id");
    $trialUpd->execute([
      ':ts' => $nowUtc->format('Y-m-d H:i:s'),
      ':te' => $endsUtc->format('Y-m-d H:i:s'),
      ':id' => $uid
    ]);
  }

  $pdo->commit();

  // Actualizar sesión
  $_SESSION['email_verified'] = true;

  // Redirigir al back (por defecto Step 4)
  header('Location: ' . $back); exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  $msg = urlencode($e->getMessage());
  // Volver al paso 3 con el error
  header('Location: welcome.php?step=3&err=' . $msg); exit;
}

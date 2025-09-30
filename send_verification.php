<?php
// send_verification.php — Genera y envía código por email (Brevo SMTP con PHPMailer)
session_start();

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
  header('Location: login.php'); exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 0) Bootstrap: lee variables de entorno (.env o panel del hosting)
require __DIR__ . '/bootstrap.php';

// 1) Cargar PHPMailer (tu estructura sin Composer)
require __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require __DIR__ . '/vendor/phpmailer/src/SMTP.php';
require __DIR__ . '/vendor/phpmailer/src/Exception.php';

// 2) Conexión a BD (asume que $pdo queda disponible)
require_once __DIR__ . '/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

// ====== CONFIG (desde entorno) ======
$CODE_TTL_MIN = (int) env('CODE_TTL_MIN', 15);              // validez del código (minutos)
$DEV_MODE     = (bool) filter_var(env('DEV_MODE', '0'), FILTER_VALIDATE_BOOLEAN);
$APP_ENV      = env('APP_ENV', 'production');               // 'production' | 'development' | etc.
$DEBUG_SMTP   = (bool) filter_var(env('DEBUG_SMTP', '0'), FILTER_VALIDATE_BOOLEAN);

// Credenciales SMTP y remitente DESDE ENTORNO
$SMTP_HOST       = env('SMTP_HOST', 'smtp-relay.brevo.com');
$SMTP_PORT       = (int) env('SMTP_PORT', 465);             // ajusta en .env (465/ssl o 587/tls)
$SMTP_ENCRYPTION = strtolower(env('SMTP_ENCRYPTION', 'ssl'));// tls | ssl
$SMTP_USERNAME   = env('SMTP_USERNAME');                    // requerido
$SMTP_PASSWORD   = env('SMTP_PASSWORD');                    // requerido

$FROM_EMAIL      = env('MAIL_FROM');                        // remitente verificado en Brevo (requerido)
$FROM_NAME       = env('MAIL_FROM_NAME', 'ShockFy');        // etiqueta visible

try {
  // 3) Email del usuario
  $uid = (int)$_SESSION['user_id'];
  $st = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
  $st->execute([$uid]);
  $email = $st->fetchColumn();
  if (!$email) throw new Exception('No se encontró el email del usuario.');

  // Validaciones mínimas de config
  if (!$SMTP_USERNAME || !$SMTP_PASSWORD) {
    throw new Exception('Faltan credenciales SMTP en variables de entorno.');
  }
  if (!$FROM_EMAIL) {
    throw new Exception('Falta MAIL_FROM en variables de entorno.');
  }

  // 4) Generar código + expiración (UTC)
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $expiresUtc = (new DateTime('now', new DateTimeZone('UTC')))
                  ->modify('+' . $CODE_TTL_MIN . ' minutes')
                  ->format('Y-m-d H:i:s');

  // 5) Guardar en BD
  $up = $pdo->prepare("UPDATE users 
                       SET email_verification_code = :c,
                           email_verification_expires_at = :e
                       WHERE id = :id");
  $up->execute([':c' => $code, ':e' => $expiresUtc, ':id' => $uid]);

  // 6) Enviar correo (PHPMailer + Brevo)
  $mail = new PHPMailer(true);

  // Tiempo de espera y keep-alive
  $mail->Timeout = 20;        // segundos
  $mail->SMTPKeepAlive = false;

  // — Debug SMTP controlado por entorno:
  //   En producción SIEMPRE OFF. En no-producción, depende de DEBUG_SMTP=1
  $effectiveDebug = ($APP_ENV !== 'production') && $DEBUG_SMTP;
  if ($effectiveDebug) {
    $mail->SMTPDebug = 2; // 0=off, 2=client+server
    $mail->Debugoutput = function($str) {
      file_put_contents(__DIR__ . '/mail_smtp.log', '['.date('c')."] {$str}\n", FILE_APPEND);
    };
  } else {
    $mail->SMTPDebug = 0;
  }

  // Relajar validación TLS SOLO fuera de producción (útil en Windows/XAMPP)
  if ($APP_ENV !== 'production') {
    $mail->SMTPOptions = [
      'ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
      ]
    ];
  }


// --- EXCEPCIÓN TLS SOLO EN LOCAL (sin descargar nada) ---
$isLocalIp = in_array($_SERVER['SERVER_ADDR'] ?? '', ['127.0.0.1','::1'])
          || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'])
          || php_sapi_name() === 'cli-server'; // servidor embebido

$allowInsecureLocal = (bool) filter_var(env('ALLOW_INSECURE_LOCAL_TLS','0'), FILTER_VALIDATE_BOOLEAN);

// Si estamos en APP_ENV=production PERO en localhost y el flag lo permite,
// relajamos verificación TLS SOLO localmente.
if ($APP_ENV === 'production' && $isLocalIp && $allowInsecureLocal) {
  $mail->SMTPOptions = [
    'ssl' => [
      'verify_peer'       => false,
      'verify_peer_name'  => false,
      'allow_self_signed' => true,
    ]
  ];
}





  $mail->isSMTP(); 
  $mail->Host       = $SMTP_HOST;
  $mail->SMTPAuth   = true;
  $mail->Username   = $SMTP_USERNAME; // login SMTP
  $mail->Password   = $SMTP_PASSWORD; // SMTP key

  if ($SMTP_PORT === 465 || $SMTP_ENCRYPTION === 'ssl') {
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;    // SSL puro
    $mail->Port       = 465;
  } else {
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // STARTTLS en 587
    $mail->Port       = $SMTP_PORT ?: 587;
  }

  $mail->CharSet = 'UTF-8';
  $mail->setFrom($FROM_EMAIL, $FROM_NAME);
  $mail->addAddress($email);

  $mail->Subject = 'Tu código de verificación – ShockFy';
  // Texto plano (simple y seguro)
  $mail->Body    = "Hola,\n\nTu código de verificación es: {$code}\nVence en {$CODE_TTL_MIN} minutos. Si no solicitaste este email, puedes ignorar este mensaje.\n\nShockFy";

  $mail->send();

  // 7) Redirigir a tu paso 3
  header('Location: welcome.php?step=3&sent=1');
  exit;

} catch (Throwable $e) {
  // Fallback DEV (útil si el envío falla o estás probando)
  if ($DEV_MODE) {
    @file_put_contents(__DIR__ . "/verification_code_user_{$uid}.txt",
      "Email: {$email}\nCode: {$code}\nExpires (UTC): {$expiresUtc}\n", LOCK_EX);
    $_SESSION['dev_last_code'] = $code; // por si lo quieres mostrar en UI
    header('Location: welcome.php?step=3&sent=1&dev=1'); exit;
  }
  // No exponer secretos en errores
  $msg = urlencode('No se pudo enviar el código: ' . $e->getMessage());
  header('Location: welcome.php?step=3&err=' . $msg);
  exit;
}

<?php
// pago_binance.php — Recibe el comprobante, crea la solicitud y deja la cuenta en 'pending_confirmation'
// Versión: hosting-safe (CSRF, proxy HTTPS, fileinfo fallback, permisos, transacción, JSON-only)

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Convierte warnings/notices en excepciones controlables (para responder JSON limpio)
set_error_handler(function($sev, $msg, $file, $line) {
  if (error_reporting() & $sev) throw new ErrorException($msg, 0, $sev, $file, $line);
});

try {
  // ==========
  // SESIÓN
  // ==========
  $proto   = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['HTTP_X_FORWARDED_SCHEME'] ?? '');
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443)
          || (strtolower($proto) === 'https')
          || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');

  
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  require_once __DIR__ . '/db.php';
  // No se incluye auth_check.php para evitar HTML/redirects en AJAX

  // ==========
  // CONFIG (usar define() dentro del bloque)
  // ==========
  if (!defined('MAX_UPLOAD_BYTES')) define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024); // 10 MB
  if (!defined('RECEIPTS_DIR'))     define('RECEIPTS_DIR', 'uploads/receipts');
  if (!defined('PR_STATUS'))        define('PR_STATUS', 'pending');              // Ajusta si tu ENUM usa otro valor
  if (!defined('NEXT_STATE'))       define('NEXT_STATE', 'pending_confirmation'); // Gate esperado por tu auth

  // ==========
  // AUTENTICACIÓN
  // ==========
  $user_id = $_SESSION['user_id'] ?? null;
  if (!$user_id) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'UNAUTH']);
    return;
  }

  // ==========
  // MÉTODO
  // ==========
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'METHOD_NOT_ALLOWED']);
    return;
  }

  // ==========
  // CSRF
  // ==========
  $csrfBody   = $_POST['csrf'] ?? '';
  $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $csrfClient = $csrfBody ?: $csrfHeader;
  $csrfServer = $_SESSION['csrf'] ?? '';

  if (!$csrfClient || !$csrfServer || !hash_equals($csrfServer, $csrfClient)) {
    http_response_code(419);
    echo json_encode(['ok'=>false, 'error'=>'CSRF_FAIL']);
    return;
  }

  // ==========
  // CAMPOS
  // ==========
  $method    = trim($_POST['method'] ?? '') ?: 'binance_manual';
  $notes     = trim($_POST['notes'] ?? '');
  $currency  = trim($_POST['currency'] ?? 'USDT');

  // Permitir amount_usd (nuevo) o amount (legacy)
  $amountUsd = null;
  if (isset($_POST['amount_usd'])) $amountUsd = (float)$_POST['amount_usd'];
  elseif (isset($_POST['amount'])) $amountUsd = (float)$_POST['amount'];
  if ($amountUsd === null || $amountUsd <= 0) $amountUsd = 4.99;

  $allowedCurrencies = ['USDT','USD','USDC','PEN','VES'];
  if (!in_array($currency, $allowedCurrencies, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'INVALID_CURRENCY']);
    return;
  }

  // ==========
  // ARCHIVO (opcional pero recomendado)
  // ==========
  $receiptPath = null;
  if (!empty($_FILES['receipt']) && is_array($_FILES['receipt'])) {
    $file = $_FILES['receipt'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'UPLOAD_ERROR','code'=>$file['error']]);
      return;
    }

    if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'FILE_TOO_LARGE']);
      return;
    }

    // MIME real (con fallback si fileinfo no existe)
    $mime = null;
    if (class_exists('finfo')) {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime  = $finfo->file($file['tmp_name']);
    } else {
      $extGuess = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $map = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
        'png'=>'image/png','webp'=>'image/webp',
        'pdf'=>'application/pdf'
      ];
      $mime = $map[$extGuess] ?? 'application/octet-stream';
    }

    $allowed = [
      'image/jpeg'      => 'jpg',
      'image/png'       => 'png',
      'image/webp'      => 'webp',
      'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'INVALID_FILE_TYPE','mime'=>$mime]);
      return;
    }

    // Asegurar carpeta
    $absDir = __DIR__ . '/' . RECEIPTS_DIR;
    if (!is_dir($absDir)) {
      if (!mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'MKDIR_FAILED']);
        return;
      }
    }
    if (!is_writable($absDir)) {
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'NOT_WRITABLE']);
      return;
    }

    // Generar nombre seguro
    $ext   = $allowed[$mime];
    $fname = sprintf('rcpt_%d_%s.%s', (int)$user_id, bin2hex(random_bytes(8)), $ext);
    $dest  = $absDir . '/' . $fname;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'MOVE_FAILED']);
      return;
    }

    // Ruta relativa pública
    $receiptPath = RECEIPTS_DIR . '/' . $fname;
  }

  // ==========
  // DB (transacción)
  // ==========
  $pdo->beginTransaction();

  $sql = "
    INSERT INTO payment_requests
      (user_id, method, amount_usd, currency, notes, receipt_path, status, created_at, updated_at)
    VALUES
      (:uid, :method, :amount, :currency, :notes, :receipt, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':uid'     => $user_id,
    ':method'  => $method,
    ':amount'  => $amountUsd,
    ':currency'=> $currency,
    ':notes'   => $notes,
    ':receipt' => $receiptPath,
    ':status'  => PR_STATUS,
  ]);

  // Marcar cuenta en pending_confirmation (coincide con tu gate)
  $stmt2 = $pdo->prepare("UPDATE users SET account_state = :state WHERE id = :id");
  $stmt2->execute([
    ':state' => NEXT_STATE,
    ':id'    => $user_id,
  ]);

  $pdo->commit();

  echo json_encode(['ok'=>true]);
} catch (PDOException $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('[PAGO_BINANCE][SQL] '.$e->getMessage());
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'SQL_ERROR','msg'=>$e->getMessage()]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('[PAGO_BINANCE][UNEXPECTED] '.$e->getMessage());
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'UNEXPECTED','msg'=>$e->getMessage()]);
} finally {
  restore_error_handler();
}

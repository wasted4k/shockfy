<?php
// pago_binance.php — Recibe el comprobante, crea la solicitud y deja la cuenta en 'pending_confirmation'
// Versión ultra compatible (sin const, sin declare strict types, sin type hints)

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Convertir warnings/notices en excepciones para devolver JSON limpio
set_error_handler(function($sev, $msg, $file, $line) {
  if (error_reporting() & $sev) { throw new ErrorException($msg, 0, $sev, $file, $line); }
});

try {
  // =========================
  // SESIÓN DETRÁS DE PROXY
  // =========================
  $proto   = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO']
            : (isset($_SERVER['HTTP_X_FORWARDED_SCHEME']) ? $_SERVER['HTTP_X_FORWARDED_SCHEME'] : '');
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
          || (strtolower($proto) === 'https')
          || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');

  // Cookie de sesión segura según esquema real
  session_name('shockfy_sess');
  session_set_cookie_params(0, '/', '', $isHttps ? true : false, true); // lifetime, path, domain, secure, httponly
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

  require_once __DIR__ . '/db.php';
  // No incluir auth_check.php aquí (para evitar HTML/redirect en AJAX)

  // =========================
  // CONFIG (define)
  // =========================
  if (!defined('MAX_UPLOAD_BYTES')) define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024); // 10 MB
  if (!defined('RECEIPTS_DIR'))     define('RECEIPTS_DIR', 'uploads/receipts');
  if (!defined('PR_STATUS'))        define('PR_STATUS', 'pending');                // ajusta si tu ENUM usa otro valor
  if (!defined('NEXT_STATE'))       define('NEXT_STATE', 'pending_confirmation');  // coincide con tu gate

  // =========================
  // AUTENTICACIÓN
  // =========================
  $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
  if (!$user_id) {
    http_response_code(401);
    echo json_encode(array('ok'=>false, 'error'=>'UNAUTH'));
    return;
  }

  // =========================
  // MÉTODO HTTP
  // =========================
  $methodHttp = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
  if ($methodHttp !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok'=>false, 'error'=>'METHOD_NOT_ALLOWED'));
    return;
  }

  // =========================
  // CSRF (body o header)
  // =========================
  $csrfBody   = isset($_POST['csrf']) ? $_POST['csrf'] : '';
  $csrfHeader = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
  $csrfClient = $csrfBody ? $csrfBody : $csrfHeader;
  $csrfServer = isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';

  if (!$csrfClient || !$csrfServer || !hash_equals($csrfServer, $csrfClient)) {
    http_response_code(419);
    echo json_encode(array('ok'=>false, 'error'=>'CSRF_FAIL'));
    return;
  }

  // =========================
  // CAMPOS
  // =========================
  $method   = isset($_POST['method']) ? trim($_POST['method']) : '';
  if ($method === '') $method = 'binance_manual';
  $notes    = isset($_POST['notes']) ? trim($_POST['notes']) : '';
  $currency = isset($_POST['currency']) ? trim($_POST['currency']) : 'USDT';

  // amount_usd (nuevo) o amount (legacy)
  $amountUsd = null;
  if (isset($_POST['amount_usd'])) $amountUsd = (float)$_POST['amount_usd'];
  elseif (isset($_POST['amount'])) $amountUsd = (float)$_POST['amount'];
  if ($amountUsd === null || $amountUsd <= 0) $amountUsd = 4.99;

  $allowedCurrencies = array('USDT','USD','USDC','PEN','VES');
  if (!in_array($currency, $allowedCurrencies, true)) {
    http_response_code(400);
    echo json_encode(array('ok'=>false, 'error'=>'INVALID_CURRENCY'));
    return;
  }

  // =========================
  // ARCHIVO (opcional)
  // =========================
  $receiptPath = null;
  if (isset($_FILES['receipt']) && is_array($_FILES['receipt']) && !empty($_FILES['receipt']['name'])) {
    $file = $_FILES['receipt'];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
      http_response_code(400);
      echo json_encode(array('ok'=>false, 'error'=>'UPLOAD_ERROR', 'code'=>isset($file['error']) ? $file['error'] : -1));
      return;
    }

    if (!isset($file['size']) || $file['size'] > MAX_UPLOAD_BYTES) {
      http_response_code(400);
      echo json_encode(array('ok'=>false, 'error'=>'FILE_TOO_LARGE'));
      return;
    }

    // Detectar MIME real (fallback si no existe fileinfo)
    $mime = null;
    if (class_exists('finfo')) {
      $fi = new finfo(FILEINFO_MIME_TYPE);
      $mime = $fi->file($file['tmp_name']);
    } else {
      $extGuess = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $map = array(
        'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg',
        'png'=>'image/png',  'webp'=>'image/webp',
        'pdf'=>'application/pdf'
      );
      $mime = isset($map[$extGuess]) ? $map[$extGuess] : 'application/octet-stream';
    }

    $allowed = array(
      'image/jpeg'      => 'jpg',
      'image/png'       => 'png',
      'image/webp'      => 'webp',
      'application/pdf' => 'pdf'
    );
    if (!isset($allowed[$mime])) {
      http_response_code(400);
      echo json_encode(array('ok'=>false, 'error'=>'INVALID_FILE_TYPE', 'mime'=>$mime));
      return;
    }

    // Asegurar carpeta
    $absDir = __DIR__ . '/' . RECEIPTS_DIR;
    if (!is_dir($absDir)) {
      if (!mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        http_response_code(500);
        echo json_encode(array('ok'=>false, 'error'=>'MKDIR_FAILED'));
        return;
      }
    }
    if (!is_writable($absDir)) {
      http_response_code(500);
      echo json_encode(array('ok'=>false, 'error'=>'NOT_WRITABLE'));
      return;
    }

    // Generar nombre seguro
    $ext   = $allowed[$mime];
    $fname = 'rcpt_' . intval($user_id) . '_' . bin2hex(openssl_random_pseudo_bytes(8)) . '.' . $ext;
    $dest  = $absDir . '/' . $fname;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      http_response_code(500);
      echo json_encode(array('ok'=>false, 'error'=>'MOVE_FAILED'));
      return;
    }

    // Ruta relativa pública
    $receiptPath = RECEIPTS_DIR . '/' . $fname;
  }

  // =========================
  // DB (transacción)
  // =========================
  $pdo->beginTransaction();

  $sql = "
    INSERT INTO payment_requests
      (user_id, method, amount_usd, currency, notes, receipt_path, status, created_at, updated_at)
    VALUES
      (:uid, :method, :amount, :currency, :notes, :receipt, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array(
    ':uid'     => $user_id,
    ':method'  => $method,
    ':amount'  => $amountUsd,
    ':currency'=> $currency,
    ':notes'   => $notes,
    ':receipt' => $receiptPath,
    ':status'  => PR_STATUS
  ));

  // Marcar cuenta como pendiente de confirmación
  $stmt2 = $pdo->prepare("UPDATE users SET account_state = :state WHERE id = :id");
  $stmt2->execute(array(
    ':state' => NEXT_STATE,
    ':id'    => $user_id
  ));

  $pdo->commit();

  echo json_encode(array('ok'=>true));
} catch (PDOException $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('[PAGO_BINANCE][SQL] '.$e->getMessage());
  http_response_code(400);
  echo json_encode(array('ok'=>false,'error'=>'SQL_ERROR','msg'=>$e->getMessage()));
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('[PAGO_BINANCE][UNEXPECTED] '.$e->getMessage());
  http_response_code(400);
  echo json_encode(array('ok'=>false,'error'=>'UNEXPECTED','msg'=>$e->getMessage()));
} finally {
  restore_error_handler();
}

<?php
// pago_binance.php — Recibe el comprobante, crea la solicitud y deja la cuenta en 'pending_confirmation'
// Versión hosting-safe: JSON-only, CSRF, transacción, compatibilidad de campos, manejo de carpeta/permiso

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Nunca imprimir warnings en la respuesta (rompen JSON)
ini_set('display_errors', '0');
set_error_handler(function($sev, $msg, $file, $line) {
  if (error_reporting() & $sev) { throw new ErrorException($msg, 0, $sev, $file, $line); }
});

try {
  // Sesión ANTES de cualquier uso de $_SESSION
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

  require_once __DIR__ . '/db.php';
  // No incluimos auth_check.php para evitar redirecciones HTML en AJAX

  // ====== Autenticación mínima ======
  $user_id = $_SESSION['user_id'] ?? null;
  if (!$user_id) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'UNAUTH']);
    return;
  }

  // ====== Método HTTP ======
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'METHOD_NOT_ALLOWED']);
    return;
  }

  // ====== CSRF ======
  $csrfClient = $_POST['csrf'] ?? '';
  $csrfServer = $_SESSION['csrf'] ?? '';
  if (!$csrfClient || !$csrfServer || !hash_equals($csrfServer, $csrfClient)) {
    http_response_code(419);
    echo json_encode(['ok'=>false, 'error'=>'CSRF_FAIL']);
    return;
  }

  // ====== Campos (aceptamos ambos esquemas para compatibilidad) ======
  // Front nuevo:
  $method     = trim($_POST['method'] ?? '') ?: 'binance_manual';
  $amountUsd  = isset($_POST['amount_usd']) ? (float)$_POST['amount_usd'] : null;
  $currency   = trim($_POST['currency'] ?? 'USDT');
  $notes      = trim($_POST['notes'] ?? '');

  // Front antiguo (por si quedó en alguna cache/cliente):
  if ($amountUsd === null && isset($_POST['amount'])) {
    $amountUsd = (float)$_POST['amount'];
  }

  // Validaciones
  if ($amountUsd === null || $amountUsd <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'INVALID_AMOUNT']);
    return;
  }
  if (!in_array($currency, ['USDT','USD','USDC','VES','PEN'], true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'INVALID_CURRENCY']);
    return;
  }

  // ====== Archivo (comprobante) ======
  $receiptPath = null;
  if (!empty($_FILES['receipt']) && is_array($_FILES['receipt'])) {
    $file = $_FILES['receipt'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'UPLOAD_ERROR', 'code'=>$file['error']]);
      return;
    }

    // Límite recomendado (ajústalo a lo que tengas en PHP/Nginx)
    $maxBytes = 10 * 1024 * 1024; // 10 MB
    if (($file['size'] ?? 0) > $maxBytes) {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'FILE_TOO_LARGE']);
      return;
    }

    // Validar MIME real (no solo por extensión)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = [
      'image/jpeg'      => 'jpg',
      'image/png'       => 'png',
      'image/webp'      => 'webp',
      'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'INVALID_FILE_TYPE']);
      return;
    }

    // Carpeta destino
    $dir = __DIR__ . '/uploads/receipts';
    if (!is_dir($dir)) {
      if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de recibos.');
      }
    }
    if (!is_writable($dir)) {
      throw new RuntimeException('La carpeta de recibos no es escribible.');
    }

    // Nombre seguro
    $ext   = $allowed[$mime];
    $fname = sprintf('rcpt_%d_%s.%s', (int)$user_id, bin2hex(random_bytes(8)), $ext);
    $dest  = $dir . '/' . $fname;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      throw new RuntimeException('MOVE_FAILED');
    }

    // Ruta pública/relativa para guardar en DB
    $receiptPath = 'uploads/receipts/' . $fname;
  }

  // ====== Transacción: crear request + marcar cuenta pendiente ======
  $pdo->beginTransaction();

  // NOTA: si tu tabla payment_requests tiene created_at/updated_at NOT NULL, setéalos aquí
  $sql = "
    INSERT INTO payment_requests
      (user_id, method, amount_usd, currency, notes, receipt_path, status, created_at, updated_at)
    VALUES
      (:uid, :method, :amount, :currency, :notes, :receipt, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':uid'     => $user_id,
    ':method'  => $method,
    ':amount'  => $amountUsd,
    ':currency'=> $currency,
    ':notes'   => $notes,
    ':receipt' => $receiptPath,
  ]);

  // Estado de cuenta: pending_confirmation (coincide con tu gate)
  $pdo->prepare("UPDATE users SET account_state = 'pending_confirmation' WHERE id = :id")
      ->execute([':id' => $user_id]);

  $pdo->commit();

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  // Log para inspección en hosting
  error_log('[PAGO_BINANCE] ' . $e->getMessage());
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'SERVER', 'msg'=>$e->getMessage()]);
} finally {
  restore_error_handler();
}

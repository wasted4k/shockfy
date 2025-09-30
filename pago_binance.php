<?php
// pago_binance.php — recibe el comprobante, crea la solicitud y deja la cuenta en 'pending_payment'

// Iniciar sesión ANTES de cualquier include o salida
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/db.php';
// No incluimos auth_check.php para evitar redirects HTML en AJAX
// require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

// Verificación de sesión (responder JSON, no HTML)
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'UNAUTH']);
  exit;
}

try {
  // ===== Validaciones mínimas =====
  $paid     = isset($_POST['paid'])  && $_POST['paid']  === '1';
  $terms    = isset($_POST['terms']) && $_POST['terms'] === '1';
  $amount   = isset($_POST['amount']) ? floatval($_POST['amount']) : 4.99;
  $currency = $_POST['currency'] ?? 'USDT';
  $notes    = $_POST['notes'] ?? '';
  $method   = 'binance';

  if (!$paid || !$terms) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'CHECKS_REQUIRED']);
    exit;
  }

  // ===== Subida de archivo (opcional pero recomendado) =====
  $receiptPath = null;
  if (!empty($_FILES['receipt']['name'])) {
    $file = $_FILES['receipt'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'UPLOAD_ERROR']);
      exit;
    }

    // Validar tipo y tamaño
    $allowedMime = ['image/jpeg','image/png','application/pdf','image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMime, true)) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'INVALID_FILE_TYPE']);
      exit;
    }
    if ($file['size'] > 8 * 1024 * 1024) { // 8 MB
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'FILE_TOO_LARGE']);
      exit;
    }

    // Carpeta destino
    $dir = __DIR__ . '/uploads/receipts';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

    // Nombre seguro
    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = 'rcpt_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $dest = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'MOVE_FAILED']);
      exit;
    }

    $receiptPath = 'uploads/receipts/' . $name; // ruta pública relativa
  }

  // ===== Crear solicitud =====
  $stmt = $pdo->prepare("INSERT INTO payment_requests
    (user_id, method, amount_usd, currency, notes, receipt_path, status)
    VALUES (:uid, :method, :amount, :currency, :notes, :receipt, 'pending')");
  $stmt->execute([
    'uid'      => $user_id,
    'method'   => $method,
    'amount'   => $amount,
    'currency' => $currency,
    'notes'    => $notes,
    'receipt'  => $receiptPath
  ]);

  // ===== Marcar cuenta como pendiente =====
  $pdo->prepare("UPDATE users SET account_state = 'pending_payment' WHERE id = :uid")
      ->execute(['uid' => $user_id]);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'SERVER','msg'=>$e->getMessage()]);
}

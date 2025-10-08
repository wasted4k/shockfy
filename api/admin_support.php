<?php
// api/admin_support.php — Envío de mensajes como "agent" (JSON only)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../db.php';

/* ===== Helpers ===== */
function respond(int $code, array $payload): void {
  http_response_code($code);
  if (ob_get_length() !== false) { @ob_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}
function fail(int $code, string $msg): void {
  respond($code, ['ok'=>false, 'error'=>$msg]);
}
function hasColumn(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}
function is_admin(): bool {
  // Ajusta a tu lógica real; mantenemos la misma convención del admin_chat.php
  if (!empty($_SESSION['is_admin'])) return true;
  if (($_SESSION['role'] ?? null) === 'admin') return true;
  return false;
}

/* ===== Auth ===== */
if (!isset($_SESSION['user_id'])) fail(401, 'No autenticado');
if (!is_admin()) fail(403, 'Acceso restringido');

/* ===== Router (solo POST: enviar mensaje) ===== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') fail(405, 'Method not allowed');

if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
  fail(413, 'El contenido excede el límite del servidor');
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$message  = trim((string)($_POST['message'] ?? ''));
$filePath = null;

if ($ticketId <= 0) fail(400, 'ticket_id inválido');

// Adjuntos opcionales (mismas reglas que el lado usuario)
if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
  if ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
    fail(413, 'Adjunto excede el límite');
  }
  if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    fail(400, 'Error al subir archivo');
  }
  if (!empty($_FILES['file']['size']) && (int)$_FILES['file']['size'] > 2*1024*1024) {
    fail(413, 'Adjunto excede 2 MB');
  }
  $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, ['png','jpg','jpeg','pdf'], true)) {
    fail(400, 'Extensión no permitida');
  }
  $dir = __DIR__ . '/../uploads/support/';
  if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
    fail(500, 'No se pudo preparar el directorio de adjuntos');
  }
  $dest = $dir . uniqid('att_', true) . '.' . $ext;
  if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    fail(500, 'No se pudo guardar el adjunto');
  }
  $filePath = 'uploads/support/' . basename($dest);
}

// No permitir ambos vacíos
if ($message === '' && !$filePath) fail(400, 'Mensaje vacío');

/* ===== Inserción ===== */
try {
  $pdo->beginTransaction();
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (\Throwable $tz) { /* ignore */ }

  // Verificar que el ticket exista
  $hasUnreadUser = hasColumn($pdo, 'support_tickets', 'unread_user');
  $hasUnreadAdm  = hasColumn($pdo, 'support_tickets', 'unread_admin');

  $chk = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id=? LIMIT 1");
  $chk->execute([$ticketId]);
  $ticket = $chk->fetch(PDO::FETCH_ASSOC);
  if (!$ticket) {
    $pdo->rollBack();
    fail(404, 'Ticket no encontrado');
  }

  // Inserta mensaje como "agent"
  $ins = $pdo->prepare("
    INSERT INTO support_messages (ticket_id, sender, message, file_path, created_at)
    VALUES (?, 'agent', ?, ?, NOW())
  ");
  $ok = $ins->execute([$ticketId, ($message !== '' ? $message : ''), $filePath]);
  if (!$ok) {
    $ei = $ins->errorInfo();
    throw new \PDOException($ei[2] ?? 'No se pudo insertar el mensaje');
  }

  // Actualizar ticket: marcar como no leído para el usuario y actualizar last_message_at
  if ($hasUnreadUser && $hasUnreadAdm) {
    $upd = $pdo->prepare("
      UPDATE support_tickets
      SET unread_user=1, unread_admin=0, last_message_at=NOW(), updated_at=NOW()
      WHERE id=?
    ");
  } elseif ($hasUnreadUser) {
    $upd = $pdo->prepare("
      UPDATE support_tickets
      SET unread_user=1, last_message_at=NOW(), updated_at=NOW()
      WHERE id=?
    ");
  } else {
    $upd = $pdo->prepare("
      UPDATE support_tickets
      SET last_message_at=NOW(), updated_at=NOW()
      WHERE id=?
    ");
  }
  $upd->execute([$ticketId]);

  $pdo->commit();
  respond(200, ['ok'=>true, 'ticket'=>$ticketId]);
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('admin_support POST fail: '.$e->getMessage());
  fail(500, 'Error interno');
}

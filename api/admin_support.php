<?php
// api/admin_support.php — Envío de mensajes como "admin/agent" (JSON only, con debug y autodetección)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../db.php';

/* ===== Debug helpers (igual filosofía que support_chat.php) ===== */
function is_debug(): bool {
  if (!empty($_GET['debug']) && $_GET['debug'] === '1') return true;
  $v = $_ENV['APP_DEBUG'] ?? '0';
  return (string)$v === '1';
}
function respond(int $code, array $payload): void {
  http_response_code($code);
  if (ob_get_length() !== false) { @ob_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}
function fail(int $code, string $msg, ?string $detail=null): void {
  if ($detail) error_log('[admin_support] '.$msg.' — '.$detail);
  $out = ['ok'=>false,'error'=>$msg];
  if (is_debug() && $detail) $out['detail'] = $detail;
  respond($code, $out);
}
function hasColumn(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}
function senderAllowed(PDO $pdo): string {
  // Detecta si sender es ENUM y contiene 'admin' o 'agent'; por defecto usa 'agent'
  try {
    $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='support_messages' AND COLUMN_NAME='sender' LIMIT 1";
    $st = $pdo->query($sql);
    $type = (string)($st->fetchColumn() ?: '');
    $lc = strtolower($type);
    if ($lc && str_starts_with($lc,'enum(')) {
      if (strpos($lc, "'admin'") !== false) return 'admin';
      if (strpos($lc, "'agent'") !== false) return 'agent';
    }
  } catch (\Throwable $e) { /* ignore */ }
  // Si no es ENUM o no encontramos nada, 'agent' es compatible con tu front (lo trata como no-'user')
  return 'agent';
}
function is_admin(): bool {
  if (!empty($_SESSION['is_admin'])) return true;
  if (($_SESSION['role'] ?? null) === 'admin') return true;
  return false;
}

// ----------- Guard de seguridad -----------
if (!is_admin()) {
  http_response_code(403);
  echo '<h1>Acceso restringido</h1><p>Solo administradores.</p>';
  exit;
}


/* ===== Router (solo POST) ===== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') fail(405, 'Method not allowed');

if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
  fail(413, 'El contenido excede el límite del servidor');
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$message  = trim((string)($_POST['message'] ?? ''));
$filePath = null;

if ($ticketId <= 0) fail(400, 'ticket_id inválido');

/* ===== Adjuntos (opcionales) ===== */
if (!empty($_FILES['file']) && (int)$_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
  $err = (int)$_FILES['file']['error'];
  if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    fail(413, 'Adjunto excede el límite', 'PHP upload error='.$err);
  }
  if ($err !== UPLOAD_ERR_OK) {
    fail(400, 'Error al subir archivo', 'PHP upload error='.$err);
  }
  $size = (int)($_FILES['file']['size'] ?? 0);
  if ($size > 2*1024*1024) fail(413, 'Adjunto excede 2 MB', 'size='.$size);

  $ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
  if (!in_array($ext, ['png','jpg','jpeg','pdf'], true)) {
    fail(400, 'Extensión no permitida', 'ext='.$ext);
  }

  $dir = __DIR__ . '/../uploads/support/';
  if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
    fail(500, 'No se pudo preparar el directorio de adjuntos', 'mkdir failed');
  }
  if (!is_writable($dir)) {
    fail(500, 'Directorio de adjuntos no escribible', 'not writable: '.$dir);
  }

  $dest = $dir . uniqid('att_', true) . '.' . $ext;
  if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    fail(500, 'No se pudo guardar el adjunto', 'move_uploaded_file failed');
  }
  $filePath = 'uploads/support/' . basename($dest);
}

/* ===== No permitir ambos vacíos ===== */
if ($message === '' && !$filePath) fail(400, 'Mensaje vacío');

/* ===== Inserción ===== */
try {
  $pdo->beginTransaction();
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (\Throwable $tz) { /* ignore */ }

  $hasUnreadUser = hasColumn($pdo, 'support_tickets', 'unread_user');
  $hasUnreadAdm  = hasColumn($pdo, 'support_tickets', 'unread_admin');

  // Verificar que el ticket exista
  $chk = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id=? LIMIT 1");
  if (!$chk->execute([$ticketId])) {
    $ei = $chk->errorInfo();
    throw new \PDOException('SELECT ticket fail: '.($ei[2] ?? 'unknown'));
  }
  $ticket = $chk->fetch(PDO::FETCH_ASSOC);
  if (!$ticket) {
    $pdo->rollBack();
    fail(404, 'Ticket no encontrado');
  }

  $sender = senderAllowed($pdo); // 'admin' o 'agent' según tu esquema

  // Inserta mensaje como admin/agent
  $ins = $pdo->prepare("
    INSERT INTO support_messages (ticket_id, sender, message, file_path, created_at)
    VALUES (?, ?, ?, ?, NOW())
  ");
  $ok = $ins->execute([$ticketId, $sender, ($message !== '' ? $message : ''), $filePath]);
  if (!$ok) {
    $ei = $ins->errorInfo();
    throw new \PDOException('INSERT support_messages fail: '.($ei[2] ?? 'unknown'));
  }

  // Actualizar ticket (notificar al usuario y refrescar fechas)
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
  if (!$upd->execute([$ticketId])) {
    $ei = $upd->errorInfo();
    throw new \PDOException('UPDATE ticket fail: '.($ei[2] ?? 'unknown'));
  }

  $pdo->commit();
  respond(200, ['ok'=>true, 'ticket'=>$ticketId]);

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('admin_support POST fail: '.$e->getMessage());
  fail(500, 'Error interno', $e->getMessage());
}

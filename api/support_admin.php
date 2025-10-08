<?php
// api/support_admin.php — Panel admin (JSON only, alineado con support_chat.php)
declare(strict_types=1);

/* ===== Salida JSON y errores ===== */
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');   // nunca HTML de errores
ini_set('html_errors','0');
ini_set('log_errors','1');       // log al error_log del servidor
ob_start();

/* ===== Dependencias ===== */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../db.php';

/* ===== Utils (mismas que en support_chat.php) ===== */
function is_debug(): bool {
  // APP_DEBUG=1 o ?debug=1
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
  $out = ['ok'=>false,'error'=>$msg];
  if (is_debug() && $detail) $out['detail'] = $detail;
  respond($code, $out);
}

/** Comprueba si una columna existe en la tabla (en el esquema actual) */
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

/** Devuelve true si la columna existe y es NOT NULL (por si lo necesitas a futuro) */
function colIsNotNull(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT IS_NULLABLE
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $col]);
  $val = $st->fetchColumn();
  if ($val === false || $val === null) return false; // no existe
  return (strtoupper((string)$val) === 'NO');
}

/* ===== Auth mínima (estilo support_chat, pero exigiendo admin si existe column role) ===== */
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) fail(401, 'No autenticado', 'NO_AUTH');

try {
  if (hasColumn($pdo, 'users', 'role')) {
    $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
    $st->execute([(int)$user_id]);
    $role = strtolower((string)$st->fetchColumn());
    if ($role !== 'admin') fail(403, 'Acceso denegado', 'NOT_ADMIN');
  }
} catch (\Throwable $e) {
  error_log('support_admin role check: '.$e->getMessage());
  fail(500, 'Error de autenticación', $e->getMessage());
}

/* ===== Router ===== */
try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = $_GET['action'] ?? ($_POST['action'] ?? '');

  /* --- GET: health --- */
  if ($method === 'GET' && $action === 'health') {
    respond(200, ['ok'=>true, 'ts'=>date('Y-m-d H:i:s')]);
  }

  /* --- GET: lista de tickets --- */
  if ($method === 'GET' && $action === 'list') {
    $status = $_GET['status'] ?? 'open';
    $status = in_array($status, ['open','resolved'], true) ? $status : 'open';

    $hasUnreadAdmin = hasColumn($pdo, 'support_tickets', 'unread_admin');
    $selUnread      = $hasUnreadAdmin ? 't.unread_admin' : '0 AS unread_admin';
    $orderBase      = 't.last_message_at DESC, t.id DESC';
    $orderOpen      = $hasUnreadAdmin ? "t.unread_admin DESC, $orderBase" : $orderBase;

    if ($status === 'open') {
      // Abiertos = open + pending
      $sql = "
        SELECT t.id, t.public_id, t.user_id, t.status, $selUnread, t.last_message_at, t.created_at,
               u.full_name
        FROM support_tickets t
        JOIN users u ON u.id = t.user_id
        WHERE t.status IN ('open','pending')
        ORDER BY $orderOpen
        LIMIT 200
      ";
      $st = $pdo->query($sql);
    } else {
      // Resueltos
      $sql = "
        SELECT t.id, t.public_id, t.user_id, t.status, $selUnread, t.last_message_at, t.created_at,
               u.full_name
        FROM support_tickets t
        JOIN users u ON u.id = t.user_id
        WHERE t.status = 'resolved'
        ORDER BY $orderBase
        LIMIT 200
      ";
      $st = $pdo->query($sql);
    }

    $tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    respond(200, ['ok'=>true, 'tickets'=>$tickets]);
  }

  /* --- GET: hilo de un ticket --- */
  if ($method === 'GET' && $action === 'thread') {
    $ticketId = (int)($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) fail(400, 'ticket_id inválido');

    $t = $pdo->prepare("
      SELECT t.id, t.public_id, t.user_id, t.status, t.last_message_at,
             t.created_at, t.updated_at, u.full_name
      FROM support_tickets t
      JOIN users u ON u.id = t.user_id
      WHERE t.id=? LIMIT 1
    ");
    $t->execute([$ticketId]);
    $ticket = $t->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) fail(404, 'Ticket no encontrado');

    // (opcional) soporto &since=YYYY-MM-DD HH:MM:SS para reducir payload
    $since = $_GET['since'] ?? null;
    $sql = "
      SELECT id, sender, message, file_path, created_at
      FROM support_messages
      WHERE ticket_id=?
    ";
    $args = [$ticketId];
    if ($since && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
      $sql .= " AND created_at > ?";
      $args[] = $since;
    }
    $sql .= " ORDER BY id ASC";
    $m = $pdo->prepare($sql);
    $m->execute($args);
    $messages = $m->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // marcar leído para admin si existe la columna
    if (hasColumn($pdo, 'support_tickets', 'unread_admin')) {
      try { $pdo->prepare("UPDATE support_tickets SET unread_admin=0 WHERE id=?")->execute([$ticketId]); }
      catch (\Throwable $e) { /* ignore */ }
    }

    respond(200, ['ok'=>true, 'ticket'=>$ticket, 'messages'=>$messages]);
  }

  /* --- POST: responder en un ticket --- */
  if ($method === 'POST' && $action === 'reply') {
    // Payload supera límites (post_max_size/upload_max_filesize)
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
      fail(413, 'El contenido excede el límite del servidor');
    }

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $message  = trim((string)($_POST['message'] ?? ''));
    if ($ticketId <= 0) fail(400, 'ticket_id inválido');

    // comprobar que el ticket existe
    $q = $pdo->prepare("SELECT id FROM support_tickets WHERE id=? LIMIT 1");
    $q->execute([$ticketId]);
    if (!$q->fetchColumn()) fail(404, 'Ticket no encontrado');

    // Adjunto opcional
    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
        fail(413, 'Adjunto excede el límite');
      }
      if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        fail(400, 'Error al subir archivo', 'php upload err='.(string)$_FILES['file']['error']);
      }
      // Límite blando 2MB
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
      $dest = $dir + uniqid('att_', true) . '.' . $ext; // <-- CORREGIDO abajo
    }

    // No permitir ambos vacíos
    // (si hay adjunto permitido, el message puede ir vacío → guardamos '' para NOT NULL)
    $hasFile = (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE);
    if ($message === '' && !$hasFile) fail(400, 'Mensaje vacío');

    // Guardar (compatibilidad STRICT MODE)
    try {
      $pdo->beginTransaction();
      try { $pdo->exec("SET time_zone = '+00:00'"); } catch (\Throwable $tz) { /* ignore */ }

      // Si hubo adjunto, terminar de mover y componer path público
      if ($hasFile) {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $dir = __DIR__ . '/../uploads/support/';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
          fail(500, 'No se pudo preparar el directorio de adjuntos');
        }
        $dest = $dir . uniqid('att_', true) . '.' . $ext; // ← CORRECCIÓN del bug anterior (+ → .)
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
          fail(500, 'No se pudo guardar el adjunto');
        }
        $filePath = 'uploads/support/' . basename($dest);
      }

      // Para esquemas con message NOT NULL, usa '' si no hay texto
      $messageForDB = ($message !== '' ? $message : '');

      $ins = $pdo->prepare("
        INSERT INTO support_messages (ticket_id, sender, message, file_path, created_at)
        VALUES (?, 'admin', ?, ?, NOW())
      ");
      $ok = $ins->execute([$ticketId, $messageForDB, $filePath]);
      if (!$ok) {
        $ei = $ins->errorInfo();
        fail(500, 'Error interno', 'INSERT support_messages: '.($ei[2] ?? 'desconocido'));
      }

      // Actualizar ticket: unread_user si existe, last_message_at/updated_at siempre
      $hasUnreadUser = hasColumn($pdo, 'support_tickets', 'unread_user');
      if ($hasUnreadUser) {
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
      respond(200, ['ok'=>true]);

    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('support_admin reply fail: '.$e->getMessage());
      fail(500, 'Error interno', $e->getMessage());
    }
  }

  /* --- POST: resolver ticket --- */
  if ($method === 'POST' && $action === 'resolve') {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) fail(400, 'ticket_id inválido');

    $st = $pdo->prepare("UPDATE support_tickets SET status='resolved', updated_at=NOW() WHERE id=?");
    $st->execute([$ticketId]);

    respond(200, ['ok'=>true]);
  }

  // Método/acción no soportada
  fail(405, 'Method/Action not allowed');

} catch (\Throwable $e) {
  error_log('support_admin fatal: '.$e->getMessage());
  fail(500, 'Error interno', $e->getMessage());
}

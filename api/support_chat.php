<?php
// api/support_chat.php — Chat del lado usuario (JSON only)
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

/* ===== Utils ===== */
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

/** Devuelve true si la columna existe y es NOT NULL */
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

/* ===== Auth mínima ===== */
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) fail(401, 'No autenticado', 'NO_AUTH');

/* ===== Tickets ===== */
function ensureUserTicket(PDO $pdo, int $userId): ?array {
  // 1) Ticket abierto o pendiente
  $q = $pdo->prepare("SELECT * FROM support_tickets WHERE user_id=? AND status IN('open','pending') ORDER BY id DESC LIMIT 1");
  $q->execute([$userId]);
  $t = $q->fetch(PDO::FETCH_ASSOC);
  if ($t) return $t;

  // 2) Crear ticket adaptando al esquema (public_id NOT NULL, unread_user ausente, etc.)
  try {
    $pdo->beginTransaction();

    try { $pdo->exec("SET time_zone = '+00:00'"); } catch (\Throwable $tz) { /* ignore */ }

    $hasUnreadUser   = hasColumn($pdo, 'support_tickets', 'unread_user');
    $hasPublicId     = hasColumn($pdo, 'support_tickets', 'public_id');
    $publicNotNull   = $hasPublicId ? colIsNotNull($pdo, 'support_tickets', 'public_id') : false;

    // Armamos columnas/valores dinámicamente
    $cols = ['user_id','status','unread_admin','last_message_at','created_at','updated_at'];
    $vals = ['?','?','?','NOW()','NOW()','NOW()'];
    $args = [$userId, 'open', 0];

    if ($hasUnreadUser) {
      $cols[] = 'unread_user';
      $vals[] = '?';
      $args[] = 0;
    }

    $tmpPublic = null;
    if ($hasPublicId && $publicNotNull) {
      // Si public_id es NOT NULL, ponemos un valor temporal único para no violar la restricción
      $tmpPublic = 'TMP-'.bin2hex(random_bytes(5));
      $cols[] = 'public_id';
      $vals[] = '?';
      $args[] = $tmpPublic;
    }

    $sql = "INSERT INTO support_tickets (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
    $ins = $pdo->prepare($sql);
    if (!$ins->execute($args)) {
      $ei = $ins->errorInfo();
      throw new \PDOException($ei[2] ?? 'No se pudo insertar ticket');
    }

    $id = (int)$pdo->lastInsertId();
    if ($id <= 0) throw new \PDOException('ID de ticket inválido');

    // Actualizar public_id definitivo si la columna existe
    if ($hasPublicId) {
      $public = sprintf('ST-%06d', $id);
      $up = $pdo->prepare("UPDATE support_tickets SET public_id=? WHERE id=?");
      if (!$up->execute([$public, $id])) {
        $ei = $up->errorInfo();
        throw new \PDOException($ei[2] ?? 'No se pudo actualizar public_id');
      }
      $finalPublic = $public;
    } else {
      $finalPublic = null;
    }

    $pdo->commit();

    $now = date('Y-m-d H:i:s');
    // Construye el array sin SELECT adicional
    return [
      'id'              => $id,
      'public_id'       => $finalPublic,
      'user_id'         => $userId,
      'status'          => 'open',
      'unread_admin'    => 0,
      'unread_user'     => $hasUnreadUser ? 0 : null,
      'last_message_at' => $now,
      'created_at'      => $now,
      'updated_at'      => $now,
    ];
  } catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ensureUserTicket fail: '.$e->getMessage());
    return null;
  }
}

function requireTicketOrFail(?array $ticket): array {
  if (!$ticket || empty($ticket['id'])) {
    fail(500, 'No se pudo obtener/crear ticket', 'ticket null/invalid');
  }
  return $ticket;
}

/* ===== Router ===== */
try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = $_GET['action'] ?? '';

  /* --- GET: hilo --- */
  if ($method === 'GET' && $action === 'thread') {
    $ticket = ensureUserTicket($pdo, (int)$user_id);
    $ticket = requireTicketOrFail($ticket);

    $m = $pdo->prepare("
      SELECT sender, message, file_path, created_at
      FROM support_messages
      WHERE ticket_id=?
      ORDER BY id ASC
    ");
    $m->execute([(int)$ticket['id']]);
    $messages = $m->fetchAll(PDO::FETCH_ASSOC);

    // marcar leído para usuario (si existe la columna)
    if (hasColumn($pdo, 'support_tickets', 'unread_user')) {
      try {
        $pdo->prepare("UPDATE support_tickets SET unread_user=0 WHERE id=?")->execute([(int)$ticket['id']]);
      } catch (\Throwable $e) { /* ignore */ }
    }

    respond(200, [
      'ok'        => true,
      'ticket'    => $ticket['id'],
      'public_id' => $ticket['public_id'],
      'messages'  => $messages
    ]);
  }

  /* --- POST: enviar mensaje --- */
  if ($method === 'POST') {
    // Payload supera límites (post_max_size/upload_max_filesize)
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
      fail(413, 'El contenido excede el límite del servidor');
    }

    $message  = trim($_POST['message'] ?? '');
    $filePath = null;

    // Adjunto opcional
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
      $dest = $dir . uniqid('att_', true) . '.' . $ext;
      if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        fail(500, 'No se pudo guardar el adjunto');
      }
      $filePath = 'uploads/support/' . basename($dest);
    }

    // No permitir ambos vacíos
    if ($message === '' && !$filePath) fail(400, 'Mensaje vacío');

    // Ticket válido
    $ticket = ensureUserTicket($pdo, (int)$user_id);
    $ticket = requireTicketOrFail($ticket);

    // Para esquemas con message NOT NULL, usa '' si no hay texto
    $messageForDB = ($message !== '' ? $message : '');

    try {
      $pdo->beginTransaction();
      try { $pdo->exec("SET time_zone = '+00:00'"); } catch (\Throwable $tz) { /* ignore */ }

      // Inserta SIEMPRE created_at=NOW() (compatibilidad STRICT MODE)
      $ins = $pdo->prepare("
        INSERT INTO support_messages (ticket_id, sender, message, file_path, created_at)
        VALUES (?, 'user', ?, ?, NOW())
      ");
      $ok = $ins->execute([(int)$ticket['id'], $messageForDB, $filePath]);
      if (!$ok) {
        $ei = $ins->errorInfo();
        fail(500, 'Error interno', 'INSERT support_messages: '.($ei[2] ?? 'desconocido'));
      }

      // Actualizar ticket (si hay unread_admin)
      if (hasColumn($pdo, 'support_tickets', 'unread_admin')) {
        $upd = $pdo->prepare("
          UPDATE support_tickets
          SET unread_admin=1, last_message_at=NOW(), updated_at=NOW()
          WHERE id=?
        ");
      } else {
        $upd = $pdo->prepare("
          UPDATE support_tickets
          SET last_message_at=NOW(), updated_at=NOW()
          WHERE id=?
        ");
      }
      $upd->execute([(int)$ticket['id']]);

      $pdo->commit();
      respond(200, ['ok'=>true, 'ticket'=>$ticket['id']]);

    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('support_chat POST fail: '.$e->getMessage());
      fail(500, 'Error interno', $e->getMessage());
    }
  }

  // Método/acción no soportada
  fail(405, 'Method/Action not allowed');

} catch (\Throwable $e) {
  error_log('support_chat fatal: '.$e->getMessage());
  fail(500, 'Error interno', $e->getMessage());
}

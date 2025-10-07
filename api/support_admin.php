<?php
// api/support_admin.php — panel admin soporte (JSON puro)
declare(strict_types=1);

// Producción: no imprimir errores en HTML
ini_set('display_errors','0');
ini_set('html_errors','0');

// Captura cualquier salida temprana para luego limpiarla
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
ob_start();

session_start();
require_once __DIR__ . '/../db.php';

// ========== Utilidades JSON ==========

function json_respond(int $code, array $payload): void {
  http_response_code($code);
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
  }
  // Limpia TODOS los buffers para evitar HTML/warnings previos
  while (ob_get_level() > 0) { @ob_end_clean(); }
  // Asegura JSON aunque haya bytes UTF-8 inválidos
  $json = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
  );
  if ($json === false) {
    // Último recurso
    $json = '{"ok":false,"error":"JSON encode failed"}';
  }
  echo $json;
  exit;
}

function require_admin(PDO $pdo): array {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) json_respond(401, ['ok'=>false,'error'=>'No autenticado']);
  $st = $pdo->prepare("SELECT id, role, full_name, email FROM users WHERE id = ? LIMIT 1");
  $st->execute([$uid]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) json_respond(401, ['ok'=>false,'error'=>'Sesión inválida']);
  if (strtolower((string)$u['role']) !== 'admin') json_respond(403, ['ok'=>false,'error'=>'Acceso denegado']);
  return $u;
}

// ========== Inicio ==========

try {
  $admin = require_admin($pdo);

  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = $_GET['action'] ?? ($_POST['action'] ?? '');

  // -------- LISTA DE TICKETS --------
  if ($method === 'GET' && $action === 'list') {
    $status = $_GET['status'] ?? 'open';
    $status = in_array($status, ['open','resolved'], true) ? $status : 'open';

    if ($status === 'open') {
      // Abiertos = open + pending
      $sql = "
        SELECT t.id, t.public_id, t.user_id, t.status, t.unread_admin, t.last_message_at, t.created_at,
               u.full_name
        FROM support_tickets t
        JOIN users u ON u.id = t.user_id
        WHERE t.status IN ('open','pending')
        ORDER BY t.unread_admin DESC, t.last_message_at DESC, t.id DESC
        LIMIT 200
      ";
      $stmt = $pdo->query($sql);
    } else {
      // Resueltos
      $stmt = $pdo->prepare("
        SELECT t.id, t.public_id, t.user_id, t.status, t.unread_admin, t.last_message_at, t.created_at,
               u.full_name
        FROM support_tickets t
        JOIN users u ON u.id = t.user_id
        WHERE t.status = 'resolved'
        ORDER BY t.last_message_at DESC, t.id DESC
        LIMIT 200
      ");
      $stmt->execute();
    }

    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_respond(200, ['ok'=>true, 'tickets'=>$tickets]);
  }

  // -------- HILO DE UN TICKET --------
  if ($method === 'GET' && $action === 'thread') {
    $ticketId = (int)($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) json_respond(400, ['ok'=>false,'error'=>'ticket_id inválido']);

    $t = $pdo->prepare("
      SELECT t.id, t.public_id, t.user_id, t.status, t.unread_admin, t.last_message_at,
             t.created_at, t.updated_at, u.full_name
      FROM support_tickets t
      JOIN users u ON u.id = t.user_id
      WHERE t.id=? LIMIT 1
    ");
    $t->execute([$ticketId]);
    $ticket = $t->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) json_respond(404, ['ok'=>false,'error'=>'Ticket no encontrado']);

    // Hilo (incluye id para anti-duplicados en el front)
    $m = $pdo->prepare("
      SELECT id, sender, message, file_path, created_at
      FROM support_messages
      WHERE ticket_id=?
      ORDER BY id ASC
      LIMIT 2000
    ");
    $m->execute([$ticketId]);
    $messages = $m->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Marcar como leído para admin
    $pdo->prepare("UPDATE support_tickets SET unread_admin=0 WHERE id=?")->execute([$ticketId]);

    json_respond(200, ['ok'=>true, 'ticket'=>$ticket, 'messages'=>$messages]);
  }

  // -------- RESPONDER EN UN TICKET --------
  if ($method === 'POST' && $action === 'reply') {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $message  = trim((string)($_POST['message'] ?? ''));
    if ($ticketId <= 0) json_respond(400, ['ok'=>false,'error'=>'ticket_id inválido']);
    if ($message === '' && (empty($_FILES['file']) || $_FILES['file']['error']===UPLOAD_ERR_NO_FILE)) {
      json_respond(400, ['ok'=>false,'error'=>'Mensaje vacío']);
    }

    // Comprobar ticket
    $q = $pdo->prepare("SELECT id FROM support_tickets WHERE id=? LIMIT 1");
    $q->execute([$ticketId]);
    if (!$q->fetchColumn()) json_respond(404, ['ok'=>false,'error'=>'Ticket no encontrado']);

    // Subida de archivo (opcional)
    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
        json_respond(413, ['ok'=>false,'error'=>'Adjunto excede el límite']);
      }
      if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_respond(400, ['ok'=>false,'error'=>'Error al subir archivo']);
      }
      $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['png','jpg','jpeg','pdf'], true)) {
        json_respond(400, ['ok'=>false,'error'=>'Extensión no permitida']);
      }
      $dir = __DIR__ . '/../uploads/support/';
      if (!is_dir($dir)) @mkdir($dir, 0777, true);
      $dest = $dir . uniqid('att_', true) . '.' . $ext;
      if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        json_respond(500, ['ok'=>false,'error'=>'No se pudo guardar el adjunto']);
      }
      $filePath = 'uploads/support/' . basename($dest);
    }

    // Escribir mensaje
    $pdo->beginTransaction();
    try {
      try {
        $pdo->exec("SET SESSION time_zone = '+00:00'");
      } catch (Throwable $tz) {
        // Ignorar si el hosting lo bloquea
      }

      // Nunca insertar NULL en message si la columna es NOT NULL
      $msgToSave = ($message !== '') ? $message : '';

      $ins = $pdo->prepare("
        INSERT INTO support_messages (ticket_id, sender, message, file_path, created_at)
        VALUES (?, 'admin', ?, ?, NOW())
      ");
      $ins->execute([$ticketId, $msgToSave, $filePath]);

      // Actualizar ticket: unread_user (si existe) + last_message_at
      try {
        $pdo->prepare("
          UPDATE support_tickets
          SET unread_user=1, last_message_at=NOW(), updated_at=NOW()
          WHERE id=?
        ")->execute([$ticketId]);
      } catch (PDOException $e) {
        // Si la columna unread_user no existe en este hosting, actualizar sin esa columna
        if (stripos($e->getMessage(), 'Unknown column') !== false) {
          $pdo->prepare("
            UPDATE support_tickets
            SET last_message_at=NOW(), updated_at=NOW()
            WHERE id=?
          ")->execute([$ticketId]);
        } else {
          throw $e;
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('support_admin reply: '.$e->getMessage());
      json_respond(500, ['ok'=>false,'error'=>'Error interno al guardar el mensaje']);
    }

    json_respond(200, ['ok'=>true]);
  }

  // -------- RESOLVER TICKET --------
  if ($method === 'POST' && $action === 'resolve') {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) json_respond(400, ['ok'=>false,'error'=>'ticket_id inválido']);

    $pdo->prepare("UPDATE support_tickets SET status='resolved', updated_at=NOW() WHERE id=?")->execute([$ticketId]);

    json_respond(200, ['ok'=>true]);
  }

  // Acción no permitida
  json_respond(405, ['ok'=>false,'error'=>'Method/Action not allowed']);

} catch (Throwable $e) {
  error_log('support_admin fatal: '.$e->getMessage());
  json_respond(500, ['ok'=>false,'error'=>'Error interno']);
}

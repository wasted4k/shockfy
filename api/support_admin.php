<?php
// api/support_admin.php — panel admin soporte
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('html_errors','0');
header('Content-Type: application/json; charset=utf-8');
ob_start();

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_check.php';  // <- asume que aquí rellenas $currentUser

function respond(int $code, array $payload){
  http_response_code($code);
  if (ob_get_length() !== false) { ob_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// requiere rol admin
if (empty($currentUser['role']) || strtolower((string)$currentUser['role']) !== 'admin') {
  respond(403, ['ok'=>false,'error'=>'Acceso denegado']);
}

try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = $_GET['action'] ?? ($_POST['action'] ?? '');

  if ($method === 'GET' && $action === 'list') {
    $status = $_GET['status'] ?? 'open';
    if (!in_array($status, ['open','resolved'], true)) $status = 'open';

    $stmt = $pdo->prepare("
      SELECT t.id, t.public_id, t.user_id, t.status, t.unread_admin, t.last_message_at, t.created_at,
             u.full_name
      FROM support_tickets t
      JOIN users u ON u.id = t.user_id
      WHERE t.status = ?
      ORDER BY t.unread_admin DESC, t.last_message_at DESC, t.id DESC
      LIMIT 200
    ");
    $stmt->execute([$status]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, ['ok'=>true, 'tickets'=>$tickets]);
  }

  if ($method === 'GET' && $action === 'thread') {
    $ticketId = (int)($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) respond(400, ['ok'=>false,'error'=>'ticket_id inválido']);

    $t = $pdo->prepare("
      SELECT t.*, u.full_name
      FROM support_tickets t
      JOIN users u ON u.id = t.user_id
      WHERE t.id=? LIMIT 1
    ");
    $t->execute([$ticketId]);
    $ticket = $t->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) respond(404, ['ok'=>false,'error'=>'Ticket no encontrado']);

    // Hilo
    $m = $pdo->prepare("SELECT sender, message, file_path, created_at FROM support_messages WHERE ticket_id=? ORDER BY id ASC");
    $m->execute([$ticketId]);
    $messages = $m->fetchAll(PDO::FETCH_ASSOC);

    // admin leyó
    $pdo->prepare("UPDATE support_tickets SET unread_admin=0 WHERE id=?")->execute([$ticketId]);

    respond(200, ['ok'=>true, 'ticket'=>$ticket, 'messages'=>$messages]);
  }

  if ($method === 'POST' && $action === 'reply') {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $message  = trim($_POST['message'] ?? '');
    if ($ticketId <= 0) respond(400, ['ok'=>false,'error'=>'ticket_id inválido']);
    if ($message === '' && (empty($_FILES['file']) || $_FILES['file']['error']===UPLOAD_ERR_NO_FILE)) {
      respond(400, ['ok'=>false,'error'=>'Mensaje vacío']);
    }

    // comprobar ticket
    $q = $pdo->prepare("SELECT * FROM support_tickets WHERE id=? LIMIT 1");
    $q->execute([$ticketId]);
    $ticket = $q->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) respond(404, ['ok'=>false,'error'=>'Ticket no encontrado']);

    // adjunto
    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
        respond(413, ['ok'=>false,'error'=>'Adjunto excede el límite']);
      }
      if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        respond(400, ['ok'=>false,'error'=>'Error al subir archivo']);
      }
      $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['png','jpg','jpeg','pdf'], true)) {
        respond(400, ['ok'=>false,'error'=>'Extensión no permitida']);
      }
      $dir = __DIR__ . '/../uploads/support/';
      if (!is_dir($dir)) @mkdir($dir, 0777, true);
      $dest = $dir . uniqid('att_', true) . '.' . $ext;
      if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        respond(500, ['ok'=>false,'error'=>'No se pudo guardar el adjunto']);
      }
      $filePath = 'uploads/support/' . basename($dest);
    }

    $pdo->beginTransaction();
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $pdo->prepare("INSERT INTO support_messages (ticket_id, sender, message, file_path, created_at) VALUES (?, 'admin', ?, ?, NOW())")
        ->execute([$ticketId, $message ?: null, $filePath]);

    // marcar para usuario como no leído y actualizar last
    $pdo->prepare("UPDATE support_tickets SET unread_user=1, last_message_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$ticketId]);
    $pdo->commit();

    respond(200, ['ok'=>true]);
  }

  if ($method === 'POST' && $action === 'resolve') {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) respond(400, ['ok'=>false,'error'=>'ticket_id inválido']);

    $pdo->prepare("UPDATE support_tickets SET status='resolved', updated_at=NOW() WHERE id=?")->execute([$ticketId]);

    respond(200, ['ok'=>true]);
  }

  respond(405, ['ok'=>false,'error'=>'Method/Action not allowed']);
} catch (Throwable $e) {
  error_log('support_admin: '.$e->getMessage());
  respond(500, ['ok'=>false,'error'=>'Error interno']);
}

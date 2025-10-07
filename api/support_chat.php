<?php
// api/support_chat.php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

try {
  $root = dirname(__DIR__);
  $bootstrap = $root . '/bootstrap.php';
  if (file_exists($bootstrap)) { require_once $bootstrap; }
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('DB no inicializada');
  }

  // Autenticación de usuario (ajusta a tu sistema)
  $userId   = (int)($_SESSION['user_id'] ?? 0);
  $userName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Usuario'));
  if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit;
  }

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $action = $_GET['action'] ?? $_POST['action'] ?? '';

  // ========= GET: leer hilo del usuario (ticket abierto) =========
  if ($action === 'thread') {
    // Ticket abierto del usuario
    $st = $pdo->prepare("SELECT id, public_id, status, last_message_at, created_at FROM support_tickets WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1");
    $st->execute([$userId]);
    $tk = $st->fetch(PDO::FETCH_ASSOC);

    if (!$tk) {
      // Sin ticket abierto: responder ok pero vacío
      echo json_encode(['ok'=>true,'ticket'=>null,'messages'=>[]]);
      exit;
    }
    $ticketId = (int)$tk['id'];

    // Mensajes del ticket
    $st = $pdo->prepare("SELECT id, sender, message, file_path, created_at FROM support_messages WHERE ticket_id=? ORDER BY created_at ASC, id ASC");
    $st->execute([$ticketId]);
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC);

    // Marcar vistos por el usuario
    $pdo->prepare("UPDATE support_messages SET seen_by_user=1 WHERE ticket_id=?")->execute([$ticketId]);
    $pdo->prepare("UPDATE support_tickets SET unread_user=0 WHERE id=?")->execute([$ticketId]);

    echo json_encode(['ok'=>true,'ticket'=>$tk,'messages'=>$msgs]); exit;
  }

  // ========= POST: crear/usar ticket e insertar mensaje =========
  // Rate-limit básico
  $last = (int)($_SESSION['last_support_ts'] ?? 0);
  if (time() - $last < 3) { // lo bajo a 3s para pruebas
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'Demasiadas solicitudes; intenta en unos segundos.']); exit;
  }
  $_SESSION['last_support_ts'] = time();

  $MAX = 2 * 1024 * 1024;
  $msg = trim((string)($_POST['message'] ?? ''));
  $hasFile = !empty($_FILES['file']['name'] ?? '');
  if ($msg === '' && !$hasFile) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Escribe un mensaje o adjunta un archivo.']); exit;
  }

  // Subida (opcional)
  $filePath = null;
  if ($hasFile) {
    $err  = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_OK);
    $size = (int)($_FILES['file']['size'] ?? 0);
    if ($err !== UPLOAD_ERR_OK || $size <= 0 || $size > $MAX) {
      http_response_code(413);
      echo json_encode(['ok'=>false,'error'=>'Adjunto inválido o supera 2 MB.']); exit;
    }
    $allowed = ['image/png','image/jpeg','application/pdf'];
    $mime = (string)($_FILES['file']['type'] ?? '');
    if (!in_array($mime, $allowed, true)) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'Tipo de archivo no permitido.']); exit;
    }
    $dir = $root . '/uploads/support';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $fn  = 'u'.$userId.'_'.date('Ymd_His').'_'.($_FILES['file']['name'] ?? 'file');
    $fn  = preg_replace('/[^\w\.\-]+/u', '_', $fn);
    $dest= $dir . '/' . $fn;
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'No se pudo guardar el archivo.']); exit;
    }
    $filePath = '/uploads/support/' . $fn;
  }

  $pdo->beginTransaction();

  // Buscar ticket abierto
  $stmt = $pdo->prepare("SELECT id, public_id FROM support_tickets WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1");
  $stmt->execute([$userId]);
  $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$ticket) {
    $publicId = 'S-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare("INSERT INTO support_tickets(public_id, user_id, status, unread_admin, unread_user, last_message_at, created_at) VALUES(?, ?, 'open', 1, 0, NOW(), NOW())");
    $stmt->execute([$publicId, $userId]);
    $ticketId = (int)$pdo->lastInsertId();
  } else {
    $ticketId = (int)$ticket['id'];
    $publicId = (string)$ticket['public_id'];
    $pdo->prepare("UPDATE support_tickets SET last_message_at=NOW(), unread_admin=1 WHERE id=?")->execute([$ticketId]);
  }

  // Insertar mensaje
  $stmt = $pdo->prepare("INSERT INTO support_messages(ticket_id, sender, message, file_path, created_at, seen_by_admin) VALUES(?, 'user', ?, ?, NOW(), 0)");
  $stmt->execute([$ticketId, $msg, $filePath]);

  $pdo->commit();

  echo json_encode(['ok'=>true,'ticket_id'=>$publicId,'reply'=>"Gracias. Ticket $publicId creado/actualizado."]);
} catch (Throwable $e) {
  if (!headers_sent()) http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error interno']);
}

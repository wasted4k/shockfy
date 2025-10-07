<?php
// api/support_admin.php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

try {
  // 1) Bootstrap / DB / sesión
  $root = dirname(__DIR__);
  $bootstrap = $root . '/bootstrap.php';
  if (file_exists($bootstrap)) { require_once $bootstrap; }
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('DB no inicializada');
  }

  // 2) Autorización admin (ajusta a tu sistema)
  $isAdmin = !empty($_SESSION['is_admin']) || (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin');
  if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit;
  }

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $action = $_GET['action'] ?? $_POST['action'] ?? 'list';

  // Helpers
  $MAX = 2 * 1024 * 1024;
  $allowed = ['image/png','image/jpeg','application/pdf'];

  $uploadFile = function(array $file) use ($root, $MAX, $allowed): ?string {
    if (empty($file['name'])) return null;
    $err  = (int)($file['error'] ?? UPLOAD_ERR_OK);
    $size = (int)($file['size'] ?? 0);
    if ($err !== UPLOAD_ERR_OK || $size <= 0 || $size > $MAX) {
      throw new RuntimeException('Adjunto inválido o supera 2 MB');
    }
    $mime = (string)($file['type'] ?? '');
    if (!in_array($mime, $allowed, true)) {
      throw new RuntimeException('Tipo de archivo no permitido');
    }
    $dir = $root . '/uploads/support';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $fn  = 'a'.date('Ymd_His').'_'.$file['name'];
    $fn  = preg_replace('/[^\w\.\-]+/u', '_', $fn);
    $dest= $dir . '/' . $fn;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
      throw new RuntimeException('No se pudo guardar el archivo');
    }
    return '/uploads/support/' . $fn; // pública
  };

  if ($action === 'list') {
    $status = ($_GET['status'] ?? 'open');
    if (!in_array($status, ['open','resolved'], true)) $status = 'open';

    $sql = "SELECT t.id, t.public_id, t.user_id, t.status, t.unread_admin, t.unread_user, t.last_message_at, t.created_at,
                   u.full_name, u.email
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.status = :status
            ORDER BY t.unread_admin DESC, t.last_message_at DESC
            LIMIT 100";
    $st = $pdo->prepare($sql);
    $st->execute([':status'=>$status]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true,'tickets'=>$rows]); exit;
  }

  if ($action === 'thread') {
    $ticketId = (int)($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'ticket_id requerido']); exit; }

    // Ticket
    $st = $pdo->prepare("SELECT t.*, u.full_name, u.email FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=?");
    $st->execute([$ticketId]);
    $ticket = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Ticket no existe']); exit; }

    // Mensajes
    $st = $pdo->prepare("SELECT id, sender, message, file_path, created_at FROM support_messages WHERE ticket_id=? ORDER BY created_at ASC, id ASC");
    $st->execute([$ticketId]);
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC);

    // Marcar como leído por admin
    $pdo->prepare("UPDATE support_messages SET seen_by_admin=1 WHERE ticket_id=?")->execute([$ticketId]);
    $pdo->prepare("UPDATE support_tickets SET unread_admin=0 WHERE id=?")->execute([$ticketId]);

    echo json_encode(['ok'=>true,'ticket'=>$ticket,'messages'=>$msgs]); exit;
  }

  if ($action === 'reply') {
    // POST: ticket_id, message, file (opcional)
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($ticketId <= 0 || $msg === '') {
      http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Datos incompletos']); exit;
    }

    // Verificar que existe
    $st = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id=?");
    $st->execute([$ticketId]);
    $tk = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tk) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Ticket no existe']); exit; }

    $filePath = null;
    if (!empty($_FILES['file']['name'] ?? '')) {
      try { $filePath = $uploadFile($_FILES['file']); }
      catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
    }

    $pdo->beginTransaction();
    // Insertar respuesta admin
    $st = $pdo->prepare("INSERT INTO support_messages(ticket_id, sender, message, file_path, created_at, seen_by_user) VALUES(?, 'admin', ?, ?, NOW(), 0)");
    $st->execute([$ticketId, $msg, $filePath]);

    // Notificar al usuario (unread_user=1) y actualizar last_message_at
    $st = $pdo->prepare("UPDATE support_tickets SET last_message_at=NOW(), unread_user=1 WHERE id=?");
    $st->execute([$ticketId]);
    $pdo->commit();

    echo json_encode(['ok'=>true,'msg'=>'Respuesta enviada']); exit;
  }

  if ($action === 'resolve') {
    // POST: ticket_id
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'ticket_id requerido']); exit; }

    $st = $pdo->prepare("UPDATE support_tickets SET status='resolved', unread_user=1, updated_at=NOW() WHERE id=?");
    $st->execute([$ticketId]);

    echo json_encode(['ok'=>true,'msg'=>'Ticket marcado como resuelto']); exit;
  }

  // Si no coincide ninguna acción:
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Acción inválida']);
} catch (Throwable $e) {
  if (!headers_sent()) http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error interno']);
}

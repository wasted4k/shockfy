<?php

// declare(strict_types=1);
// api/support_chat.php — chat del lado usuario
ini_set('display_errors',1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


ini_set('html_errors','0');
header('Content-Type: application/json; charset=utf-8');
ob_start();

session_start();
require_once __DIR__ . '/../db.php';

function respond(int $code, array $payload){
  http_response_code($code);
  if (ob_get_length() !== false) { ob_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// requiere login de usuario normal
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { respond(401, ['ok'=>false,'error'=>'No autenticado']); }

// helpers
function ensureUserTicket(PDO $pdo, int $userId): ?array {
    // 1. Busca ticket abierto o pendiente
    // ¡Consulta preparada correctamente!
    $q = $pdo->prepare("SELECT * FROM support_tickets WHERE user_id = ? AND status IN('open','pending') ORDER BY id DESC LIMIT 1");
    $q->execute([$userId]);
    $existingTicket = $q->fetch(PDO::FETCH_ASSOC);
    
    if ($existingTicket) {
        return $existingTicket;
    }

    // ---
    
    // 2. Crea nuevo ticket
    $pdo->beginTransaction();
    try {
        // Mejor pasar la hora desde PHP en UTC para consistencia
        // o al menos mantener el exec dentro del try/catch.
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        
        $insertStmt = $pdo->prepare(
            "INSERT INTO support_tickets (user_id, status, unread_admin, last_message_at, created_at, updated_at) 
             VALUES (?, 'open', 0, NOW(), NOW(), NOW())"
        );
        
        // Verifica la inserción
        if (!$insertStmt->execute([$userId])) {
            throw new \PDOException("Falló la inserción del ticket.");
        }
        
        $id = (int)$pdo->lastInsertId();
        
        if ($id === 0) {
            throw new \PDOException("No se pudo obtener el ID de la última inserción.");
        }
        
        $publicId = sprintf('ST-%06d', $id);
        
        // Uso de consulta preparada para la actualización
        $updateStmt = $pdo->prepare("UPDATE support_tickets SET public_id = ? WHERE id = ?");
        
        if (!$updateStmt->execute([$publicId, $id])) {
            throw new \PDOException("Falló la actualización del public_id.");
        }

        $pdo->commit();
        
        // Evita el SELECT final: Construye el array manualmente o haz la SELECT.
        // Opción 1 (Más eficiente):
        $now = date('Y-m-d H:i:s'); // O usa una hora más precisa de PHP en UTC

        return [
            'id' => $id,
            'public_id' => $publicId,
            'user_id' => $userId,
            'status' => 'open',
            'unread_admin' => 0,
            'last_message_at' => $now,
            'created_at' => $now,
            'updated_at' => $now
            // ... otros campos que necesites
        ];

        // Opción 2 (Tu SELECT original, pero dentro del try/catch):
        /*
        $q = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
        */
        
    } catch (\PDOException $e) {
        // Si falló en cualquier punto, hace rollback
        $pdo->rollBack();
        // Loguea el error $e->getMessage()
        // Devuelve null o lanza una excepción para que el llamador sepa que falló
        return null; 
    }
}

try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = $_GET['action'] ?? '';

  if ($method == 'GET' && $action == 'thread') {
    
    // trae (o crea) ticket del usuario
    $ticket = ensureUserTicket($pdo, (int)$user_id);
    
    $m = $pdo->prepare("SELECT sender,message,file_path,created_at FROM support_messages WHERE ticket_id=? ORDER BY id ASC");
    $m->execute([(int)$ticket['id']]);
    $messages = $m->fetchAll(PDO::FETCH_ASSOC);

   

    // al leer, marca como leído para usuario
    $pdo->prepare("UPDATE support_tickets SET unread_user=0 WHERE id=?")->execute([(int)$ticket['id']]);

    respond(200, ['ok'=>true,'ticket'=>$ticket['id'],'public_id'=>$ticket['public_id'],'messages'=>$messages]);
  }

  if ($method === 'POST') {
    // valida post grande
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
      respond(413, ['ok'=>false,'error'=>'El contenido excede el límite del servidor']);
    }

    $message = trim($_POST['message'] ?? '');
    $filePath = null;

    

    // adjunto opcional
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

    if ($message === '' && !$filePath) {
      respond(400, ['ok'=>false,'error'=>'Mensaje vacío']);
    }

    // ticket y mensaje
    $ticket = ensureUserTicket($pdo, (int)$user_id);
    echo $message;
    die();
    $pdo->beginTransaction();
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $ins = $pdo->prepare("INSERT INTO support_messages (ticket_id, sender, message, file_path, created_at) VALUES (?, 'user', ?, ?, NOW())");
    $ins->execute([(int)$ticket['id'], $message ?: null, $filePath]);

    // marca para admin como no leído y actualiza last_message_at
    $pdo->prepare("UPDATE support_tickets SET unread_admin=1, last_message_at=NOW(), updated_at=NOW() WHERE id=?")->execute([(int)$ticket['id']]);
    $pdo->commit();

    respond(200, ['ok'=>true,'ticket'=>$ticket['id']]);
  }

  respond(405, ['ok'=>false,'error'=>'Method/Action not allowed']);
} catch (Throwable $e) {
  error_log('support_chat: '.$e->getMessage());
  respond(500, ['ok'=>false,'error'=>'Error interno']);
}

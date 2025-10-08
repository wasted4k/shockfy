<?php
// api/support_rating.php — Guardar calificación (1–5) de un ticket por el usuario
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../db.php';

function respond(int $code, array $payload): void {
  http_response_code($code);
  if (ob_get_length() !== false) { @ob_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}
function fail(int $code, string $msg): void { respond($code, ['ok'=>false,'error'=>$msg]); }
function hasColumn(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $pdo->prepare($sql); $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}

/* ===== Auth mínima ===== */
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) fail(401, 'No autenticado');

/* ===== Solo POST ===== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') fail(405, 'Method not allowed');

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$rating   = (int)($_POST['rating'] ?? 0);
if ($ticketId <= 0 || $rating < 1 || $rating > 5) fail(400, 'Parámetros inválidos');

try {
  $pdo->beginTransaction();
  try { $pdo->exec("SET time_zone = '+00:00'"); } catch (\Throwable $tz) {}

  // Verificar que el ticket sea del usuario
  $st = $pdo->prepare("SELECT id FROM support_tickets WHERE id=? AND user_id=? LIMIT 1");
  $st->execute([$ticketId, (int)$user_id]);
  if (!$st->fetchColumn()) {
    $pdo->rollBack();
    fail(403, 'No autorizado para calificar este ticket');
  }

  // Guardar rating si existe columna
  if (hasColumn($pdo, 'support_tickets', 'rating')) {
    $pdo->prepare("UPDATE support_tickets SET rating=?, updated_at=NOW() WHERE id=?")->execute([$rating, $ticketId]);
  }

  // Dejar traza como mensaje visible
  $msg = "CALIFICACIÓN: {$rating}/5";
  $pdo->prepare("INSERT INTO support_messages (ticket_id, sender, message, file_path, created_at)
                 VALUES (?, 'user', ?, NULL, NOW())")->execute([$ticketId, $msg]);

  // Marcar no leído para admin si existe
  if (hasColumn($pdo, 'support_tickets', 'unread_admin')) {
    $pdo->prepare("UPDATE support_tickets SET unread_admin=1, last_message_at=NOW() WHERE id=?")->execute([$ticketId]);
  } else {
    $pdo->prepare("UPDATE support_tickets SET last_message_at=NOW() WHERE id=?")->execute([$ticketId]);
  }

  $pdo->commit();
  respond(200, ['ok'=>true]);
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('support_rating fail: '.$e->getMessage());
  fail(500, 'Error interno');
}

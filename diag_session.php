<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';

$uid = $_SESSION['user_id'] ?? null;
$stateSess = $_SESSION['account_state'] ?? null;

$row = null;
if ($uid) {
  $st = $pdo->prepare("SELECT id, account_state FROM users WHERE id=?");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
}

header('Content-Type: application/json');
echo json_encode([
  'session_name' => session_name(),
  'session_id'   => session_id(),
  'user_id'      => $uid,
  'session_state'=> $stateSess,
  'db_state'     => $row['account_state'] ?? null,
], JSON_PRETTY_PRINT);

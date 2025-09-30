<?php
require 'db.php';
require 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: ajustes.php"); exit;
}

$user_id         = $_SESSION['user_id'];
$current_password= $_POST['current_password'] ?? '';
$new_password    = $_POST['new_password'] ?? '';
$confirm_password= $_POST['confirm_password'] ?? '';

// Validaciones básicas
if ($new_password === '' || strlen($new_password) < 8) {
  $_SESSION['ajustes_error'] = "La nueva contraseña debe tener al menos 8 caracteres.";
  header("Location: ajustes.php"); exit;
}
if ($new_password !== $confirm_password) {
  $_SESSION['ajustes_error'] = "Las contraseñas no coinciden.";
  header("Location: ajustes.php"); exit;
}

// Verificar contraseña actual
$stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$hash = $stmt->fetchColumn();
if (!$hash || !password_verify($current_password, $hash)) {
  $_SESSION['ajustes_error'] = "La contraseña actual no es correcta.";
  header("Location: ajustes.php"); exit;
}

// Actualizar
$new_hash = password_hash($new_password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
$stmt->execute([':p' => $new_hash, ':id' => $user_id]);

$_SESSION['ajustes_success'] = "Tu contraseña se actualizó correctamente.";
header("Location: ajustes.php"); exit;

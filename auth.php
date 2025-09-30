<?php
// auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ... resto de tu archivo


// Si no hay sesión activa, redirige a login
if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

// Conectamos a la base de datos
require 'db.php';

// Traemos los datos completos del usuario
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$user){
    // Usuario no encontrado, destruimos sesión y redirigimos
    session_destroy();
    header("Location: login.php");
    exit;
}

// Ahora $user contiene todos los datos del usuario, incluyendo $user['id'], $user['username'], etc.

<?php
// logout.php
session_start();        // Iniciamos sesión
session_unset();        // Limpiamos todas las variables de sesión
session_destroy();      // Destruye la sesión
header("Location: login.php"); // Redirige al login
exit;
?>

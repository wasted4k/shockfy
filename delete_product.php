<?php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email

// Obtener el ID del producto de la URL
$id = intval($_GET['id'] ?? 0);

if ($id > 0) {
    try {
        // 1. Eliminar las ventas asociadas al producto
        $stmt = $pdo->prepare("DELETE FROM sales WHERE product_id = ?");
        $stmt->execute([$id]);

        // 2. Eliminar el producto de la tabla products
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);

        // Redirigir con mensaje de éxito
        header("Location: products.php?message=Producto eliminado correctamente");
        exit;
    } catch (PDOException $e) {
        // En caso de error, mostrar mensaje
        echo "Error al eliminar el producto: " . $e->getMessage();
    }
} else {
    echo "ID de producto no válido.";
}
?>

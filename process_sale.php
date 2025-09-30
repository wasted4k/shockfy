<?php
// process_sale.php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
require 'auth.php'; // requiere iniciar sesión

// Obtener id del usuario logueado
$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id <= 0) {
    header('Location: sell.php?error=Usuario no identificado');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$product_id = intval($_POST['product_id'] ?? 0);
$quantity   = intval($_POST['quantity'] ?? 0);
$unit_price = floatval($_POST['unit_price'] ?? 0);

if ($product_id <= 0 || $quantity <= 0) {
    header('Location: sell.php?error=Datos inválidos');
    exit;
}

try {
    // Iniciar transacción
    $pdo->beginTransaction();

    // Verificar si el producto existe en la tabla products
    $stmt = $pdo->prepare('SELECT id, stock, sale_price FROM products WHERE id = ? FOR UPDATE');
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        $pdo->rollBack();
        header('Location: sell.php?error=Producto no encontrado');
        exit;
    }

    // Validar stock disponible
    if ($product['stock'] < $quantity) {
        $pdo->rollBack();
        header('Location: sell.php?error=Stock insuficiente (hay ' . $product['stock'] . ' unidades)');
        exit;
    }

    // Actualizar el stock
    $stmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
    $stmt->execute([$quantity, $product_id]);

    // Calcular el total
    $total = round($quantity * $unit_price, 2);

    // Insertar venta en la tabla sales
    $stmt = $pdo->prepare('INSERT INTO sales (product_id, user_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$product_id, $user_id, $quantity, $unit_price, $total]);

    // Confirmar la transacción
    $pdo->commit();
    header('Location: sell.php?msg=Venta%20registrada%20correctamente');
    exit;

} catch (Exception $e) {
    // Rollback si hay algún error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exit('Error al procesar la venta: ' . $e->getMessage());
}

<?php
// delete_sale.php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
require 'auth.php'; // requiere iniciar sesion



if (!empty($_GET['id'])) {
    $id = intval($_GET['id']);

    // 1️⃣ Buscar la venta antes de eliminarla
    $stmt = $pdo->prepare("SELECT product_id, quantity FROM sales WHERE id = ?");
    $stmt->execute([$id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sale) {
        $productId = (int)$sale['product_id'];
        $quantity  = (int)$sale['quantity'];

        // 2️⃣ Devolver la cantidad al inventario
        $update = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $update->execute([$quantity, $productId]);

        // 3️⃣ Eliminar la venta
        $delete = $pdo->prepare("DELETE FROM sales WHERE id = ?");
        $delete->execute([$id]);
    }
}

header('Location: index.php?msg=Venta eliminada correctamente');
exit;

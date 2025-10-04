<?php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
require 'auth.php'; // requiere iniciar sesion

$id = intval($_GET['id'] ?? 0);
$product = $pdo->prepare("SELECT * FROM products WHERE id=?");
$product->execute([$id]);
$p = $product->fetch();

if(!$p){ exit("Producto no encontrado"); }

// Mantenemos igual (sin tocar tu lógica): categorías globales
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$errors = [];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $name  = trim($_POST['name']);
    $size  = trim($_POST['size']);
    $color = trim($_POST['color']);
    $cost  = floatval($_POST['cost_price']);
    $sale  = floatval($_POST['sale_price']);
    $stock = intval($_POST['stock']);
    $category_id = intval($_POST['category_id']);

    if($name === '') $errors[] = "El nombre es obligatorio.";
    if($category_id === 0) $errors[] = "Debe seleccionar una categoría.";

    // Por defecto, mantener imagen actual
    $image_path = $p['image'];

    // Si suben una nueva imagen, mantenemos tu misma lógica
    if(isset($_FILES['image']) && $_FILES['image']['name'] !== ''){
        $allowed_ext = ['jpg','jpeg','png','gif'];
        $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

        if(!in_array($file_ext, $allowed_ext)){
            $errors[] = "Formato de imagen no permitido.";
        } else {
            $uploadDir = 'uploads/';
            if(!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $new_name = $uploadDir . uniqid('prod_', true) . '.' . $file_ext;
            if(!move_uploaded_file($_FILES['image']['tmp_name'], $new_name)){
                $errors[] = "Error al subir la imagen.";
            } else {
                if($p['image'] && file_exists($p['image'])) @unlink($p['image']);
                $image_path = $new_name;
            }
        }
    }

    if(empty($errors)){
        $stmt = $pdo->prepare("UPDATE products SET name=?, size=?, color=?, cost_price=?, sale_price=?, stock=?, category_id=?, image=? WHERE id=?");
        $stmt->execute([$name,$size,$color,$cost,$sale,$stock,$category_id,$image_path,$id]);
        header("Location: products.php?message=" . urlencode("Producto actualizado"));
        exit;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Editar producto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- importante para móvil -->
  <link rel="stylesheet" href="style.css">
  <style>
    :root{
      --bg:#eef3f8;
      --panel:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --primary:#2563eb;
      --primary-2:#60a5fa;
      --danger:#e11d48;
      --border:#e2e8f0;
      --shadow:0 10px 24px rgba(15,23,42,.06);
      --radius:16px;
    }
    *{box-sizing:border-box}
    html,body{overflow-x:hidden;}
    body{
      margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      background:linear-gradient(180deg,#fff,#f9fbff 45%,var(--bg));color:var(--text)
    }
    a{color:inherit;text-decoration:none}

    /* Sidebar-aware: empuja en desktop, no en móvil */
    .page{
      padding:24px 20px 64px;
      transition: margin-left .3s ease;
    }
    .sidebar ~ .page{ margin-left:78px; }
    .sidebar.open ~ .page{ margin-left:250px; }
    @media (max-width:1024px){
      .sidebar ~ .page, .sidebar.open ~ .page{ margin-left:0; padding:20px 16px 72px; }
    }

    .header{
      max-width:980px;margin:0 auto 16px;
      display:flex;align-items:center;gap:14px;
    }
    .header .icon{
      width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#e0edff,#f1f7ff);
      display:grid;place-items:center;border:1px solid #dbeafe;box-shadow:var(--shadow)
    }
    .header h1{margin:0;font-size:22px;font-weight:800;line-height:1.2}
    .subtitle{font-size:13px;color:var(--muted);margin-top:2px}
    @media (max-width:640px){
      .header{ gap:12px; }
      .header h1{ font-size:20px; }
    }

    .card{
      max-width:980px;margin:0 auto;background:var(--panel);
      border:1px solid var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow);overflow:hidden
    }
    .card-head{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      padding:14px 16px;background:linear-gradient(180deg,#ffffff,#f7fafc);border-bottom:1px solid var(--border)
    }
    .card-body{padding:18px}

    /* Formulario premium */
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .form-row{display:flex;flex-direction:column;gap:6px}
    .label{font-size:13px;font-weight:700;color:#0b1220}
    .input, .select{
      border:1px solid var(--border);border-radius:12px;background:#fff;color:var(--text);
      padding:10px 12px;outline:none;font-size:14px;transition:border .2s ease, box-shadow .2s ease
    }
    .input:focus, .select:focus{border-color:#bfdbfe;box-shadow:0 0 0 4px rgba(191,219,254,.4)}
    /* Evita zoom iOS */
    @media (max-width:640px){
      .input, .select{ font-size:16px; }
    }

    /* Bloque imagen */
    .image-section{
      margin-top:8px;display:grid;grid-template-columns: 160px 1fr;gap:16px;align-items:start
    }
    .image-preview{
      width:160px;height:140px;border:1px solid var(--border);border-radius:12px;
      background:linear-gradient(180deg,#fbfdff,#f2f6ff);display:grid;place-items:center;overflow:hidden
    }
    .image-preview img{max-width:100%;max-height:100%;object-fit:contain}
    .image-actions{display:flex;gap:10px;flex-wrap:wrap}
    .btn{padding:10px 14px;border-radius:12px;border:none;cursor:pointer;font-weight:700}
    .btn.primary{background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff}
    .btn.ghost{background:#fff;color:#0f172a;border:1px solid var(--border)}
    .btn.danger{background:#fff;color:#b91c1c;border:1px solid #fecaca}
    .actions{margin-top:18px;display:flex;gap:10px;justify-content:center}

    /* Errores */
    .alert{
      padding:12px 16px;border-radius:12px;margin-bottom:12px;font-weight:600;
      border:1px solid #fecaca;background:#fef2f2;color:#991b1b
    }

    @media (max-width:900px){
      .form-grid{grid-template-columns:1fr}
      .image-section{grid-template-columns:1fr}
    }
    @media (max-width:560px){
      .image-preview{ width:100%; height:200px; }
      .btn{ width:100%; text-align:center; }
    }
  </style>
</head>
<body>
  <?php include 'sidebar.php'; ?>

  <div class="page">
    <!-- Header -->
    <div class="header">
      <div class="icon">
        <!-- SVG Edit -->
        <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" aria-hidden="true">
          <path d="M12 20h9"/>
          <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/>
        </svg>
      </div>
      <div>
        <h1>Editar producto</h1>
        <div class="subtitle">Actualiza datos, precio, stock y categoría. La función se mantiene intacta.</div>
      </div>
    </div>

    <!-- Card -->
    <div class="card">
      <div class="card-head">
        <div style="font-size:12px;color:#64748b">ID #<?= htmlspecialchars($p['id']) ?></div>
      </div>

      <div class="card-body">
        <?php if(!empty($errors)): ?>
          <div class="alert">
            <?php foreach($errors as $e): ?>
              <div>• <?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="editForm">
          <div class="form-grid">
            <div class="form-row">
              <label class="label">Nombre de la prenda</label>
              <input class="input" name="name" value="<?= htmlspecialchars($p['name']) ?>" required>
            </div>

            <div class="form-row">
              <label class="label">Categoría</label>
              <select class="select" name="category_id" required>
                <option value="">-- Seleccione categoría --</option>
                <?php foreach($categories as $cat): ?>
                  <option value="<?= $cat['id'] ?>" <?= ($p['category_id']==$cat['id']?'selected':'') ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-row">
              <label class="label">Talla</label>
              <input class="input" name="size" value="<?= htmlspecialchars($p['size']) ?>">
            </div>

            <div class="form-row">
              <label class="label">Color</label>
              <input class="input" name="color" value="<?= htmlspecialchars($p['color']) ?>">
            </div>

            <div class="form-row">
              <label class="label">Precio de costo</label>
              <input class="input" name="cost_price" type="number" step="0.01" value="<?= htmlspecialchars($p['cost_price']) ?>" required>
            </div>

            <div class="form-row">
              <label class="label">Precio de venta</label>
              <input class="input" name="sale_price" type="number" step="0.01" value="<?= htmlspecialchars($p['sale_price']) ?>" required>
            </div>

            <div class="form-row">
              <label class="label">Stock</label>
              <input class="input" name="stock" type="number" value="<?= htmlspecialchars($p['stock']) ?>" required>
            </div>

            <div class="form-row">
              <label class="label">Imagen del producto</label>
              <input class="input" type="file" name="image" id="imageInput" accept="image/*">
            </div>
          </div>

          <!-- Sección de imagen -->
          <div class="image-section">
            <div class="image-preview" id="previewBox">
              <?php if($p['image'] && file_exists($p['image'])): ?>
                <img src="<?= htmlspecialchars($p['image']) ?>" alt="Imagen actual" id="previewImg">
              <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="40" height="40" aria-hidden="true">
                  <rect x="3" y="3" width="18" height="14" rx="2"></rect>
                  <path d="m3 13 4-4 3 3 5-5 4 4"/>
                </svg>
              <?php endif; ?>
            </div>
            <div class="image-actions">
              <button type="button" class="btn ghost" id="btnChoose">Seleccionar imagen</button>
              <button type="button" class="btn danger" id="btnClear">Quitar imagen seleccionada</button>
              <a href="products.php" class="btn ghost">Cancelar</a>
              <button type="submit" class="btn primary">Guardar cambios</button>
            </div>
          </div>

          <!-- Botonera secundaria (si algún tema oculta la superior, puedes usar esta) -->
          <div class="actions" style="display:none">
            <a href="products.php" class="btn ghost">Cancelar</a>
            <button type="submit" class="btn primary">Guardar cambios</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    // Mantener modo oscuro si estaba activo
    (function(){
      if(localStorage.getItem('darkMode')==='true'){
        document.body.classList.add('dark');
      }
    })();

    // Preview de imagen + limpiar selección (sin tocar backend)
    const imageInput = document.getElementById('imageInput');
    const previewBox = document.getElementById('previewBox');
    const btnChoose  = document.getElementById('btnChoose');
    const btnClear   = document.getElementById('btnClear');

    btnChoose.addEventListener('click', () => imageInput.click());

    imageInput.addEventListener('change', () => {
      const file = imageInput.files && imageInput.files[0];
      if(!file){ return; }
      const reader = new FileReader();
      reader.onload = (e) => {
        let img = previewBox.querySelector('img');
        if(!img){
          img = document.createElement('img');
          previewBox.innerHTML = '';
          previewBox.appendChild(img);
        }
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);
    });

    btnClear.addEventListener('click', () => {
      // Limpia únicamente la selección actual del input file (no borra la imagen existente del producto)
      imageInput.value = '';
      const existingImg = previewBox.querySelector('img');
      if(existingImg){
        if(existingImg.src.startsWith('data:')){
          previewBox.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="40" height="40" aria-hidden="true">
              <rect x="3" y="3" width="18" height="14" rx="2"></rect>
              <path d="m3 13 4-4 3 3 5-5 4 4"/>
            </svg>`;
        }
      }
    });
  </script>
</body>
</html>

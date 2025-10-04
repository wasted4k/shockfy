<?php
// add_product.php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
$user_id = $_SESSION['user_id']; // el ID del usuario que está logueado

// Traer todas las categorías
$categories = $pdo->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name");
$categories->execute([$user_id]);
$categories = $categories->fetchAll();

// traer moneda para solo mostrar símbolo (no cambia backend)
$currencyStmt = $pdo->prepare('SELECT currency_pref FROM users WHERE id = ?');
$currencyStmt->execute([$_SESSION['user_id']]);
$currency = $currencyStmt->fetchColumn() ?: 'S/.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code  = trim($_POST['code'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    $size  = trim($_POST['size'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $cost  = floatval($_POST['cost_price'] ?? 0);
    $sale  = floatval($_POST['sale_price'] ?? 0);
    $stock = intval($_POST['stock'] ?? 0);
    $category_id = intval($_POST['category_id'] ?? 0);

    if ($name === '') {
        header('Location: add_product.php?error=El nombre es obligatorio');
        exit;
    }
    if ($category_id === 0) {
        header('Location: add_product.php?error=Debe seleccionar una categoría');
        exit;
    }

    // Procesar imagen si se sube
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['image']['tmp_name'];
        $originalName = basename($_FILES['image']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $allowed = ['jpg','jpeg','png','gif'];
        if (!in_array($ext, $allowed)) {
            header('Location: add_product.php?error=Formato de imagen no permitido');
            exit;
        }

        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $newName = uniqid('prod_', true) . '.' . $ext;
        $imagePath = $uploadDir . $newName;

        move_uploaded_file($tmpName, $imagePath);
    }

    // INSERT del producto incluyendo la imagen y el usuario logueado
    $sql = 'INSERT INTO products (code, name, size, color, cost_price, sale_price, stock, category_id, image, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute([$code ?: null, $name, $size, $color, $cost, $sale, $stock, $category_id, $imagePath, $user_id]);
        header('Location: index.php?msg=Producto agregado correctamente');
        exit;
    } catch (Exception $e) {
        header('Location: add_product.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Agregar producto</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
    <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root{
            --bg:#f0f4f9;
            --panel:#ffffff;
            --panel-2:#f2f5f9;
            --text:#0f172a;
            --muted:#64748b;
            --primary:#2563eb;
            --primary-2:#60a5fa;
            --success:#16a34a;
            --danger:#dc2626;
            --border:#e2e8f0;
            --shadow:0 10px 24px rgba(15,23,42,.06);
            --radius:16px;
        }
        *{box-sizing:border-box}
        body{margin:0; font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--text);}

        .page{ padding:24px 18px 64px; }
        .container{ max-width:1000px; margin:0 auto; }

        .header{
            display:flex; align-items:center; justify-content:space-between; gap:16px; margin:8px 0 18px;
        }
        .title{ display:flex; align-items:center; gap:14px; }
        .title .icon{
            width:48px;height:48px;border-radius:12px;
            background:linear-gradient(135deg,#e0edff,#f1f7ff);display:grid;place-items:center;
            border:1px solid #dbeafe; box-shadow:var(--shadow)
        }
        .title h1{ margin:0; font-size:26px; font-weight:800; color:#0b1220; }
        .subtitle{ font-size:13px; color:var(--muted); margin-top:4px; }

        .card{
            background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
            box-shadow:var(--shadow); overflow:hidden;
        }
        .card-header{
            display:flex; align-items:center; justify-content:space-between; gap:12px;
            padding:14px 16px; background:linear-gradient(180deg,#ffffff,#f7fafc); border-bottom:1px solid var(--border);
        }
        .card-title{ font-size:14px; font-weight:800; }
        .card-body{ padding:16px; }

        .form-grid{
            display:grid; grid-template-columns:1fr 1fr; gap:14px;
        }
        .form-col-span-2{ grid-column: span 2; }

        label{font-weight:600; font-size:13px; color:#0f172a;}
        .field{
            margin-top:6px; display:flex; align-items:center; gap:8px;
            background:#fff; border:1px solid var(--border); border-radius:12px; padding:10px 12px;
        }
        .field input[type="text"],
        .field input[type="number"],
        .field input[type="file"],
        .field select{
            border:none; outline:none; background:transparent; width:100%; color:var(--text); font-size:14px;
        }
        .prefix{
            font-size:12px; color:#475569; background:var(--panel-2); padding:4px 8px; border-radius:8px; border:1px solid var(--border);
        }
        .hint{ font-size:12px; color:var(--muted); margin-top:6px; }

        .image-uploader{
            display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;
        }
        .image-preview{
            width:140px; height:140px; border-radius:14px; background:#f1f5f9; border:1px solid var(--border);
            display:grid; place-items:center; overflow:hidden;
        }
        .image-preview img{ width:100%; height:100%; object-fit:cover; }
        .image-actions{ display:flex; gap:8px; flex-wrap:wrap; }

        .btn{
            padding:10px 14px; border-radius:12px; border:1px solid var(--border);
            background:#fff; font-weight:800; cursor:pointer; box-shadow:var(--shadow);
            transition: transform .15s ease, background .15s ease, border-color .15s ease;
        }
        .btn:hover{ transform: translateY(-1px); background:#f5f7fb; }
        .btn.primary{ background:linear-gradient(135deg,var(--primary),var(--primary-2)); color:#fff; border:none; }
        .btn.primary:hover{ filter:brightness(.98); }

        /* Mejora contraste botones ghost */
        .btn.ghost{ background: var(--panel-2); border-color:#cfd7e3; color: var(--text); }
        .btn.ghost:hover{ background:#e8edf4; border-color:#b8c3d4; }
        body.dark .btn.ghost{ background:#0e1630; border-color:#1f2a4a; color:#e5e7eb; }
        body.dark .btn.ghost:hover{ background:#132146; border-color:#33416b; }

        .btn.danger{ background:linear-gradient(135deg,#ef4444,#f87171); color:#fff; border:none; }

        .actions{
            display:flex; align-items:center; gap:10px; justify-content:flex-end; margin-top:8px;
        }

        /* Toast */
        #toast{
            position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
            background:#ecfdf5; border:1px solid #d1fae5; color:#065f46;
            padding:12px 16px; border-radius:12px; box-shadow:var(--shadow);
            font-weight:800; opacity:0; pointer-events:none; transition:opacity .25s ease; z-index:2000;
        }
        #toast.show{ opacity:1; pointer-events:auto; }

        /* ====== Responsive ====== */
        @media (max-width: 1024px){
          .page{ padding:20px 14px 72px; }
          .container{ max-width:100%; }
        }
        @media (max-width: 900px){
            .form-grid{ grid-template-columns:1fr; }
            .form-col-span-2{ grid-column: auto; }
        }
        @media (max-width: 640px){
            .card-body{ padding:14px; }
            .title h1{ font-size:22px; }
            .actions{ flex-direction:column; align-items:stretch; }
            .btn{ width:100%; text-align:center; }
            .image-uploader{ flex-direction:column; }
            .image-preview{ width:100%; max-width:360px; height:200px; }
            .field input[type="text"],
            .field input[type="number"],
            .field input[type="file"],
            .field select{ font-size:16px; } /* evita zoom iOS */
        }
        @media (max-width: 380px){
            .page{ padding:16px 10px 68px; }
            .title h1{ font-size:20px; }
        }

        /* Modo oscuro coherente */
        body.dark{ background:#0c1326; color:#e5e7eb; }
        body.dark .card, body.dark .field{ background:#0b1220; border-color:#1f2a4a; }
        body.dark .card-header{ background:#0e1630; }
        body.dark .prefix{ background:#0e1630; border-color:#1f2a4a; color:#9fb1ff; }
        body.dark .btn{ background:#0e1630; border-color:#2a365a; color:#e5e7eb; }
        body.dark .btn:hover{ background:#132146; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="page">
      <div class="container">
        <div class="header">
            <div class="title">
                <div class="icon">
                    <!-- SVG etiqueta/producto -->
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                      <path d="M3 7v10a2 2 0 0 0 2 2h11l4-4V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2Z" stroke="#2563eb" stroke-width="2" />
                      <path d="M16 19v-4h4" stroke="#60a5fa" stroke-width="2" />
                      <circle cx="8" cy="10" r="1" fill="#2563eb"/>
                      <circle cx="12" cy="10" r="1" fill="#2563eb"/>
                      <circle cx="16" cy="10" r="1" fill="#2563eb"/>
                    </svg>
                </div>
                <div>
                    <h1>Agregar producto</h1>
                    <div class="subtitle">Completa los campos y guarda. La imagen es opcional.</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">Formulario</div>
                <div class="subtitle">Los campos obligatorios están marcados</div>
            </div>
            <div class="card-body">

                <?php if(!empty($_GET['error'])): ?>
                    <div id="errorBox" class="hint" style="background:#fef2f2;border:1px solid #fee2e2;color:#991b1b;padding:10px 12px;border-radius:12px;margin-bottom:12px;font-weight:700;">
                        <?= htmlspecialchars($_GET['error']) ?>
                    </div>
                <?php endif; ?>

                <!-- IMPORTANTE: no cambiar names ni método/enctype -->
                <form method="post" enctype="multipart/form-data" id="productForm" novalidate>

                    <div class="form-grid">
                        <!-- Código -->
                        <div>
                            <label for="code">Código (opcional)</label>
                            <div class="field">
                                <input id="code" name="code" type="text" placeholder="ABC-123">
                            </div>
                        </div>

                        <!-- Nombre -->
                        <div>
                            <label for="name">Nombre de la prenda *</label>
                            <div class="field">
                                <input id="name" name="name" type="text" required placeholder="Ej: Polo básico">
                            </div>
                            <div class="hint">Requerido.</div>
                        </div>

                        <!-- Talla -->
                        <div>
                            <label for="size">Talla</label>
                            <div class="field">
                                <input id="size" name="size" type="text" placeholder="S, M, L, 32, 34...">
                            </div>
                        </div>

                        <!-- Color -->
                        <div>
                            <label for="color">Color</label>
                            <div class="field">
                                <input id="color" name="color" type="text" placeholder="Negro, Azul...">
                            </div>
                        </div>

                        <!-- Costo -->
                        <div>
                            <label for="cost_price">Precio de costo *</label>
                            <div class="field">
                                <span class="prefix"><?= htmlspecialchars($currency) ?></span>
                                <input id="cost_price" name="cost_price" type="number" inputmode="decimal" step="0.01" value="0.00" required>
                            </div>
                        </div>

                        <!-- Venta -->
                        <div>
                            <label for="sale_price">Precio de venta *</label>
                            <div class="field">
                                <span class="prefix"><?= htmlspecialchars($currency) ?></span>
                                <input id="sale_price" name="sale_price" type="number" inputmode="decimal" step="0.01" value="0.00" required>
                            </div>
                        </div>

                        <!-- Stock -->
                        <div>
                            <label for="stock">Stock inicial *</label>
                            <div class="field">
                                <input id="stock" name="stock" type="number" step="1" value="0" required>
                            </div>
                        </div>

                        <!-- Categoría -->
                        <div>
                            <label for="category_id">Categoría *</label>
                            <div class="field">
                                <select id="category_id" name="category_id" required>
                                    <option value="">-- Seleccione categoría --</option>
                                    <?php foreach($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Imagen -->
                        <div class="form-col-span-2">
                            <label for="image">Imagen del producto (opcional)</label>
                            <div class="image-uploader">
                                <div class="image-preview" id="imagePreview">
                                    <!-- Placeholder SVG -->
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                                        <rect x="3" y="3" width="18" height="14" rx="3" stroke="#94a3b8" stroke-width="2"/>
                                        <path d="M3 14l4-4 4 4 3-3 4 3" stroke="#94a3b8" stroke-width="2" fill="none"/>
                                        <circle cx="15.5" cy="8.5" r="1.5" fill="#94a3b8"/>
                                    </svg>
                                </div>
                                <div style="flex:1; min-width:220px;">
                                    <div class="field">
                                        <input id="image" type="file" name="image" accept="image/*">
                                    </div>
                                    <div class="image-actions">
                                        <button type="button" class="btn ghost" id="btnRemoveImage" style="display:none;">Quitar imagen</button>
                                        <div class="hint">Formatos permitidos: JPG, PNG, GIF. Tamaño razonable recomendado.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="actions">
                        <a href="index.php" class="btn ghost">Cancelar</a>
                        <button type="submit" class="btn primary">Guardar producto</button>
                    </div>
                </form>
            </div>
        </div>
      </div>
    </div>

    <div id="toast" role="status" aria-live="polite"></div>

    <script>
      // Prefiere la apariencia dark si ya está en localStorage (coherente con tu app)
      window.addEventListener('load', () => {
        if(localStorage.getItem('darkMode') === 'true'){
            document.body.classList.add('dark');
        }
      });

      // Toast helper
      function showToast(msg){
        const el = document.getElementById('toast');
        el.textContent = msg;
        el.classList.add('show');
        setTimeout(() => el.classList.remove('show'), 2500);
      }

      // Previsualización de imagen
      const inputImage = document.getElementById('image');
      const preview = document.getElementById('imagePreview');
      const btnRemove = document.getElementById('btnRemoveImage');

      inputImage.addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        if(!file){ resetPreview(); return; }
        const url = URL.createObjectURL(file);
        renderPreview(url);
      });

      btnRemove.addEventListener('click', () => {
        inputImage.value = '';
        resetPreview();
      });

      function renderPreview(src){
        preview.innerHTML = '';
        const img = document.createElement('img');
        img.src = src;
        preview.appendChild(img);
        btnRemove.style.display = 'inline-flex';
      }
      function resetPreview(){
        preview.innerHTML = `
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
            <rect x="3" y="3" width="18" height="14" rx="3" stroke="#94a3b8" stroke-width="2"/>
            <path d="M3 14l4-4 4 4 3-3 4 3" stroke="#94a3b8" stroke-width="2" fill="none"/>
            <circle cx="15.5" cy="8.5" r="1.5" fill="#94a3b8"/>
          </svg>
        `;
        btnRemove.style.display = 'none';
      }

      // Validación rápida de requeridos en cliente (no sustituye backend)
      const form = document.getElementById('productForm');
      form.addEventListener('submit', (e) => {
        const name = document.getElementById('name').value.trim();
        const cost = parseFloat(document.getElementById('cost_price').value || '0');
        const sale = parseFloat(document.getElementById('sale_price').value || '0');
        const cat  = document.getElementById('category_id').value;

        if(!name){
          e.preventDefault(); showToast('El nombre es obligatorio'); document.getElementById('name').focus(); return;
        }
        if(!cat){
          e.preventDefault(); showToast('Selecciona una categoría'); document.getElementById('category_id').focus(); return;
        }
        if(isNaN(cost) || isNaN(sale)){
          e.preventDefault(); showToast('Verifica los precios'); return;
        }
        if(cost < 0 || sale < 0){
          e.preventDefault(); showToast('Los precios no pueden ser negativos'); return;
        }
        if(parseInt(document.getElementById('stock').value || '0', 10) < 0){
          e.preventDefault(); showToast('El stock no puede ser negativo'); return;
        }
      });
    </script>
</body>
</html>


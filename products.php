<?php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
require 'auth.php';

// Obtener la preferencia de moneda del usuario
$stmt = $pdo->prepare("SELECT currency_pref FROM users WHERE id=?");
$stmt->execute([$user['id']]);
$currencyPref = $stmt->fetchColumn() ?: 'S/.';

// Lista de símbolos de moneda
$currencySymbols = [
  'S/.' => 'S/.',
  '$' => '$',
  'USD' => '$',
  'EUR' => '€',
  'VES' => 'Bs.',
  'COP' => '$',
  'CLP' => '$',
  'MXN' => '$',
  'ARS' => '$'
];
$currencySymbol = $currencySymbols[$currencyPref] ?? $currencyPref;

// Obtener categorías
$categories = $pdo->prepare("SELECT id, name FROM categories WHERE user_id=? ORDER BY name");
$categories->execute([$user['id']]);
$categories = $categories->fetchAll(PDO::FETCH_ASSOC);

// Obtener productos
$sql = "SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.user_id = ?
        ORDER BY p.name";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user['id']]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Productos</title>
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root{
      --bg:#eef3f8;
      --panel:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --primary:#2563eb;
      --primary-2:#60a5fa;
      --danger:#e11d48;
      --success:#16a34a;
      --warning:#f59e0b;
      --border:#e2e8f0;
      --shadow:0 10px 24px rgba(15,23,42,.06);
      --radius:16px;
    }
    *{box-sizing:border-box}
    html,body{overflow-x:hidden;}
    img,svg{max-width:100%;height:auto;display:block}

    body{
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
      margin:0;
      background: linear-gradient(180deg,#fff,#f9fbff 45%,var(--bg));
      color: var(--text);
    }

    .page{padding:24px 20px 80px}
    .header{
      max-width:1200px;margin:0 auto 16px;
      display:flex;align-items:center;justify-content:space-between;gap:16px;
    }
    .title{display:flex;align-items:center;gap:14px}
    .title .icon{
      width:44px;height:44px;border-radius:12px;
      background:linear-gradient(135deg,#e0edff,#f1f7ff);
      display:grid;place-items:center;border:1px solid #dbeafe;box-shadow:var(--shadow)
    }
    .title h1{margin:0;font-size:24px;font-weight:800;line-height:1.2}
    .subtitle{font-size:13px;color:var(--muted);margin-top:2px}

    .actions{display:flex;align-items:center;gap:10px}
    .btn-primary{
      padding:10px 14px;border-radius:12px;border:none;cursor:pointer;
      background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff;font-weight:700;
      box-shadow:var(--shadow);transition:.25s transform ease
    }
    .btn-primary:hover{transform:translateY(-2px)}

    .toolbar{
      max-width:1200px;margin:0 auto 18px;
      display:grid;grid-template-columns:1fr auto auto;gap:12px;
    }
    .search, .select{
      background:var(--panel);border:1px solid var(--border);border-radius:12px;
      padding:10px 12px;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow)
    }
    .search input, .select select{
      border:none;background:transparent;outline:none;color:var(--text);font-size:14px
    }
    .select select{padding-right:6px}

    .card{
      max-width:1200px;margin:0 auto;background:var(--panel);
      border:1px solid var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow);overflow:hidden
    }
    .card-header{
      display:flex;align-items:center;justify-content:space-between;
      padding:14px 16px;background:linear-gradient(180deg,#ffffff,#f7fafc);
      border-bottom:1px solid var(--border)
    }
    .card-header .meta{font-size:12px;color:var(--muted)}

    .products-grid{
      padding:18px;
      display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px
    }
    .product-card{
      background:#fff;border:1px solid var(--border);border-radius:14px;
      padding:14px;box-shadow:0 8px 18px rgba(2,6,23,.06);
      display:flex;flex-direction:column;align-items:center;text-align:center;
      transition:.25s transform ease,.25s box-shadow ease,.2s border-color ease
    }
    .product-card:hover{
      transform:translateY(-4px);
      box-shadow:0 12px 26px rgba(2,6,23,.1);
      border-color:#dbeafe;
    }
    .product-img{
      width:150px;height:120px;object-fit:contain;border-radius:10px;
      background:linear-gradient(180deg,#fbfdff,#f2f6ff);
      border:1px solid #eef2ff;
      margin-bottom:10px;transition:transform .3s ease
    }
    .product-card:hover .product-img{transform:scale(1.03)}
    .product-name{font-size:15px;font-weight:700;margin-bottom:4px;line-height:1.25}
    .muted{font-size:12px;color:var(--muted);line-height:1.4}
    .price{margin-top:6px;font-size:13px;font-weight:700}

    .chip{
      display:inline-flex;align-items:center;gap:6px;
      padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;
      border:1px solid var(--border);background:#f8fafc;color:#334155
    }
    .chip.stock.ok{color:#065f46;background:#ecfdf5;border-color:#d1fae5}
    .chip.stock.mid{color:#92400e;background:#fffbeb;border-color:#fde68a}
    .chip.stock.low{color:#991b1b;background:#fef2f2;border-color:#fecaca}

    .badge{
      display:inline-flex;align-items:center;gap:6px;
      padding:4px 8px;border-radius:10px;font-size:11px;font-weight:600;
      border:1px solid var(--border);background:#f8fafc;color:#334155
    }

    .product-actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;justify-content:center}
    .product-actions a{
      font-size:13px;padding:8px 12px;border-radius:10px;text-decoration:none;
      display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);
      transition:.2s transform ease,.2s background ease
    }
    a.edit{background:#299EE6;color:#fff;border-color:#bfdbfe}
    a.edit:hover{transform:translateY(-1px)}
    a.delete{background:#F52727;color:#fff;border-color:#fecaca}
    a.delete:hover{transform:translateY(-1px)}

    .fab{
      position:fixed;right:24px;bottom:24px;width:56px;height:56px;border-radius:50%;
      background:linear-gradient(135deg,var(--primary),var(--primary-2));
      color:#fff;display:grid;place-items:center;font-size:26px;font-weight:800;
      text-decoration:none;box-shadow:0 14px 30px rgba(37,99,235,.35);
      border:1px solid #dbeafe;transition:.2s transform ease;z-index:800
    }
    .fab:hover{transform:translateY(-3px)}

    #toastMessage{
      position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
      background:#11101D;color:#fff;padding:12px 18px;border-radius:12px;
      box-shadow:0 12px 28px rgba(0,0,0,.25);opacity:0;pointer-events:none;
      transition:opacity .25s ease;z-index:9999;font-weight:700
    }
    #toastMessage.show{opacity:1;pointer-events:auto}

    .empty{
      grid-column:1/-1;display:flex;flex-direction:column;align-items:center;gap:10px;
      padding:24px;border:1px dashed var(--border);border-radius:14px;background:#fff;text-align:center
    }
    .empty .icon{width:40px;height:40px;color:#94a3b8}

    /* ========= RESPONSIVE ========= */

    /* Toolbar apilada en móvil */
    @media (max-width: 860px){
      .toolbar{grid-template-columns:1fr;gap:10px}
    }

    /* Header apilado y botones cómodos */
    @media (max-width: 720px){
      .header{flex-direction:column;align-items:flex-start;gap:10px}
      .actions{width:100%}
      .btn-primary{width:100%;text-align:center}
      .title h1{font-size:22px}
    }

    /* Grid más flexible y tarjetas compactas */
    @media (max-width: 960px){
      .products-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
    }
    @media (max-width: 560px){
      .page{padding:18px 14px 84px}
      .products-grid{grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
      .product-card{padding:12px}
      .product-img{width:120px;height:96px;margin-bottom:8px}
      .product-name{font-size:14px}
      .muted{font-size:12px}
      .badge{font-size:10.5px}
      .price{font-size:13px}
      .product-actions a{flex:1;justify-content:center}
    }

    /* Sidebar abierta no debe aplastar el contenido en móvil (por si quedó persistida) */
    @media (max-width: 640px){
      .sidebar.open ~ .page{ margin-left:78px; }
    }

    /* Preferencias mínimas para iOS (evita zoom al enfocar inputs) */
    @media (max-width: 560px){
      input,select,button{font-size:16px}
    }
  </style>
</head>
<body>
  <?php include 'sidebar.php'; ?>

  <div class="page">
    <div class="header">
      <div class="title">
        <div class="icon">
          <svg class="icon-20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
            <path d="M3.27 6.96 12 12l8.73-5.04M12 22V12"/>
          </svg>
        </div>
        <div>
          <h1>Inventario de productos</h1>
          <div class="subtitle">Explora, filtra y gestiona tu catálogo.</div>
        </div>
      </div>
      <div class="actions">
        <a href="add_product.php" class="btn-primary">+ Agregar producto</a>
      </div>
    </div>

    <div class="toolbar">
      <div class="search">
        <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="8"></circle>
          <path d="m21 21-3.6-3.6"></path>
        </svg>
        <input type="text" id="search" placeholder="Buscar por nombre, código, color, talla o categoría...">
      </div>
      <div class="select">
        <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20.59 13.41 11 3H4v7l9.59 9.59a2 2 0 0 0 2.82 0l4.18-4.18a2 2 0 0 0 0-2.82Z"/>
          <path d="M7 7h.01"/>
        </svg>
        <select id="category">
          <option value="">Todas las categorías</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['name'] ?? '') ?>"><?= htmlspecialchars($cat['name'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="select">
        <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M3 6h13M3 12h9M3 18h5"/>
        </svg>
        <select id="order">
          <option value="name">Ordenar por: Nombre</option>
          <option value="stock_desc">Mayor stock</option>
          <option value="stock_asc">Menor stock</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="meta">Catálogo completo</div>
        <div class="meta" id="emptyHint" style="display:none">No hay resultados con los filtros aplicados</div>
      </div>

      <div id="productsBody" class="products-grid">
        <?php foreach ($products as $p):
          $stockClass = $p['stock'] < 5 ? 'low' : ($p['stock'] < 10 ? 'mid' : 'ok'); ?>
          <div class="product-card"
               data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
               data-code="<?= strtolower(htmlspecialchars($p['code'] ?? '')) ?>"
               data-color="<?= strtolower(htmlspecialchars($p['color'] ?? '')) ?>"
               data-size="<?= strtolower(htmlspecialchars($p['size'] ?? '')) ?>"
               data-cat="<?= strtolower(htmlspecialchars($p['category_name'] ?? '')) ?>"
               data-stock="<?= (int)$p['stock'] ?>">
            <?php if (!empty($p['image'])): ?>
              <img class="product-img" src="<?= htmlspecialchars($p['image'] ?? '') ?>" alt="<?= htmlspecialchars($p['name'] ?? '') ?>">
            <?php else: ?>
              <div class="product-img" style="display:grid;place-items:center;color:#94a3b8;">
                <svg class="icon-24" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <rect x="3" y="3" width="18" height="14" rx="2"></rect>
                  <path d="m3 13 4-4 3 3 5-5 4 4"/>
                </svg>
              </div>
            <?php endif; ?>

            <div class="product-name"><?= htmlspecialchars($p['name'] ?? '') ?></div>
            <div class="badge">
              <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20.59 13.41 11 3H4v7l9.59 9.59a2 2 0 0 0 2.82 0l4.18-4.18a2 2 0 0 0 0-2.82Z"/>
                <path d="M7 7h.01"/>
              </svg>
              <?= htmlspecialchars($p['category_name'] ?? '-') ?>
            </div>

            <div style="margin-top:6px" class="muted">
              Código <?= htmlspecialchars($p['code'] ?? '-') ?> ·
              <?= htmlspecialchars($p['color'] ?? '-') ?> <?= htmlspecialchars($p['size'] ?? '-') ?>
            </div>

            <div style="margin-top:8px">
              <?php $cls = $stockClass==='ok'?'ok':($stockClass==='mid'?'mid':'low'); ?>
              <span class="chip stock <?= $cls ?>">
                <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                </svg>
                Stock: <?= (int)$p['stock'] ?>
              </span>
            </div>

            <div class="price"><?= htmlspecialchars($currencySymbol ?? '') . ' ' . number_format($p['sale_price'], 2) ?></div>

            <div class="product-actions">
              <a class="edit" href="edit_product.php?id=<?= $p['id'] ?>">
                <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M12 20h9"/>
                  <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/>
                </svg>
                Editar
              </a>
              <a class="delete" href="delete_product.php?id=<?= $p['id'] ?>" onclick="return confirm('¿Eliminar este producto?')">
                <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M3 6h18"/>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
                  <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                  <path d="M10 11v6M14 11v6"/>
                </svg>
                Eliminar
              </a>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if (!$products): ?>
          <div class="empty">
            <div class="icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <path d="M3.27 6.96 12 12l8.73-5.04M12 22V12"/>
              </svg>
            </div>
            <strong>No hay productos</strong>
            <span class="muted">Agrega tu primer producto con el botón “+”.</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <a href="add_product.php" class="fab" title="Agregar producto">+</a>

  <div id="toastMessage"></div>

  <script>
    // Toast (?message=)
    const urlParams = new URLSearchParams(window.location.search);
    const message = urlParams.get('message');
    if (message) {
      const toast = document.getElementById('toastMessage');
      toast.textContent = message;
      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 3000);
    }

    // Helpers: normalizar (quita acentos) y a minúsculas
    const norm = s => (s || '')
      .toString()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g,'')
      .toLowerCase()
      .trim();

    // Filtros
    const cards = [...document.querySelectorAll('.product-card')],
          search = document.getElementById('search'),
          order  = document.getElementById('order'),
          catSel = document.getElementById('category'),
          body   = document.getElementById('productsBody'),
          emptyHint = document.getElementById('emptyHint');

    function render(){
      const t = norm(search.value);
      const c = norm(catSel.value);

      let filtered = cards.filter(x => {
        const haystack = [
          x.dataset.name,
          x.dataset.code,
          x.dataset.color,
          x.dataset.size,
          x.dataset.cat
        ].map(norm).join(' ');
        const byText = !t || haystack.includes(t);
        const byCat  = !c || norm(x.dataset.cat) === c;
        return byText && byCat;
      });

      if (order.value === 'stock_desc') {
        filtered.sort((a,b) => (+b.dataset.stock) - (+a.dataset.stock));
      } else if (order.value === 'stock_asc') {
        filtered.sort((a,b) => (+a.dataset.stock) - (+b.dataset.stock));
      } else {
        filtered.sort((a,b) => norm(a.dataset.name).localeCompare(norm(b.dataset.name)));
      }

      body.innerHTML = '';
      if (filtered.length === 0) {
        emptyHint.style.display = '';
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.innerHTML = `
          <div class="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
              <path d="M3.27 6.96 12 12l8.73-5.04M12 22V12"/>
            </svg>
          </div>
          <strong>Sin resultados</strong>
          <span class="muted">Ajusta los filtros o limpia la búsqueda.</span>
        `;
        body.appendChild(empty);
      } else {
        emptyHint.style.display = 'none';
        filtered.forEach(el => body.appendChild(el));
      }
    }

    search.addEventListener('input', render);
    order.addEventListener('change', render);
    catSel.addEventListener('change', render);
    render(); // ordenar/filtrar al cargar
  </script>
</body>
</html>


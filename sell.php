<?php
// sell.php —
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger: exige login y email verificado
require 'auth.php';

$user_id = $_SESSION['user_id'];

/* ===================== Productos (traer category_id si existe) ===================== */
$products = [];
try {
  $stmt = $pdo->prepare('SELECT id, name, sale_price AS price, stock, size, color, image, category_id FROM products WHERE user_id = ? ORDER BY name');
  $stmt->execute([$user_id]);
  $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // Fallback sin category_id
  $stmt = $pdo->prepare('SELECT id, name, sale_price AS price, stock, size, color, image FROM products WHERE user_id = ? ORDER BY name');
  $stmt->execute([$user_id]);
  $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ================== Moneda preferida ================== */
$stmt = $pdo->prepare("SELECT currency_pref FROM users WHERE id=?");
$stmt->execute([$user_id]);
$currencyPref = $stmt->fetchColumn() ?: 'S/.';

$currencySymbols = [
  'S/.' => 'S/.', '$' => '$', 'USD' => '$', 'EUR' => '€',
  'VES' => 'Bs.', 'COP' => '$', 'CLP' => '$', 'MXN' => '$', 'ARS' => '$'
];
$currencySymbol = $currencySymbols[$currencyPref] ?? $currencyPref;

/* ================== Categorías (categories: id, user_id, category_id, name) ================== */
$categories = [];
$catByCode = []; // category_id (código externo) => name
$catByDbId = []; // id (PK) => name
try {
  $cstmt = $pdo->prepare('SELECT id, category_id, name FROM categories WHERE user_id = ? ORDER BY name');
  $cstmt->execute([$user_id]);
  $rows = $cstmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r){
    $id   = (string)$r['id'];
    $code = (string)$r['category_id'];
    $name = (string)$r['name'];
    $categories[] = ['value' => $code, 'name' => $name, 'kind' => 'id', 'id' => $id];
    if($code !== '') $catByCode[$code] = $name;
    $catByDbId[$id] = $name;
  }
} catch (Throwable $e) {
  // Ignorar si no existe tabla
}
if (!$categories) {
  // Fallback de nombres (si no hay tabla o está vacía y tienes products.category texto)
  $names = [];
  foreach ($products as $p) {
    $catName = isset($p['category']) ? trim((string)$p['category']) : '';
    if ($catName !== '') $names[$catName] = true;
  }
  if ($names) {
    $names = array_keys($names);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($names as $n) $categories[] = ['value' => $n, 'name' => $n, 'kind' => 'name', 'id' => ''];
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Registrar venta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Favicon -->
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">

  <link rel="stylesheet" href="style.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --shadow: 0 10px 28px rgba(0,0,0,.08);
      --shadow-lg: 0 18px 48px rgba(0,0,0,.12);
      --gray-50:#f9fafb; --gray-100:#f3f4f6; --gray-200:#e5e7eb; --gray-300:#d1d5db;
      --gray-400:#9ca3af; --gray-500:#6b7280; --gray-700:#374151; --gray-800:#1f2937;
      --green:#1f9d39; --green-600:#178c32; --red:#e33c2d; --amber:#f59e0b;
      --blue:#0ea5e9; --blue-600:#0284c7; --btn-blue:#12B829; --btn-blue-600:#00C91B;
    }
    *{box-sizing:border-box}
    html,body{overflow-x:hidden;}
    img,svg{max-width:100%;height:auto;display:block}
    body{font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Arial; color:var(--gray-800); background:var(--gray-50)}

    .page-wrap{ max-width:1280px; margin:0 auto; padding:20px; transition:margin-left .3s ease; }
    /* Empuje de contenido por sidebar en escritorio */
    .sidebar ~ .home-section .page-wrap{ margin-left:78px; }
    .sidebar.open ~ .home-section .page-wrap{ margin-left:250px; }

    .shop-layout{ display:grid; grid-template-columns: 1fr 380px; gap:24px; align-items:start; }
    @media (max-width: 1024px){
      .shop-layout{ grid-template-columns:1fr; }
      /* En móvil no empujar contenido por sidebar */
      .sidebar ~ .home-section .page-wrap,
      .sidebar.open ~ .home-section .page-wrap{ margin-left:0; }
    }

    .section-title{ font-size:24px; font-weight:800; margin:6px 0 12px; line-height:1.2; }
    @media (max-width:420px){ .section-title{ font-size:22px; } }

    .toolbar{ display:grid; grid-template-columns: 1fr 260px 40px; gap:14px; margin-bottom:16px; }
    @media (max-width:900px){ .toolbar{ grid-template-columns: 1fr 200px 40px; } }
    @media (max-width:760px){ .toolbar{ grid-template-columns: 1fr; } }

    .searchbox, .selectbox{
      height:42px; border:1px solid var(--gray-200); border-radius:12px; padding:0 12px; outline:none; background:#fff; width:100%;
      transition: box-shadow .18s, border-color .18s;
      font-size:16px; /* evita zoom iOS */
    }
    .searchbox:focus, .selectbox:focus{ border-color:var(--blue); box-shadow:0 0 0 3px rgba(14,165,233,.18) }
    .icon-btn{
      display:flex; align-items:center; justify-content:center; height:42px; width:42px;
      border:1px solid var(--gray-200); border-radius:12px; background:#fff; cursor:pointer; box-shadow: var(--shadow);
      font-size:16px;
    }

    .grid{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:16px; }
    @media (max-width:1200px){ .grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width:640px){ .grid{ grid-template-columns: 1fr; gap:12px; } }

    .card{ background:#fff; border:1px solid var(--gray-200); border-radius:18px; box-shadow: var(--shadow); overflow:hidden; transition: transform .15s, box-shadow .2s; padding:14px; text-align:center; }
    .card:hover{ transform:translateY(-2px); box-shadow: var(--shadow-lg); }
    .card-img{ width:140px; height:120px; object-fit:contain; background:var(--gray-100); margin:8px auto 6px; border-radius:12px; }
    @media (max-width:640px){ .card-img{ width:110px; height:90px; } }

    .p-name{ font-weight:800; margin:6px 0 6px; font-size:14px; line-height:1.25; }
    .pill{ display:inline-block; padding:6px 12px; border-radius:999px; background:#e0f2fe; color:#0284c7; font-weight:700; font-size:12px; margin-bottom:8px; }

    .stock-line{ font-weight:800; margin:4px 0; }
    .stock-line.low{ color:#ef4444; }   /* <=5 */
    .stock-line.mid{ color:#f59e0b; }   /* 6..10 */
    .stock-line.high{ color:#10b981; }  /* >=11 */

    .muted-line{ color:var(--gray-400); font-size:12px; margin-bottom:6px; line-height:1.35; }
    .price-line{ font-weight:800; margin:6px 0 12px; }

    .btn-blue{
      background:var(--btn-blue); color:#fff; border:none; border-radius:10px; padding:10px 14px;
      font-weight:800; cursor:pointer; display:inline-block; min-width:120px; transition: background .15s;
    }
    .btn-blue:hover{ background:var(--btn-blue-600); }
    .btn-blue:disabled{ background:#94a3b8; cursor:not-allowed; }

    /* Panel de venta */
    .panel{ background:#fff; border:1px solid var(--gray-200); border-radius:16px; box-shadow: var(--shadow-lg); position:sticky; top:86px; margin-top:16px; }
    .panel-head{ padding:14px; border-bottom:1px solid var(--gray-200); display:flex; align-items:center; justify-content:space-between; background:linear-gradient(180deg, #ffffff, #fafafa); }
    .panel-title{ font-size:18px; font-weight:800; }
    .items-count{ font-size:12px; color:#6b7280; }
    .panel-body{ padding:14px; }
    .empty{ text-align:center; color:#9ca3af; font-size:14px; margin-bottom:12px; }

    /* En móvil, panel deja de ser sticky y ocupa 100% debajo */
    @media (max-width:1024px){
      .panel{ position:static; top:auto; }
    }

    .cart-list{ display:flex; flex-direction:column; gap:12px; margin-bottom:12px; }
    .cart-item{ border:1px solid var(--gray-200); border-radius:12px; padding:10px; display:flex; gap:12px; align-items:center; background:#fff; box-shadow: var(--shadow); animation: enter .26s ease forwards; }
    @keyframes enter{ from{opacity:.0; transform: translateY(6px)} to{opacity:1; transform: translateY(0)} }
    .thumb{ width:56px; height:56px; border-radius:10px; object-fit:cover; background:#fff; }
    .meta{ flex:1; min-width:0; }
    .meta .t{ font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .meta .s{ font-size:12px; color:#6b7280 }
    .meta .q{ font-size:13px; color:#374151; margin-top:6px; display:flex; align-items:center; gap:8px; flex-wrap:wrap }

    .qty-btn{ height:30px; min-width:30px; padding:0 8px; border:1px solid var(--gray-300); background:#fff; border-radius:8px; cursor:pointer; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
    @media (max-width:420px){ .qty-btn{ height:28px; min-width:28px; } }

    .mini.warn{ padding:6px 10px; border:1px solid #f59e0b; background:#fff7ed; border-radius:8px; cursor:pointer; }

    .totals{ border-top:1px solid var(--gray-200); padding-top:12px; margin-top:12px; }
    .row{ display:flex; justify-content:space-between; align-items:center; margin:6px 0; gap:10px; }
    .disc-input{ width:120px; height:36px; border:1px solid var(--gray-200); border-radius:10px; padding:0 8px; font-size:16px; }

    .btn-primary{
      width:100%; background:var(--green); color:#fff; border:none; border-radius:12px; padding:12px 14px; font-weight:800; cursor:pointer; margin-top:8px;
      box-shadow:0 10px 24px rgba(31,157,57,.25); transition: transform .08s, box-shadow .12s, background .15s;
      display:inline-flex; align-items:center; justify-content:center; gap:10px; font-size:16px;
    }
    .btn-primary:hover{ transform:translateY(-1px); background:var(--green-600); }
    .btn-danger{
      width:100%; background:var(--red); color:#fff; border:none; border-radius:12px; padding:12px 14px; font-weight:800; cursor:pointer; margin-top:8px;
      box-shadow:0 10px 24px rgba(227,60,45,.18); display:inline-flex; align-items:center; justify-content:center; gap:10px; font-size:16px;
    }

    .error{ background:#fde2e2; color:#7f1d1d; border:1px solid #fecaca; padding:10px 12px; border-radius:10px; margin-bottom:12px; }

    .toast{ position:fixed; right:16px; bottom:16px; z-index:3000; background:linear-gradient(180deg,#10b981,#059669); color:#fff; padding:12px 14px; border-radius:12px; box-shadow: var(--shadow-lg); display:none; max-width: 90vw; font-weight:700; }
    .toast.show{ display:block; animation: toastIn .18s ease-out }
    @keyframes toastIn{ from { transform: translateY(8px); opacity: .0 } to { transform: translateY(0); opacity: 1 } }

    .price-edit-wrap{ display:inline-flex; align-items:center; gap:6px; }
    .price-edit{ width:110px; height:34px; border:1px solid #e5e7eb; border-radius:8px; padding:0 8px; text-align:right; font-weight:600; background:#fff; outline:none; font-size:16px; }
    .price-edit:focus{ border-color:#0ea5e9; box-shadow:0 0 0 3px rgba(14,165,233,.15); }
    .price-prefix{ color:#6b7280; font-size:12px; }

    /* Afinado en móviles pequeños */
    @media (max-width:480px){
      .page-wrap{ padding:16px 12px 80px; }
      .section-title{ font-size:22px; }
      .icon-btn{ height:40px; width:40px; }
      .btn-blue{ min-width:unset; width:100%; }
      .meta .t{ white-space:normal; } /* deja saltar línea si el nombre es largo */
      .row{ flex-wrap:wrap; }
      .disc-input{ flex:0 0 120px; }
    }
  </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<section class="home-section">
  <div class="page-wrap">
    <div class="shop-layout">

      <!-- ====== Productos ====== -->
      <div>
        <h2 class="section-title">Realizar una venta</h2>

        <div class="toolbar">
          <input class="searchbox" id="productSearch" type="text" placeholder="Buscar nombre, talla, color..." autocomplete="off">
          <select class="selectbox" id="categoryFilter">
            <option value="" data-kind="" data-cat-id="">Todas las categorías</option>
            <?php foreach ($categories as $cat): ?>
              <option
                value="<?= htmlspecialchars($cat['value']) ?>"
                data-kind="<?= htmlspecialchars($cat['kind']) ?>"
                data-cat-id="<?= htmlspecialchars($cat['id']) ?>"
              ><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="icon-btn" title="Filtrar"><span>🔍</span></button>
        </div>

        <div class="grid" id="productsGrid">
          <?php foreach ($products as $p): ?>
            <?php
              $stock = (int)($p['stock'] ?? 0);
              $stockClass = ($stock <= 5) ? 'low' : (($stock <= 10) ? 'mid' : 'high');

              $prodCatId = isset($p['category_id']) ? (string)$p['category_id'] : '';

              // Resolver nombre de categoría por código (category_id) o por id (PK)
              $catName = '';
              if ($prodCatId !== '') {
                if (isset($catByCode[$prodCatId]))      $catName = $catByCode[$prodCatId];
                elseif (isset($catByDbId[$prodCatId]))  $catName = $catByDbId[$prodCatId];
              }
              // Fallback si tuvieses un campo de texto "category" en products
              if ($catName === '' && isset($p['category'])) {
                $catName = trim((string)$p['category']);
              }

              $pillText = $catName !== '' ? $catName : 'Producto';
              $codigo = 'P' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT);

              // Sanitizar ruta de imagen (evitar esquemas peligrosos)
              $img = (string)($p['image'] ?? '');
              if (!preg_match('~^(uploads/|https?://)~i', $img)) { $img = ''; }
            ?>
            <div class="card"
                 data-id="<?= (int)$p['id'] ?>"
                 data-stock="<?= $stock ?>"
                 data-category-id="<?= htmlspecialchars($prodCatId) ?>"
                 data-category-name="<?= htmlspecialchars($catName) ?>">
              <img class="card-img" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
              <div class="p-name"><?= htmlspecialchars($p['name']) ?></div>
              <div class="pill"><?= htmlspecialchars($pillText) ?></div>
              <div class="stock-line <?= $stockClass ?>">Stock: <?= $stock ?></div>
              <div class="muted-line">
                Código <?= htmlspecialchars($codigo) ?><?= ($p['color']||$p['size']) ? ' | ' : '' ?>
                <?= htmlspecialchars($p['color'] ?? '') ?><?= ($p['color']&&$p['size']) ? ' ' : '' ?><?= htmlspecialchars($p['size'] ?? '') ?>
              </div>
              <div class="price-line"><?= htmlspecialchars($currencySymbol) ?> <?= number_format((float)$p['price'], 2, '.', ',') ?></div>
              <button class="btn-blue" data-add="<?= (int)$p['id'] ?>" <?= ($stock<=0 ? 'disabled' : '') ?>>Agregar</button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ====== Panel Vender ====== -->
      <aside class="panel">
        <div class="panel-head">
          <div class="panel-title">Vender</div>
          <div class="items-count" id="cartCount">0 items</div>
        </div>
        <div class="panel-body">
          <?php if(!empty($_GET['error'])): ?>
            <p class="error"><?= htmlspecialchars($_GET['error']) ?></p>
          <?php endif; ?>

          <!-- FORM ORIGINAL -->
          <form id="saleForm" action="process_sale.php" method="post" onsubmit="return validateForm()">
            <input type="hidden" id="selected_product_id" name="product_id">
            <input type="hidden" id="unit_price" name="unit_price" step="0.01" min="0" />
            <input type="hidden" id="quantity" name="quantity" value="1" min="1" />

            <div id="emptyMsg" class="empty">Agrega productos para vender</div>
            <div class="cart-list" id="cartList"></div>

            <div class="totals" id="totalsBox" style="display:none">
              <div class="row"><strong>Subtotal:</strong><span id="subtotalText"><?= htmlspecialchars($currencySymbol) ?> 0.00</span></div>
              <div class="row"><strong>Descuento:</strong><input class="disc-input" id="discountInput" type="number" step="0.01" value="0"></div>
              <div class="row"><strong>Total:</strong><strong id="totalText"><?= htmlspecialchars($currencySymbol) ?> 0.00</strong></div>

              <button type="submit" class="btn-primary">Vender</button>
              <button type="button" class="btn-danger" id="clearCart">Vaciar carrito</button>
            </div>
          </form>
        </div>
      </aside>

    </div>
  </div>
</section>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script>
/* ====== Datos desde PHP ====== */
const products = <?= json_encode($products, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const CURRENCY = <?= json_encode($currencySymbol) ?>;

/* ====== refs ====== */
const selectedProductId = document.getElementById('selected_product_id');
const unitPriceInput    = document.getElementById('unit_price');
const quantityInput     = document.getElementById('quantity');
const saleForm          = document.getElementById('saleForm');

const searchInput    = document.getElementById('productSearch');
const categoryFilter = document.getElementById('categoryFilter');

const productsGrid = document.getElementById('productsGrid');
const cartList   = document.getElementById('cartList');
const cartCount  = document.getElementById('cartCount');
const totalsBox  = document.getElementById('totalsBox');
const emptyMsg   = document.getElementById('emptyMsg');
const subtotalText = document.getElementById('subtotalText');
const totalText    = document.getElementById('totalText');
const discountInput= document.getElementById('discountInput');
const clearCartBtn = document.getElementById('clearCart');
const toastEl      = document.getElementById('toast');

let cart = [];

/* ====== batch ====== */
const LS_KEY_QUEUE = 'batchQueue';
const LS_KEY_FLAG  = 'batchInProgress';
function saveQueue(q){ localStorage.setItem(LS_KEY_QUEUE, JSON.stringify(q)); }
function loadQueue(){ try{ return JSON.parse(localStorage.getItem(LS_KEY_QUEUE)||'[]'); }catch(e){ return []; } }
function setBatchFlag(v){ localStorage.setItem(LS_KEY_FLAG, v ? '1':''); }
function getBatchFlag(){ return localStorage.getItem(LS_KEY_FLAG)==='1'; }

/* ====== utils ====== */
function fmt(n){ return (CURRENCY ? (CURRENCY + ' ') : '') + Number(n || 0).toFixed(2); }
function escapeHtml(str){ return str ? String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;') : ''; }
function showToast(message, ms=2500){ if(!message) return; toastEl.textContent = message; toastEl.classList.add('show'); setTimeout(()=> toastEl.classList.remove('show'), ms); }

/* ====== totals ====== */
function recomputeTotals(){
  var subtotal = 0;
  for(var i=0;i<cart.length;i++){
    var it = cart[i];
    subtotal += Number(it.unit_price||0) * Number(it.quantity||0);
  }
  var disc = Number(discountInput.value)||0;
  var total = Math.max(subtotal - disc, 0);
  subtotalText.textContent = fmt(subtotal);
  totalText.textContent    = fmt(total);
  totalsBox.style.display = cart.length ? 'block' : 'none';
}

/* ====== render ====== */
function renderCart(){
  cartList.innerHTML = '';
  if(cart.length === 0){
    cartCount.textContent = '0 items';
    totalsBox.style.display = 'none';
    emptyMsg.style.display = 'block';
    selectedProductId.value = '';
    unitPriceInput.value    = '';
    quantityInput.value     = 1;
    return;
  }
  emptyMsg.style.display = 'none';
  cartCount.textContent = cart.length + (cart.length===1 ? ' item' : ' items');

  var subtotal = 0;
  for(var idx=0; idx<cart.length; idx++){
    var it = cart[idx];
    subtotal += it.unit_price * it.quantity;

    var row = document.createElement('div');
    row.className = 'cart-item';
    row.innerHTML =
      '<img class="thumb" src="'+escapeHtml(it.image||'')+'" alt="">' +
      '<div class="meta">' +
        '<div class="t">'+escapeHtml(it.name)+'</div>' +
        '<div class="s">'+escapeHtml(it.size? it.size : '')+(it.size && it.color ? ' · ' : '')+escapeHtml(it.color? it.color : '')+'</div>' +
        '<div class="q">' +
          'Cant: ' +
          '<button type="button" class="qty-btn" data-dec="'+idx+'" aria-label="Disminuir cantidad">−</button>' +
          '<strong class="qty-val" data-qty="'+idx+'">'+it.quantity+'</strong>' +
          '<button type="button" class="qty-btn" data-inc="'+idx+'" aria-label="Aumentar cantidad">+</button>' +
          ' · ' +
          '<span class="price-edit-wrap">' +
            '<span class="price-prefix">'+CURRENCY+'</span>' +
            '<input type="number" step="0.01" min="0" class="price-edit" data-price="'+idx+'" value="'+Number(it.unit_price).toFixed(2)+'">' +
          '</span>' +
          ' · Subtotal: <strong class="line-subtotal" data-sub="'+idx+'">'+fmt(it.unit_price*it.quantity)+'</strong>' +
        '</div>' +
      '</div>' +
      '<div><button type="button" class="mini warn" data-remove="'+idx+'" aria-label="Quitar del carrito">❌ Quitar</button></div>';

    cartList.appendChild(row);
  }

  var disc = Number(discountInput.value)||0;
  var total = Math.max(subtotal - disc, 0);
  subtotalText.textContent = fmt(subtotal);
  totalText.textContent = fmt(total);
  totalsBox.style.display = 'block';
}

/* ====== add to cart ====== */
productsGrid.addEventListener('click', function(e){
  var btn = e.target.closest ? e.target.closest('button[data-add]') : null;
  if(!btn) return;

  var id = btn.getAttribute('data-add');
  var p = null;
  for (var i=0;i<products.length;i++){ if (String(products[i].id) === String(id)) { p = products[i]; break; } }
  if(!p) return;

  var existing = null;
  for (var j=0;j<cart.length;j++){ if (String(cart[j].id) === String(p.id)) { existing = cart[j]; break; } }
  var stock = Number(p.stock)||0;

  if(existing){
    if(existing.quantity + 1 > stock){ showToast('Stock insuficiente. Disponibles: '+stock); return; }
    existing.quantity += 1;
  } else {
    if(stock <= 0){ showToast('Sin stock.'); return; }
    cart.push({
      id: p.id, name: p.name, size: p.size || '', color: p.color || '', image: p.image || '',
      unit_price: Number(p.price), quantity: 1, stock: stock
    });
  }
  renderCart();
});

/* ====== cart actions (NO SUBMIT) ====== */
cartList.addEventListener('click', function(e){
  var targetBtn = e.target.closest ? e.target.closest('button') : null;
  if (!targetBtn) return;
  e.preventDefault(); // evita submits

  var rem = targetBtn.getAttribute('data-remove');
  var inc = targetBtn.getAttribute('data-inc');
  var dec = targetBtn.getAttribute('data-dec');

  if(rem !== null){
    cart.splice(Number(rem),1);
    renderCart();
    return;
  }
  if(inc !== null){
    var i = Number(inc);
    var stock = Number(cart[i].stock)||0;
    if(cart[i].quantity + 1 > stock){ showToast('Stock insuficiente. Disponibles: '+stock); return; }
    cart[i].quantity += 1;

    var qtyEl = cartList.querySelector('.qty-val[data-qty="'+i+'"]');
    if (qtyEl) qtyEl.textContent = String(cart[i].quantity);
    var line = cartList.querySelector('.line-subtotal[data-sub="'+i+'"]');
    if (line) line.textContent = fmt(cart[i].unit_price * cart[i].quantity);

    recomputeTotals();
    return;
  }
  if(dec !== null){
    var i2 = Number(dec);
    cart[i2].quantity = Math.max(1, cart[i2].quantity - 1);

    var qtyEl2 = cartList.querySelector('.qty-val[data-qty="'+i2+'"]');
    if (qtyEl2) qtyEl2.textContent = String(cart[i2].quantity);
    var line2 = cartList.querySelector('.line-subtotal[data-sub="'+i2+'"]');
    if (line2) line.textContent = fmt(cart[i2].unit_price * cart[i2].quantity);

    recomputeTotals();
    return;
  }
});

/* ====== price edit (no re-render) ====== */
cartList.addEventListener('input', function(e){
  var iAttr = e.target.getAttribute ? e.target.getAttribute('data-price') : null;
  if(iAttr === null) return;
  var i = Number(iAttr);
  var v = parseFloat(e.target.value);
  if (isNaN(v) || v < 0) v = 0;
  cart[i].unit_price = v;
  var row = e.target.closest ? e.target.closest('.cart-item') : null;
  var line = row ? row.querySelector('.line-subtotal[data-sub="'+i+'"]') : null;
  if(line) line.textContent = fmt(v * (cart[i].quantity||0));
  recomputeTotals();
});
cartList.addEventListener('blur', function(e){
  var iAttr = e.target.getAttribute ? e.target.getAttribute('data-price') : null;
  if(iAttr === null) return;
  var i = Number(iAttr);
  e.target.value = Number(cart[i].unit_price||0).toFixed(2);
}, true);

/* Bloquear Enter en el input de precio (evita submit accidental) */
cartList.addEventListener('keydown', function(e){
  if (e.key === 'Enter' && e.target && e.target.matches && e.target.matches('input.price-edit')) {
    e.preventDefault();
    e.target.blur();
  }
});

/* ====== descuento ====== */
discountInput.addEventListener('input', recomputeTotals);

/* ====== vaciar ====== */
clearCartBtn.addEventListener('click', function(){ cart = []; renderCart(); });

/* ====== Búsqueda + Filtro por categoría (ID/código O nombre) ====== */
function applyFilters(){
  var term = (searchInput.value || '').trim().toLowerCase();

  var sel = categoryFilter.options[categoryFilter.selectedIndex] || null;
  var kind = sel ? (sel.getAttribute('data-kind') || '') : '';
  var rawVal = (categoryFilter.value || '').trim();                // value = categories.category_id (código)
  var optDbId = sel ? (sel.getAttribute('data-cat-id') || '') : ''; // data-cat-id = categories.id (PK)
  var optionName = sel ? (sel.textContent || '').replace(/\s+/g,' ').trim().toLowerCase() : '';

  var cards = document.querySelectorAll('#productsGrid .card');
  for (var k=0; k<cards.length; k++){
    var card = cards[k];
    var id = card.getAttribute('data-id');
    var p = null;
    for (var z=0; z<products.length; z++){ if (String(products[z].id) === String(id)) { p = products[z]; break; } }
    p = p || {};
    var txt = ((p.name||'')+' '+(p.size||'')+' '+(p.color||'')).toLowerCase();
    var matchesText = term === '' ? true : (txt.indexOf(term) !== -1);

    var matchesCat = true;
    if (rawVal !== '' || optDbId !== '') {
      var cid   = (card.getAttribute('data-category-id') || '').trim();     // products.category_id (puede ser code o PK)
      var cname = (card.getAttribute('data-category-name') || '').trim().toLowerCase();

      if (kind === 'id') {
        matchesCat = (cid !== '' && (String(cid) === String(rawVal) || String(cid) === String(optDbId)));
        if (!matchesCat && optionName !== '') {
          matchesCat = (cname !== '' && cname === optionName);
        }
      } else if (kind === 'name') {
        matchesCat = (cname !== '' && cname === optionName);
      }
    }

    card.style.display = (matchesText && matchesCat) ? '' : 'none';
  }
}
searchInput.addEventListener('input', applyFilters);
categoryFilter.addEventListener('change', applyFilters);

/* ====== vender  ====== */
function validateForm(){
  if(cart.length === 0){ showToast('Agrega al menos un producto al carrito.'); return false; }
  for(var i=0;i<cart.length;i++){
    var it = cart[i];
    var st = Number(it.stock)||0;
    if(it.quantity > st){ showToast('Stock insuficiente para "'+it.name+'". Disponibles: '+st); return false; }
  }
  var queue = [];
  for (var j=0;j<cart.length;j++){
    queue.push({ id: cart[j].id, unit_price: Number(cart[j].unit_price), quantity: Number(cart[j].quantity) });
  }
  saveQueue(queue);
  setBatchFlag(true);

  var first = queue[0];
  selectedProductId.value = first.id;
  unitPriceInput.value    = Number(first.unit_price).toFixed(2);
  quantityInput.value     = first.quantity;

  var qty = Number(quantityInput.value); if(!(qty>0)){ showToast('Cantidad inválida'); return false; }
  var up  = Number(unitPriceInput.value); if(isNaN(up) || up<0){ showToast('Precio inválido'); return false; }
  unitPriceInput.value = up.toFixed(2);

  return true;
}

/* ====== continuar batch después de process_sale.php ====== */
window.addEventListener('load', function(){
  var url = new URL(window.location.href);
  var urlMsg = url.searchParams.get('msg');

  if (getBatchFlag()) {
    if (urlMsg) { url.searchParams.delete('msg'); history.replaceState({}, '', url.pathname + (url.searchParams.toString()? '?'+url.searchParams.toString() : '')); }
    var queue = loadQueue();
    if (queue.length === 0) {
      setBatchFlag(false); localStorage.removeItem('batchQueue');
      cart = []; renderCart(); showToast('Venta completada.'); return;
    }
    queue.shift();
    if (queue.length === 0) {
      setBatchFlag(false); localStorage.removeItem('batchQueue');
      cart = []; renderCart(); showToast('Venta completada.'); return;
    }
    saveQueue(queue);
    var next = queue[0];
    selectedProductId.value = next.id;
    unitPriceInput.value    = Number(next.unit_price).toFixed(2);
    quantityInput.value     = next.quantity;
    setTimeout(function(){ saleForm.submit(); }, 120);
    return;
  }

  if (urlMsg) {
    showToast(decodeURIComponent(urlMsg));
    url.searchParams.delete('msg');
    history.replaceState({}, '', url.pathname + (url.searchParams.toString()? '?'+url.searchParams.toString() : ''));
  }
});

/* init */
renderCart();
</script>
</body>
</html>

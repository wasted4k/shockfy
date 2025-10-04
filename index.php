<?php
require_once __DIR__ . '/auth_check.php'; // protege: exige login y email verificado (redirige a welcome.php si falta)
require_once __DIR__ . '/db.php';

// ================= Prefs de zona horaria / formato =================
$user_tz  = $_SESSION['timezone']    ?? null;
$time_fmt = $_SESSION['time_format'] ?? null;
if (!$user_tz || !$time_fmt) {
  $uid = $_SESSION['user_id'] ?? null;
  if ($uid) {
    $q = $pdo->prepare("SELECT timezone, time_format FROM users WHERE id = :id LIMIT 1");
    $q->execute([':id' => $uid]);
    if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
      $user_tz  = $row['timezone']    ?: 'America/New_York';
      $time_fmt = $row['time_format'] ?: '12h';
      $_SESSION['timezone']    = $user_tz;
      $_SESSION['time_format'] = $time_fmt;
    }
  }
}
$user_tz  = $user_tz  ?: 'America/New_York';
$time_fmt = $time_fmt ?: '12h';

// Helpers: convierte desde UTC a TZ del usuario y formatea
function dt_in_tz($dt, string $tz): DateTime {
  // Asumimos $dt guardado en UTC; si tu DB guarda local, cambia 'UTC' por tu TZ del server.
  $d = ($dt instanceof DateTime)
    ? (clone $dt)
    : new DateTime((string)$dt, new DateTimeZone('UTC'));
  $d->setTimezone(new DateTimeZone($tz));
  return $d;
}
function fmt_time_for_user(DateTime $d, string $fmt): string {
  return $fmt === '24h' ? $d->format('H:i') : $d->format('g:i A');
}
function fmt_datetime_for_user($dt, string $tz, string $fmt): array {
  $d = dt_in_tz($dt, $tz);
  // Texto visible: "25 Sep 2025, 2:45 PM" / "25 Sep 2025, 14:45"
  $datePart = $d->format('d M Y');
  $timePart = fmt_time_for_user($d, $fmt);
  $visible  = $datePart . ', ' . $timePart;
  // Timestamp ISO para JS (ordenar/filtrar robusto)
  $iso = $d->format('c'); // 2025-09-25T14:45:00-05:00
  return [$visible, $iso];
}

// ================= Estado de plan / trial =================
$uid = (int)($_SESSION['user_id'] ?? 0);
$plan = 'free';
$trialActive = false;
$trialDaysLeft = 0;
$trialEndsLocalText = '';

if ($uid) {
  $stPlan = $pdo->prepare("SELECT plan, trial_started_at, trial_ends_at FROM users WHERE id = ? LIMIT 1");
  $stPlan->execute([$uid]);
  if ($row = $stPlan->fetch(PDO::FETCH_ASSOC)) {
    $plan = $row['plan'] ?: 'free';
    if (!empty($row['trial_ends_at'])) {
      $nowUtc  = new DateTime('now', new DateTimeZone('UTC'));
      $endsUtc = new DateTime($row['trial_ends_at'], new DateTimeZone('UTC'));
      $trialActive   = ($nowUtc <= $endsUtc);
      $trialDaysLeft = max(0, (int)$nowUtc->diff($endsUtc)->format('%r%a'));
      // Convertimos a zona del usuario para mostrar
      $endsLocal = dt_in_tz($endsUtc, $user_tz);
      $trialEndsLocalText = $endsLocal->format('d M Y') . ', ' . fmt_time_for_user($endsLocal, $time_fmt);
    }
  }
}

// ================= Datos =================
// Moneda
$stmt = $pdo->prepare('SELECT currency_pref FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$currency = $stmt->fetchColumn() ?: 'S/.';

// últimos 3 productos
$stmt = $pdo->prepare('SELECT * FROM products WHERE user_id = :user_id ORDER BY id DESC LIMIT 3');
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ventas recientes (subí a 100 para que funcione la paginación en cliente)
$stmt = $pdo->prepare('
    SELECT s.*, p.name AS product_name, p.size, p.color
    FROM sales s 
    JOIN products p ON s.product_id = p.id
    WHERE s.user_id = :user_id
    ORDER BY s.sale_date DESC 
    LIMIT 100
');
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// total ventas del mes actual (consulta sigue igual)
$stmt = $pdo->prepare('
    SELECT COALESCE(SUM(total),0) as total_mes 
    FROM sales 
    WHERE YEAR(sale_date) = YEAR(CURDATE()) 
      AND MONTH(sale_date) = MONTH(CURDATE())
      AND user_id = :user_id
');
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$totalMes = $stmt->fetchColumn();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>ShockFy</title>
  <link rel="stylesheet" href="style.css">
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root{
      --bg:#f8fafc;
      --bg-contrast:#e9eef5;
      --panel:#ffffff;
      --panel-2:#f2f5f9;
      --text:#0f172a;
      --muted:#64748b;
      --primary:#2563eb;
      --primary-2:#60a5fa;
      --success:#16a34a;
      --danger:#dc2626;
      --warning:#d97706;
      --border:#e2e8f0;
      --shadow:0 10px 24px rgba(15,23,42,.06);
      --radius:16px;
    }
    *{box-sizing:border-box}
    body.home{
      margin:0;
      font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      background: var(--bg-contrast);
      color:var(--text);
    }
    a{color:inherit;text-decoration:none}

    #darkToggle{
      position: fixed; right: 20px; bottom: 20px; z-index: 9999;
      background: linear-gradient(135deg,var(--primary),var(--primary-2));
      color:#fff; padding:10px 14px; border-radius:14px; border:none; cursor:pointer;
      font-weight:800; box-shadow:var(--shadow); transition:transform .2s ease, box-shadow .2s ease;
    }
    #darkToggle:hover{ transform: translateY(-2px); box-shadow:0 16px 30px rgba(37,99,235,.25); }

    .page{ padding:24px 18px 64px; }
    .container{
      max-width:1200px; margin:0 auto;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 16px;
    }

    .hero{
      display:flex; align-items:center; justify-content:space-between; gap:16px; margin:4px 0 4px;
    }
    .hero-left{ display:flex; align-items:center; gap:14px; }
    .hero .icon{ width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#e0edff,#f1f7ff);display:grid;place-items:center;border:1px solid #dbeafe; box-shadow:var(--shadow)}
    .hero h1{ margin:0; font-size:28px; font-weight:800; color:#0b1220; }
    .hero .subtitle{ font-size:13px; color:#64748b; margin-top:4px; }
    .cta-row{ display:flex; gap:10px; flex-wrap:wrap; }

    .chip{
      display:inline-flex; align-items:center; gap:6px;
      padding:4px 10px; border-radius:999px; font-weight:800; font-size:12px;
      border:1px solid #cfe0ff; background:#eef5ff; color:#0b3ea8;
    }
    /* Chip de plan FREE en ámbar suave (más visible) */
    .chip.plan-free{
      border-color:#fde68a;      /* amber-200 */
      background:#fff7ed;        /* amber-50 */
      color:#92400e;             /* amber-800 */
    }
    .chip.plan-warning{
      border-color:#fde68a; background:#fff7ed; color:#92400e; /* ámbar para expirado */
    }

    .btn{
      padding:10px 14px; border-radius:12px;
      border:1px solid #cfd7e3;
      background: var(--panel-2);
      color: var(--text);
      box-shadow:var(--shadow); font-weight:700;
      transition: transform .15s ease, background .15s ease, border-color .15s ease;
    }
    .btn:hover{ transform:translateY(-1px); background:#e8edf4; border-color:#b8c3d4; }
    .btn:focus{ outline: 2px solid #bfd3ff; outline-offset:2px; }
    .btn.primary{
      background:linear-gradient(135deg,var(--primary),var(--primary-2));
      color:#fff; border:none;
    }
    .btn.primary:hover{ filter: brightness(0.98); }

    .banner{
      margin:10px 0 0; padding:12px 14px; border-radius:12px; border:1px solid;
      display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
      box-shadow:var(--shadow); position:relative;
    }
    /* Banner TRIAL activo en ámbar/naranja */
    .banner.trial{
      background:#fff7ed;        /* amber-50 */
      border-color:#fbbf24;      /* amber-400 */
      color:#7c2d12;             /* amber-900 */
    }
    .banner.trial::before{
      content:"";
      position:absolute; left:0; top:0; bottom:0; width:6px;
      background: linear-gradient(180deg, #f59e0b, #fbbf24); /* amber-500→400 */
      border-top-left-radius: inherit;
      border-bottom-left-radius: inherit;
    }
    /* Banner de prueba finalizada (se mantiene ámbar suave) */
    .banner.warn{
      background:#fff7ed; border-color:#fde68a; color:#92400e;
    }

    .stats{ display:grid; grid-template-columns:repeat(3, minmax(220px,1fr)); gap:14px; margin:16px 0 22px; }
    .stat-card{ background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); padding:16px; box-shadow:var(--shadow); display:flex; gap:12px; align-items:center; }
    .stat-icon{ width:42px;height:42px;border-radius:10px; display:grid; place-items:center; background:linear-gradient(135deg,#e8f1ff,#f3f8ff); border:1px solid #e5eaff; }
    .stat-meta{ font-size:12px; color:#64748b; }
    .stat-value{ font-weight:800; font-size:20px; margin-top:2px; }

    .section{ margin-top:18px; background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }
    .section-header{ padding:14px 16px; border-bottom:1px solid var(--border); background:linear-gradient(180deg,#ffffff,#f7fafc); display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .section-title{ font-size:14px; font-weight:800; }
    .section-hint{ font-size:12px; color:#64748b }
    .section-tools{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .pill{ display:flex; align-items:center; gap:8px; background:#fff; border:1px solid var(--border); padding:8px 10px; border-radius:12px; box-shadow:var(--shadow); }
    .pill input, .pill select{ border:none; outline:none; background:transparent; color:inherit; min-width:120px; }
    .pill .icon{ opacity:.7 }

    table{ width:100%; border-collapse:separate; border-spacing:0; }
    thead th{ font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:#475569; padding:14px 16px; background:#f8fafc; border-bottom:1px solid var(--border); text-align:left; user-select:none; cursor:pointer; }
    thead th[data-sortable="false"]{ cursor:default; }
    tbody td{ padding:14px 16px; border-bottom:1px solid var(--border); }
    tbody tr{ transition: background .18s ease }
    tbody tr:hover{ background:#f1f5f9 }
    .low-stock{ color:#dc2626; font-weight:800; }

    .delete-btn{
      background:linear-gradient(135deg,#ef4444,#f87171);
      color:#fff !important; padding:8px 12px; border-radius:10px; font-weight:700; font-size:13px;
      display:inline-block; border:1px solid #fecaca; transition:transform .18s ease, box-shadow .18s ease;
    }
    .delete-btn:hover{ transform:translateY(-1px); box-shadow:0 10px 20px rgba(239,68,68,.22); }

    .pagination{ display:flex; align-items:center; gap:8px; padding:12px 16px; }
    .pagination .btn{ padding:8px 10px; }
    .spacer{flex:1}

    #notification{
      position: fixed; left:50%; bottom: 24px; transform: translateX(-50%);
      background:#ecfdf5; border:1px solid #d1fae5; color:#065f46;
      padding:12px 16px; border-radius:12px; box-shadow:var(--shadow);
      font-weight:800; opacity:0; pointer-events:none; transition:opacity .25s ease; z-index:2000;
    }
    #notification.show{ opacity:1; pointer-events:auto; }

    /* DARK MODE */
    body.dark{ background:#0c1326; }
    body.dark .container{ background:#0b1220; border-color:#1f2a4a; }
    body.dark .section, body.dark .stat-card{ background:#0b1220; border-color:#1f2a4a; }
    body.dark .section-header{ background:#0e1630; }
    body.dark thead th{ background:#0e1630; color:#a5b4fc; border-color:#1f2a4a; }
    body.dark tbody td{ border-color:#1f2a4a; }
    body.dark tbody tr:hover{ background:rgba(99,102,241,.08); }
    body.dark .pill{ background:#0e1630; border-color:#1f2a4a; }
    body.dark .btn{ background:#0e1630; border-color:#2a365a; color:#e5e7eb; }
    body.dark .btn:hover{ background:#132146; border-color:#33416b; }

    body.dark .chip{ background:#0e1630; border-color:#33416b; color:#a5b4fc; }
    body.dark .chip.plan-free{
      background:#221709;        /* ámbar muy oscuro */
      border-color:#5c3a0b;
      color:#f2c48a;
    }
    body.dark .chip.plan-warning{
      background:#221709; border-color:#5c3a0b; color:#f2c48a;
    }
    body.dark .banner.trial{
      background:#221709;
      border-color:#5c3a0b;
      color:#f2c48a;
    }
    body.dark .banner.trial::before{
      background: linear-gradient(180deg, #b45309, #d97706); /* amber-600→500 */
    }
    body.dark .banner.warn{
      background:#221709; border-color:#5c3a0b; color:#f2c48a;
    }
  

/* === Mobile tweaks for index page (layout-safe) === */
@media (max-width: 1024px){
  .hero{ flex-direction: column; align-items: flex-start; gap: 10px; }
  .hero-left{ align-items: center; }
  .cta-row{ width: 100%; }
}
@media (max-width: 768px){
  .container{ padding: 12px; }
  .section .section-header{ flex-direction: column; align-items: flex-start; gap: 10px; }
  .section-tools{ width: 100%; }
  .pill{ width: 100%; }
  .pill input, .pill select{ width: 100%; min-width: 0; }
}

/* Tables: make them horizontally scrollable on small screens without breaking layout */
@media (max-width: 980px){
  table{ display: block; width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  thead, tbody, tr{ display: table; width: 100%; table-layout: fixed; }
  th, td{ white-space: nowrap; }
}
</style>
</head>

<body class="home">
  <?php include 'sidebar.php'; ?>
  
  <div class="page">
    <div class="container">
      <!-- Hero -->
      <div class="hero">
        <div class="hero-left">
          <div class="icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M6 6h15l-1.5 9h-12L5 3H2" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="20" r="1" fill="#2563eb"/><circle cx="18" cy="20" r="1" fill="#2563eb"/></svg>
          </div>
          <div>
            <h1>Panel de control</h1>
            <div class="subtitle">
              Resumen de tu actividad y accesos rápidos.
              <!-- chip con TZ y formato -->
              <span class="chip" style="margin-left:8px;">
                <?= htmlspecialchars($user_tz) ?> · <?= htmlspecialchars(strtoupper($time_fmt)) ?>
              </span>
              <!-- chip de plan -->
              <?php if ($plan === 'free' && $trialActive): ?>
                <span class="chip plan-free" style="margin-left:6px;">Plan: Gratis · Prueba (<?= (int)$trialDaysLeft ?> día<?= $trialDaysLeft==1?'':'s' ?>)</span>
              <?php elseif ($plan === 'free' && !$trialActive): ?>
                <span class="chip plan-warning" style="margin-left:6px;">Plan: Gratis · Prueba finalizada</span>
              <?php else: ?>
                <span class="chip" style="margin-left:6px;">Plan: <?= htmlspecialchars(ucfirst($plan)) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="cta-row">
          <a class="btn primary" href="sell.php">Vender ahora</a>
          <a class="btn" href="products.php">Productos</a>
          <a class="btn" href="categories.php">Categorías</a>
        </div>
      </div>

      <!-- Banner de trial / plan -->
      <?php if ($plan === 'free' && $trialActive): ?>
        <div class="banner trial">
          <div style="display:flex;align-items:center;gap:10px">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 19a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div>
              <strong>Prueba gratuita activa.</strong>
              Te quedan <strong><?= (int)$trialDaysLeft ?></strong> día<?= $trialDaysLeft==1?'':'s' ?>.
              Termina: <strong><?= htmlspecialchars($trialEndsLocalText) ?></strong>.
            </div>
          </div>
          <a class="btn primary" href="billing.php">Elegir plan</a>
        </div>
      <?php elseif ($plan === 'free' && !$trialActive): ?>
        <div class="banner warn">
          <div>
            <strong>Tu prueba gratuita ha finalizado.</strong>
            Continúas en el plan gratuito con funciones limitadas.
          </div>
          <a class="btn primary" href="billing.php">Actualizar plan</a>
        </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="stats">
        <div class="stat-card">
          <div class="stat-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18" stroke="#2563eb" stroke-width="2"/><path d="M7 15l3-3 4 4 6-6" stroke="#16a34a" stroke-width="2" fill="none"/></svg>
          </div>
          <div>
            <div class="stat-meta">Ventas del mes</div>
            <div class="stat-value"><?= $currency . ' ' . number_format($totalMes, 2) ?></div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 7h18M3 12h18M3 17h18" stroke="#2563eb" stroke-width="2"/></svg>
          </div>
          <div>
            <div class="stat-meta">Últimos productos listados</div>
            <div class="stat-value"><?= count($products) ?></div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 6h16v12H4z" stroke="#2563eb" stroke-width="2"/><path d="M4 10h16" stroke="#60a5fa" stroke-width="2"/></svg>
          </div>
          <div>
            <div class="stat-meta">Ventas recientes listadas</div>
            <div class="stat-value"><?= count($recentSales) ?></div>
          </div>
        </div>
      </div>

      <!-- Últimos productos -->
      <div class="section" id="productsSection">
        <div class="section-header">
          <div class="section-title">Últimos productos agregados</div>
          <div class="section-tools">
            <div class="pill">
              <span class="icon"></span>
              <input id="productSearch" type="text" placeholder="Buscar producto... (código, nombre, talla, color)">
            </div>
          </div>
        </div>
        <div class="section-body">
          <table class="products-table" id="productsTable">
            <thead>
              <tr>
                <th data-key="id">ID</th>
                <th data-key="code">Código</th>
                <th data-key="name">Prenda</th>
                <th data-key="size">Talla</th>
                <th data-key="color">Color</th>
                <th data-key="cost_price">Precio Costo</th>
                <th data-key="sale_price">Precio Venta</th>
                <th data-key="stock">Stock</th>
                <th data-key="created_at">Creado</th>
              </tr>
            </thead>
            <tbody id="productsBody">
            <?php foreach ($products as $p): ?>
              <?php
                // Si created_at está en UTC en DB:
                [$pCreatedText, $pCreatedISO] = fmt_datetime_for_user($p['created_at'] ?? 'now', $user_tz, $time_fmt);
              ?>
              <tr>
                <td><?= $p['id'] ?></td>
                <td><?= htmlspecialchars($p['code'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['size'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['color'] ?? '') ?></td>
                <td><?= $currency . ' ' . number_format($p['cost_price'], 2) ?></td>
                <td><?= $currency . ' ' . number_format($p['sale_price'], 2) ?></td>
                <td>
                  <?php if ((int)$p['stock'] < 5): ?>
                    <span class="low-stock">⚠ <?= (int)$p['stock'] ?></span>
                  <?php else: ?>
                    <?= (int)$p['stock'] ?>
                  <?php endif; ?>
                </td>
                <td data-ts="<?= htmlspecialchars($pCreatedISO) ?>"><?= htmlspecialchars($pCreatedText) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Ventas recientes -->
      <div class="section" id="salesSection">
        <div class="section-header">
          <div class="section-title">Ventas recientes</div>
          <div class="section-tools">
            <div class="pill">
              <span class="icon">🔎</span>
              <input id="salesSearch" type="text" placeholder="Buscar venta... (producto, talla, color)">
            </div>
            <div class="pill">
              <span class="icon">🗓</span>
              <select id="dateQuick">
                <option value="all">Todo</option>
                <option value="today">Hoy</option>
                <option value="7">Últimos 7 días</option>
                <option value="30">Últimos 30 días</option>
              </select>
            </div>
            <div class="pill">
              <span class="icon">💲</span>
              <input id="minTotal" type="number" step="0.01" placeholder="Total min" style="width:100px">
              <span style="opacity:.6">—</span>
              <input id="maxTotal" type="number" step="0.01" placeholder="Total max" style="width:100px">
            </div>
            <div class="pill">
              <label for="rowsPerPage" style="font-size:12px;color:#64748b">Filas:</label>
              <select id="rowsPerPage">
                <option>10</option>
                <option selected>20</option>
                <option>50</option>
                <option>100</option>
              </select>
            </div>
          </div>
        </div>
        <div class="section-body">
          <table class="sales-table" id="salesTable">
            <thead>
              <tr>
                <th data-key="id">ID</th>
                <th data-key="product_name">Producto</th>
                <th data-key="size">Talla</th>
                <th data-key="color">Color</th>
                <th data-key="quantity">Cantidad</th>
                <th data-key="unit_price">Precio</th>
                <th data-key="total">Total</th>
                <th data-key="sale_date">Fecha</th>
                <th data-sortable="false">Acciones</th>
              </tr>
            </thead>
            <tbody id="salesBody">
            <?php foreach ($recentSales as $s): ?>
              <?php
                [$saleText, $saleISO] = fmt_datetime_for_user($s['sale_date'] ?? 'now', $user_tz, $time_fmt);
              ?>
              <tr>
                <td><?= $s['id'] ?></td>
                <td><?= htmlspecialchars($s['product_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($s['size'] ?? '') ?></td>
                <td><?= htmlspecialchars($s['color'] ?? '') ?></td>
                <td><?= (int)$s['quantity'] ?></td>
                <td><?= $currency . ' ' . number_format($s['unit_price'], 2) ?></td>
                <td><?= $currency . ' ' . number_format($s['total'], 2) ?></td>
                <td data-ts="<?= htmlspecialchars($saleISO) ?>"><?= htmlspecialchars($saleText) ?></td>
                <td>
                  <a href="delete_sale.php?id=<?= $s['id'] ?>" class="delete-btn"
                     onclick="return confirm('¿Seguro que deseas eliminar esta venta?');">
                    Eliminar
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <!-- Paginación -->
          <div class="pagination" id="salesPagination">
            <button class="btn" id="prevPage">⟨</button>
            <div class="pill"><span id="pageInfo">Página 1</span></div>
            <button class="btn" id="nextPage">⟩</button>
            <div class="spacer"></div>
            <div class="section-hint">Ordena clicando los encabezados ↑↓</div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <div id="notification" role="status" aria-live="polite"></div>

  <script>
   

    function showNotification(message, duration = 3000) {
      const notif = document.getElementById('notification');
      notif.textContent = message;
      notif.classList.add('show');
      setTimeout(() => notif.classList.remove('show'), duration);
    }
    <?php if (!empty($_GET['msg'])): ?>
      showNotification("<?= addslashes($_GET['msg']) ?>");
    <?php endif; ?>

    // Utils
    const parseMoney = (txt) => {
      const num = String(txt).replace(/[^0-9.,-]/g,'').replace(/\./g,'').replace(/,/g,'.');
      return parseFloat(num) || 0;
    };
    const parseDate = (txtOrISO, el) => {
      // Si el TD trae data-ts (ISO), úsalo; si no, intenta parsear el texto
      const iso = el?.dataset?.ts || txtOrISO;
      const t = Date.parse(iso);
      return isNaN(t) ? NaN : t;
    };

    // Búsqueda Productos
    const productSearch = document.getElementById('productSearch');
    const productsBody = document.getElementById('productsBody');
    productSearch.addEventListener('input', () => {
      const q = productSearch.value.trim().toLowerCase();
      [...productsBody.rows].forEach(tr => {
        const cells = [...tr.cells].map(td => td.textContent.toLowerCase());
        const show = !q || cells.some(t => t.includes(q));
        tr.style.display = show ? '' : 'none';
      });
    });

    // Ordenamiento genérico (ahora usa data-ts si está)
    function enableSorting(tableId){
      const table = document.getElementById(tableId);
      const thead = table.tHead;
      const tbody = table.tBodies[0];
      const getCellValue = (tr, idx) => tr.children[idx].textContent.trim();

      [...thead.rows[0].cells].forEach((th, idx) => {
        if (th.dataset.sortable === 'false') return;
        let asc = true;
        th.addEventListener('click', () => {
          const rows = [...tbody.rows];
          rows.sort((a, b) => {
            const Ael = a.children[idx];
            const Bel = b.children[idx];
            const A = getCellValue(a, idx);
            const B = getCellValue(b, idx);

            // Números (dinero)
            const aNum = parseFloat(A.replace(/[^0-9.,-]/g,'').replace(/\./g,'').replace(/,/g,'.'));
            const bNum = parseFloat(B.replace(/[^0-9.,-]/g,'').replace(/\./g,'').replace(/,/g,'.'));
            if (!isNaN(aNum) && !isNaN(bNum)) return asc ? aNum - bNum : bNum - aNum;

            // Fechas (preferir data-ts)
            const aDate = parseDate(A, Ael), bDate = parseDate(B, Bel);
            if (!isNaN(aDate) && !isNaN(bDate)) return asc ? aDate - bDate : bDate - aDate;

            // Texto
            return asc ? A.localeCompare(B) : B.localeCompare(A);
          });
          rows.forEach(r => tbody.appendChild(r));
          asc = !asc;
        });
      });
    }
    enableSorting('productsTable');
    enableSorting('salesTable');

    // Filtros + Paginación Ventas
    const salesBody = document.getElementById('salesBody');
    const salesSearch = document.getElementById('salesSearch');
    const dateQuick = document.getElementById('dateQuick');
    const minTotal = document.getElementById('minTotal');
    const maxTotal = document.getElementById('maxTotal');
    const rowsPerPage = document.getElementById('rowsPerPage');
    const prevPage = document.getElementById('prevPage');
    const nextPage = document.getElementById('nextPage');
    const pageInfo = document.getElementById('pageInfo');
    let page = 1;

    function getFilteredSalesRows(){
      const q = salesSearch.value.trim().toLowerCase();
      const min = parseFloat(minTotal.value || ''); const max = parseFloat(maxTotal.value || '');
      const now = new Date(); now.setHours(0,0,0,0);
      let fromTs = Number.NEGATIVE_INFINITY;

      const opt = dateQuick.value;
      if (opt === 'today') fromTs = now.getTime();
      else if (!isNaN(parseInt(opt))) {
        const days = parseInt(opt);
        const from = new Date(); from.setDate(from.getDate() - days); from.setHours(0,0,0,0);
        fromTs = from.getTime();
      }

      const allRows = [...salesBody.rows];
      return allRows.filter(tr => {
        const tds = [...tr.cells];
        const textHit = !q || tds.slice(0,8).some(td => td.textContent.toLowerCase().includes(q));
        const totalTxt = tds[6].textContent;
        const totalVal = parseMoney(totalTxt);
        const totalHit = (isNaN(min) || totalVal >= min) && (isNaN(max) || totalVal <= max);

        const dateTd = tds[7];
        const dateTxt = dateTd.textContent.trim();
        const ts = parseDate(dateTxt, dateTd);
        const dateHit = (fromTs === Number.NEGATIVE_INFINITY) || (!isNaN(ts) && ts >= fromTs);
        return textHit && totalHit && dateHit;
      });
    }

    function renderSales(){
      const rows = [...salesBody.rows];
      rows.forEach(r => r.style.display = 'none');

      const filtered = getFilteredSalesRows();
      const perPage = parseInt(rowsPerPage.value, 10) || 20;
      const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
      if (page > totalPages) page = totalPages;

      const start = (page - 1) * perPage;
      const end = start + perPage;
      filtered.slice(start, end).forEach(r => r.style.display = '');

      pageInfo.textContent = `Página ${page} de ${totalPages} — ${filtered.length} resultados`;
      prevPage.disabled = page <= 1;
      nextPage.disabled = page >= totalPages;
    }

    salesSearch.addEventListener('input', () => { page = 1; renderSales(); });
    dateQuick.addEventListener('change', () => { page = 1; renderSales(); });
    minTotal.addEventListener('input', () => { page = 1; renderSales(); });
    maxTotal.addEventListener('input', () => { page = 1; renderSales(); });
    rowsPerPage.addEventListener('change', () => { page = 1; renderSales(); });
    prevPage.addEventListener('click', () => { if (page>1){ page--; renderSales(); }});
    nextPage.addEventListener('click', () => { page++; renderSales(); });

    renderSales();
  </script>
</body>
</html>

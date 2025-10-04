<?php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
require 'auth.php'; // requiere iniciar sesion

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

/* ================== Preferencias de usuario (TZ y formato) ================== */
$user_tz  = $_SESSION['timezone']    ?? null;
$time_fmt = $_SESSION['time_format'] ?? null;

if (!$user_tz || !$time_fmt) {
  $q = $pdo->prepare("SELECT timezone, time_format FROM users WHERE id = :id LIMIT 1");
  $q->execute([':id' => $user_id]);
  if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $user_tz  = $row['timezone']    ?: 'America/New_York';
    $time_fmt = $row['time_format'] ?: '12h';
    $_SESSION['timezone']    = $user_tz;
    $_SESSION['time_format'] = $time_fmt;
  } else {
    $user_tz  = $user_tz  ?: 'America/New_York';
    $time_fmt = $time_fmt ?: '12h';
  }
}

/* ================== Moneda ================== */
$stmt = $pdo->prepare("SELECT currency_pref FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$currency = $stmt->fetchColumn() ?: 'S/.';

/* ================== Selección de rango ==================
   ?range = month | last3 | year | historico
   Acepta también “histórico” con tilde.
*/
$range = $_GET['range'] ?? 'month';
$range = strtolower($range);
if ($range === 'histórico') $range = 'historico';
if (!in_array($range, ['month','last3','year','historico'], true)) $range = 'month';
$rangeLabelMap = [
  'month'     => 'Mes actual',
  'last3'     => 'Últimos 3 meses',
  'year'      => 'Año actual',
  'historico' => 'Histórico',
];
$currentRangeLabel = $rangeLabelMap[$range];

/* ================== Utilidades de fecha ================== */
function dt_tz_now(string $tz): DateTime {
  return new DateTime('now', new DateTimeZone($tz));
}
function start_end_for_range(string $range, string $tz): array {
  $now = dt_tz_now($tz);

  if ($range === 'month') {
    $start = (clone $now)->modify('first day of this month')->setTime(0,0,0);
    $end   = (clone $start)->modify('last day of this month')->setTime(23,59,59);
    return [$start, $end, $now->format('F Y'), 'day'];
  }
  if ($range === 'last3') {
    $start = (clone $now)->modify('first day of -2 month')->setTime(0,0,0);
    $end   = (clone $now)->modify('last day of this month')->setTime(23,59,59);
    return [$start, $end, $start->format('M Y').' – '.$end->format('M Y'), 'month'];
  }
  if ($range === 'year') {
    $start = (clone $now)->setDate((int)$now->format('Y'), 1, 1)->setTime(0,0,0);
    $end   = (clone $now)->setDate((int)$now->format('Y'),12,31)->setTime(23,59,59);
    return [$start, $end, 'Año '.$now->format('Y'), 'month'];
  }
  // histórico: definiremos el inicio real tras consultar la primera venta
  $start = null;
  $end   = (clone $now);
  return [$start, $end, 'Histórico', 'month'];
}

/* Convierte un DateTime en TZ del usuario a UTC string para SQL BETWEEN */
function tz_to_utc_string(DateTime $d): string {
  $utc = clone $d;
  $utc->setTimezone(new DateTimeZone('UTC'));
  return $utc->format('Y-m-d H:i:s');
}

/* Formateo 12h/24h (para tablar si habilitas la tabla detallada) */
function fmt_time(DateTime $d, string $fmt): string {
  return $fmt === '24h' ? $d->format('H:i') : $d->format('g:i A');
}
function fmt_datetime_for_user($src, string $tz, string $fmt): string {
  $d = new DateTime((string)$src, new DateTimeZone('UTC')); // ⚠️ ajusta si tu DB no está en UTC
  $d->setTimezone(new DateTimeZone($tz));
  return $d->format('d M Y') . ', ' . fmt_time($d, $fmt);
}

/* ================== Período base ================== */
[$startTZ, $endTZ, $periodLabel, $granularity] = start_end_for_range($range, $user_tz);

/* ================== Traer datos ================== */
if ($range === 'historico') {
  $stmt = $pdo->prepare("SELECT MIN(sale_date) FROM sales WHERE user_id = ?");
  $stmt->execute([$user_id]);
  $firstSale = $stmt->fetchColumn();

  if ($firstSale) {
    $first = new DateTime($firstSale, new DateTimeZone('UTC'));
    $first->setTimezone(new DateTimeZone($user_tz));
    $startTZ = (clone $first)->modify('first day of this month')->setTime(0,0,0);
    $periodLabel = $startTZ->format('M Y').' – '.dt_tz_now($user_tz)->format('M Y');
  } else {
    $startTZ = (clone $endTZ)->setTime(0,0,0);
    $periodLabel = 'Sin ventas';
  }
}

if ($range === 'historico') {
  $stmt = $pdo->prepare("
    SELECT s.*, p.name AS product_name, p.cost_price
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE s.user_id = :uid
    ORDER BY s.sale_date DESC
  ");
  $stmt->execute([':uid'=>$user_id]);
} else {
  $startUTC = tz_to_utc_string($startTZ);
  $endUTC   = tz_to_utc_string($endTZ);
  $stmt = $pdo->prepare("
    SELECT s.*, p.name AS product_name, p.cost_price
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE s.user_id = :uid
      AND s.sale_date BETWEEN :start AND :end
    ORDER BY s.sale_date DESC
  ");
  $stmt->execute([':uid'=>$user_id, ':start'=>$startUTC, ':end'=>$endUTC]);
}
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumen
if ($range === 'historico') {
  $stmt = $pdo->prepare("
    SELECT 
      COALESCE(SUM(s.total),0) AS totalPeriodo,
      COUNT(*)                 AS numVentas,
      COALESCE(SUM(s.total - (p.cost_price*s.quantity)),0) AS ganancia
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE s.user_id = :uid
  ");
  $stmt->execute([':uid'=>$user_id]);
} else {
  $stmt = $pdo->prepare("
    SELECT 
      COALESCE(SUM(s.total),0) AS totalPeriodo,
      COUNT(*)                 AS numVentas,
      COALESCE(SUM(s.total - (p.cost_price*s.quantity)),0) AS ganancia
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE s.user_id = :uid
      AND s.sale_date BETWEEN :start AND :end
  ");
  $stmt->execute([':uid'=>$user_id, ':start'=>$startUTC, ':end'=>$endUTC]);
}
$resumen      = $stmt->fetch(PDO::FETCH_ASSOC);
$totalPeriodo = (float)($resumen['totalPeriodo'] ?? 0);
$numVentas    = (int)  ($resumen['numVentas']    ?? 0);
$ganancia     = (float)($resumen['ganancia']     ?? 0);

// Inventario
$inv = $pdo->prepare("SELECT SUM(stock) AS cant, SUM(stock*cost_price) AS valor FROM products WHERE user_id = ?");
$inv->execute([$user_id]);
$inv = $inv->fetch(PDO::FETCH_ASSOC);
$invCantidad = (int)($inv['cant'] ?? 0);
$invValor    = (float)($inv['valor'] ?? 0);

// Ingreso total acumulado
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sales WHERE user_id = ?");
$stmt->execute([$user_id]);
$ingresoTotal = (float)$stmt->fetchColumn();

/* ================== Agregaciones para gráfico ================== */
$chartLabels = [];
$chartData   = [];

if ($granularity === 'day' && $range!=='historico') {
  $cursor = clone $startTZ;
  while ($cursor <= $endTZ) {
    $chartLabels[] = (int)$cursor->format('j'); // 1..31
    $chartData[]   = 0;
    $cursor->modify('+1 day');
  }
  $idxBy = array_flip($chartLabels);
  foreach ($sales as $s) {
    $dt = new DateTime($s['sale_date'], new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($user_tz));
    $d = (int)$dt->format('j');
    if (isset($idxBy[$d])) $chartData[$idxBy[$d]] += (float)$s['total'];
  }
} else {
  $mStart = (clone $startTZ)->modify('first day of this month')->setTime(0,0,0);
  $mEnd   = (clone $endTZ)->modify('first day of this month')->setTime(0,0,0);
  $idxByKey = []; $i = 0;
  while ($mStart <= $mEnd) {
    $key = $mStart->format('Y-m');
    $chartLabels[] = $mStart->format('M');
    $chartData[]   = 0;
    $idxByKey[$key] = $i++;
    $mStart->modify('+1 month');
  }
  foreach ($sales as $s) {
    $dt = new DateTime($s['sale_date'], new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($user_tz));
    $key = $dt->format('Y-m');
    if (isset($idxByKey[$key])) $chartData[$idxByKey[$key]] += (float)$s['total'];
  }
}

/* ================== Ranking ================== */
if ($range === 'historico') {
  $stmt = $pdo->prepare("
    SELECT p.name, SUM(s.quantity) AS q, SUM(s.total) AS t
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE s.user_id = :uid
    GROUP BY p.id
    ORDER BY q DESC
  ");
  $stmt->execute([':uid'=>$user_id]);
} else {
  $stmt = $pdo->prepare("
    SELECT p.name, SUM(s.quantity) AS q, SUM(s.total) AS t
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE s.user_id = :uid
      AND s.sale_date BETWEEN :start AND :end
    GROUP BY p.id
    ORDER BY q DESC
  ");
  $stmt->execute([':uid'=>$user_id, ':start'=>$startUTC, ':end'=>$endUTC]);
}
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte de ventas</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="assets/img/favicon.png" type="image/png">
<link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{
  --bg:#e9eef5; --panel:#ffffff; --panel-2:#f2f5f9; --text:#0f172a; --muted:#64748b;
  --primary:#2563eb; --primary-2:#60a5fa; --success:#16a34a; --danger:#dc2626; --warning:#d97706;
  --border:#e2e8f0; --shadow:0 10px 24px rgba(15,23,42,.06); --radius:16px;
}
*{box-sizing:border-box}
html,body{overflow-x:hidden;}
img,svg{max-width:100%;height:auto;display:block}
body{background:var(--bg); color:var(--text); margin:0; font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}

/* Sidebar push en escritorio; no en móvil */
.container{ max-width:1200px; margin:24px auto 64px; padding:16px; background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); transition:margin-left .3s ease; }
.sidebar ~ .container{ margin-left:78px; }
.sidebar.open ~ .container{ margin-left:250px; }
@media (max-width:1024px){
  .sidebar ~ .container, .sidebar.open ~ .container{ margin-left:0; }
}

.header{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px}
.header .title{display:flex; align-items:center; gap:12px}
.header .icon{ width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,#e0edff,#f1f7ff); display:grid; place-items:center; border:1px solid #dbeafe; box-shadow:var(--shadow)}
.header h2{margin:0; font-size:24px; font-weight:800; color:#0b1220}
.sub{font-size:12px; color:var(--muted); margin-top:2px}
.actions{display:flex; gap:8px; align-items:center; flex-wrap:wrap}

/* ===== Botón Rango (dropdown) ===== */
.range-dropdown{ position:relative; }
.range-btn{
  display:inline-flex; align-items:center; gap:8px;
  padding:8px 12px; border-radius:10px; border:1px solid var(--border);
  background:#fff; color:var(--text); font-weight:700; box-shadow:var(--shadow);
  cursor:pointer; user-select:none; font-size:13px; line-height:1;
}
.range-btn:hover{ background:#f6f9fe; border-color:#cfd7e3 }
.range-caret{ width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid #64748b; }
.range-menu{
  position:absolute; top:calc(100% + 8px); left:0; min-width:190px;
  background:#fff; border:1px solid var(--border); border-radius:12px; box-shadow:var(--shadow); padding:6px;
  display:none; z-index:1000;
}
.range-menu.open{ display:block; }
.range-item{
  padding:10px 10px; border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:8px; font-size:13px;
}
.range-item:hover{ background:#eef4ff; }
.range-item.active{ background:#e6f0ff; font-weight:800; }

.btn{
  padding:10px 14px; border-radius:12px; border:1px solid var(--border);
  background:var(--panel-2); color:var(--text); font-weight:800; cursor:pointer; box-shadow:var(--shadow);
}
.btn:hover{ transform:translateY(-1px); background:#e8edf4; border-color:#b8c3d4}
.btn.primary{ background:linear-gradient(135deg,var(--primary),var(--primary-2)); color:#fff; border:none}

/* Summary cards */
.summary{ display:grid; grid-template-columns:repeat(3, minmax(220px,1fr)); gap:14px; margin:12px 0 18px; }
.card{ background: linear-gradient(135deg, #7b2ff7, #1c92d2) !important; color:#fff; padding:22px; border-radius:16px; min-width: 200px; text-align:center; box-shadow:0 10px 25px rgba(0,0,0,0.15); transition: transform .25s ease, box-shadow .25s ease, background .25s ease; }
.card:hover{ transform: translateY(-6px); box-shadow: 0 15px 35px rgba(0,0,0,0.22); background: linear-gradient(135deg, #1c92d2, #7b2ff7) !important; }
.card h3{margin:0 0 6px; font-size:22px; font-weight:800}
.card p{margin:0; font-size:12px; opacity:.95}

/* Secciones */
.section{ margin-top:14px; background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }
.section-header{ padding:14px 16px; border-bottom:1px solid var(--border); background:linear-gradient(180deg,#ffffff,#f7fafc); display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.section-title{ font-size:14px; font-weight:800 }
.section-hint{ font-size:12px; color:var(--muted) }
.section-body{ padding:16px }

/* Chart responsive wrapper */
.chart-wrap{ position:relative; width:100%; }
.chart-wrap canvas{ width:100% !important; height:380px !important; }
@media (max-width:720px){
  .chart-wrap canvas{ height:260px !important; }
}

/* Tabla + scroll horizontal sin romper layout */
.table-wrap{ width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:12px; }
.table-wrap table{ width:100%; border-collapse:separate; border-spacing:0; }
thead th{ font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:#475569; padding:14px 16px; background:#f8fafc; border-bottom:1px solid var(--border); text-align:left; position:sticky; top:0; z-index:1; }
tbody td{ padding:14px 16px; border-bottom:1px solid var(--border); white-space:nowrap; }
tbody tr{ transition: background .18s ease }
tbody tr:hover{ background:#f1f5f9 }

/* Dark mode */
body.dark{ background:#0c1326; color:#e5e7eb }
body.dark .container{ background:#0b1220; border-color:#1f2a4a }
body.dark .section, body.dark .range-btn{ background:#0b1220; border-color:#1f2a4a; color:#e5e7eb }
body.dark thead th{ background:#0e1630; color:#a5b4fc; border-color:#1f2a4a }
body.dark tbody td{ border-color:#1f2a4a }
body.dark tbody tr:hover{ background:rgba(99,102,241,.08) }
body.dark .btn{ background:#0e1630; border-color:#2a365a; color:#e5e7eb }
body.dark .btn:hover{ background:#132146; border-color:#33416b }
body.dark .range-menu{ background:#0b1220; border-color:#1f2a4a }
body.dark .range-item:hover{ background:#132146 }
body.dark .range-item.active{ background:#0e1630 }

/* ======= RESPONSIVE FINO ======= */
@media (max-width:980px){
  .summary{ grid-template-columns:repeat(2, minmax(0,1fr)); }
}
@media (max-width:640px){
  .header{ flex-direction:column; align-items:flex-start; gap:10px; }
  .actions{ width:100%; gap:10px; }
  .actions .btn, .actions .range-btn{ flex:1 1 auto; text-align:center; }
  .summary{ grid-template-columns:1fr; }
  .container{ margin:16px auto 80px; padding:12px; border-radius:12px; }
}

/* Impresión */
@media print{
  .actions, .header .icon, .sidebar, #sidebar, .range-menu { display:none !important; }
  .container{ box-shadow:none; border:none; margin:0; }
}
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="container">
  <div class="header">
    <div class="title">
      <div class="icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
          <path d="M5 3h10l4 4v14H5z" stroke="#2563eb" stroke-width="2"/>
          <path d="M15 3v5h5" stroke="#60a5fa" stroke-width="2"/>
          <path d="M8 13h8M8 17h5" stroke="#2563eb" stroke-width="2"/>
        </svg>
      </div>
      <div>
        <h2>Reporte de ventas</h2>
        <div class="sub">
          Período: <?= htmlspecialchars($periodLabel) ?> 
          <span style="margin-left:8px; font-weight:700; font-size:12px; color:#2563eb; background:#e6f0ff; padding:4px 8px; border-radius:999px;">
            <?= htmlspecialchars($user_tz) ?> · <?= htmlspecialchars(strtoupper($time_fmt)) ?>
          </span>
        </div>
      </div>
    </div>

    <div class="actions">
      <div class="range-dropdown" id="rangeDropdown">
        <button type="button" class="range-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="rangeMenu">
          <span>Rango: <?= htmlspecialchars($currentRangeLabel) ?></span>
          <span class="range-caret"></span>
        </button>
        <div class="range-menu" id="rangeMenu" role="menu">
          <a class="range-item <?= $range==='month' ? 'active':'' ?>" href="?range=month" role="menuitem">📅 Mes actual</a>
          <a class="range-item <?= $range==='last3' ? 'active':'' ?>" href="?range=last3" role="menuitem">🗓️ Últimos 3 meses</a>
          <a class="range-item <?= $range==='year' ? 'active':'' ?>" href="?range=year" role="menuitem">📆 Año actual</a>
          <div style="height:6px"></div>
          <a class="range-item <?= $range==='historico' ? 'active':'' ?>" href="?range=historico" role="menuitem">⏳ Histórico</a>
        </div>
      </div>

      <button class="btn" id="btnExport">Exportar CSV</button>
      <button class="btn primary" onclick="window.print()">Imprimir</button>
    </div>
  </div>

  <!-- Cards de resumen del período -->
  <div class="summary">
    <div class="card ventas-periodo"><h3><?= $currency . ' ' . number_format($totalPeriodo,2) ?></h3><p>Total ventas <?= htmlspecialchars($periodLabel) ?></p></div>
    <div class="card num-ventas"><h3><?=$numVentas?></h3><p>Número de ventas</p></div>
    <div class="card ganancia"><h3><?= $currency . ' ' . number_format($ganancia,2) ?></h3><p>Saldo / Ganancia</p></div>
    <div class="card inventario"><h3><?=$invCantidad?></h3><p>Prendas en inventario</p></div>
    <div class="card valor-inventario"><h3><?= $currency . ' ' . number_format($invValor,2) ?></h3><p>Valor del inventario</p></div>
    <div class="card ingreso-total"><h3><?= $currency . ' ' . number_format($ingresoTotal,2) ?></h3><p>Ingreso total acumulado</p></div>
  </div>

  <!-- Gráfico -->
  <div class="section">
    <div class="section-header">
      <div class="section-title">
        <?= ($granularity==='day' && $range!=='historico') ? "Ventas por día ($currency)" : "Ventas por mes ($currency)" ?>
      </div>
      <div class="section-hint">
        <?= htmlspecialchars($periodLabel) ?>
      </div>
    </div>
    <div class="section-body">
      <div class="chart-wrap">
        <canvas id="ventasChart"></canvas>
      </div>
    </div>
  </div>

  <!-- Ranking -->
  <div class="section" style="margin-top:14px;">
    <div class="section-header">
      <div class="section-title">Ranking de productos más vendidos</div>
      <div class="section-hint"><?= $ranking ? 'Ordenado por cantidad vendida' : 'No hay ventas para este período' ?></div>
    </div>
    <div class="section-body">
      <div class="table-wrap">
        <table id="rankingTable">
          <thead>
            <tr><th>Producto</th><th>Cantidad</th><th>Total (<?= $currency ?>)</th></tr>
          </thead>
          <tbody id="rankingBody">
            <?php if ($ranking): foreach($ranking as $r): ?>
              <tr>
                <td><?=htmlspecialchars($r['name'])?></td>
                <td><?= (int)$r['q'] ?></td>
                <td><?= $currency . ' ' . number_format($r['t'],2) ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="3">No hay ventas para este período.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
  // Dark mode coherente
  if(localStorage.getItem('darkMode') === 'true'){
    document.body.classList.add('dark');
  }

  // Dropdown Rango (accesible y pequeño)
  (function(){
    const dd = document.getElementById('rangeDropdown');
    const btn = dd.querySelector('.range-btn');
    const menu = dd.querySelector('.range-menu');
    const toggle = (open) => {
      menu.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      toggle(!menu.classList.contains('open'));
    });
    document.addEventListener('click', () => toggle(false));
    btn.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') toggle(false);
    });
  })();

  // ChartJS
  const ctx = document.getElementById('ventasChart').getContext('2d');
  const labels = <?= json_encode(array_values($chartLabels)) ?>;
  const dataVals = <?= json_encode(array_map(fn($v)=>round($v,2), $chartData)) ?>;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Ventas (<?= $currency ?>)',
        data: dataVals,
        backgroundColor: 'rgba(37, 99, 235, 0.75)',
        borderColor: 'rgba(37, 99, 235, 1)',
        borderWidth: 1,
        hoverBackgroundColor: 'rgba(96, 165, 250, 0.85)'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false, // permite que la altura definida por CSS mande
      plugins: {
        legend: { display: false },
        tooltip: { mode: 'index', intersect: false }
      },
      scales: {
        y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.25)' } },
        x: { grid: { display: false } }
      }
    }
  });

  // Exportar CSV (ranking visible)
  document.getElementById('btnExport')?.addEventListener('click', () => {
    const body = document.getElementById('rankingBody');
    const rows = [['Producto','Cantidad','Total (<?= $currency ?>)']];
    [...body.rows].forEach(tr => {
      if (tr.style.display === 'none') return;
      const cols = [...tr.cells].map(td => td.textContent.replace(/\s+/g,' ').trim());
      if (cols.length === 3) rows.push(cols);
    });
    const csv = rows.map(r => r.map(v => `"${v.replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'ranking_ventas_<?= strtolower($range) ?>.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
  });
</script>
</body>
</html>

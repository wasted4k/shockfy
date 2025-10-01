<?php
// billing.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php'; // exige login + email verificado
require_once __DIR__ . '/auth.php';


$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  header('Location: login.php'); exit;
}

/**
 * Carga datos mínimos del usuario para billing
 */
$stmt = $pdo->prepare("
  SELECT id, full_name, username, currency_pref, plan, trial_started_at, trial_ends_at, trial_cancelled_at
  FROM users
  WHERE id = :id
  LIMIT 1
");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$currencyPref = $user['currency_pref'] ?: 'S/.';
$currentPlan  = $user['plan'] ?: null;

$trialEndsAt  = $user['trial_ends_at'] ?? null;
$trialCancelledAt = $user['trial_cancelled_at'] ?? null;
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$trialActive = false;
$trialDaysLeft = null;
if ($trialEndsAt && !$trialCancelledAt) {
  try {
    $trialEnd = new DateTimeImmutable($trialEndsAt, new DateTimeZone('UTC'));
    if ($now < $trialEnd) {
      $trialActive = true;
      $diff = $now->diff($trialEnd);
      $trialDaysLeft = max(0, (int)$diff->format('%a'));
    }
  } catch (Throwable $e) { /* ignora parseos inválidos */ }
}

/**
 * Mapa para mostrar nombres bonitos sin cambiar los códigos en BD
 */
function display_plan_name(?string $code): string {
  if (!$code) return '—';
  $map = ['starter' => 'Premium', 'free' => 'Free'];
  return $map[strtolower($code)] ?? strtoupper($code);
}

/**
 * Planes disponibles (mostrar "Premium" para starter y precio fijo $4.99/mes)
 */
$PLANS = [
  // NOTA: el código de plan sigue siendo 'starter' para no romper contratos;
  // solo el nombre visible cambia a "Premium" y el precio se fija a $4.99/mes.
  'starter' => [
    'name' => 'Premium',
    'price_label' => '$4.99/mes',
    'badge' => 'Recomendado',
    'features' => [
      'Inventario Ilimitado',
      'Reporte de ventas y ganancias',
      'Soporte Personalizado',
      'Alertas de stock bajo',
      'Importación/Exportación (CSV/PDF)',
      'Historial de movimientos',
      
      
    'Backups automáticos diarios',
    ],
  ],
  'free' => [
    'name' => 'Free',
    'price_label' => 'Gratis',
    'badge' => 'Post-trial',
    'features' => [
      'Todas las funciones de manera ilimitada por 15 dias.',
      'Sin Soporte Personalizado',
      'Sin Backups automáticos diarios'
    ],
    'help' => 'Estas son las limitaciones del plan free.'
  ],
];

// Mensajes (toasts) por querystring o flash
$success = $_GET['ok']   ?? ($_SESSION['billing_ok']   ?? '');
$error   = $_GET['err']  ?? ($_SESSION['billing_err']  ?? '');
unset($_SESSION['billing_ok'], $_SESSION['billing_err']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Planes y Facturación</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
  <style>
    :root{
      --sidebar-w:260px;
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#6b7280;
      --primary:#2563eb;        /* tono base */
      --primary-600:#1e3a8a;    /* tono oscuro para gradiente */
      --ring:rgba(37,99,235,.28);
      --success:#16a34a;
      --warning:#f59e0b;
      --danger:#dc2626;
      --border:#e5e7eb;
      --shadow:0 16px 32px rgba(2,6,23,.08);
      --radius:16px;
      --nudge-x: -16px;
    }

    body{ margin:0; background:var(--bg); color:var(--text); font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
    .app-shell{ display:flex; min-height:100vh; transition: filter .25s ease, transform .25s ease; }
    .content{ flex:1; padding:28px 24px 48px; }
    .with-fixed-sidebar{ margin-left:var(--sidebar-w); }
    .container{ max-width:1100px; margin:0 auto; position:relative; transform: translateX(var(--nudge-x)); }
    @media (max-width: 900px){ .container{ transform:none; } }

    body.modal-open .app-shell{
      filter: blur(6px) saturate(.9);
      pointer-events:none;
      user-select:none;
    }

    .header-wrap{ text-align:center; margin:0 auto 18px; }
    .page-title{ font-size:30px; font-weight:800; letter-spacing:.2px; margin:0; }
    .page-sub{ color:var(--muted); margin-top:6px; font-size:13px; }

    .grid{ display:grid; gap:18px; grid-template-columns:1fr; }
    .plans{ display:grid; grid-template-columns: repeat(auto-fit, minmax(280px,1fr)); gap:18px; }

    .card{
      background:var(--card); border:1px solid var(--border);
      border-radius:var(--radius); box-shadow:var(--shadow); padding:22px;
    }
    .card h3{ margin:0 0 6px; font-size:20px; font-weight:800; }
    .price{ font-size:26px; font-weight:900; margin:4px 0 10px; }
    .badge{ display:inline-block; background:#eef6ff; color:#0b61c2; border:1px solid #b9dcff; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; }
    .ul{ margin:12px 0 6px; padding-left:20px; color:#0f172a; }
    .ul li{ margin:6px 0; }

    .btn{
      display:inline-flex; align-items:center; justify-content:center; gap:10px;
      padding:12px 18px; border-radius:12px; border:1px solid rgba(0,0,0,.04);
      background: linear-gradient(135deg, var(--primary), #3b82f6);
      color:#fff; font-weight:800; letter-spacing:.01em;
      box-shadow: 0 6px 16px rgba(37,99,235,.18);
      cursor:pointer; transition: transform .06s ease, box-shadow .2s ease, filter .2s ease;
      text-decoration:none;
    }
    .btn:hover{ filter:brightness(.98); box-shadow: 0 10px 24px rgba(37,99,235,.22); }
    .btn:active{ transform: translateY(1px); }
    .btn:focus-visible{ outline: none; box-shadow: 0 0 0 4px var(--ring); }
    .btn.outline{
      background:#fff; color:var(--primary);
      border:1px solid rgba(37,99,235,.4);
      box-shadow: 0 4px 12px rgba(2,6,23,.06);
    }
    .btn.outline:hover{ background:#f8fbff; }
    .btn[disabled]{ opacity:.65; cursor:not-allowed; filter:grayscale(.1); box-shadow:none; }

    .topbar{ display:flex; gap:10px; align-items:center; justify-content:center; flex-wrap:wrap; margin-bottom:8px; }
    .chip{
      display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; font-size:12px; border:1px solid var(--border);
      background:#fff; color:#111827; font-weight:700;
    }
    .chip .dot{ width:8px; height:8px; border-radius:999px; background:#34d399; display:inline-block; }
    .chip.warn .dot{ background:var(--warning); }
    .muted{ color:var(--muted); font-size:12px; }
    .current{ border:2px solid #93c5fd; }
    .hr{ height:1px; background:var(--border); margin:16px 0; }

    .toast{ padding:12px 16px; border-radius:12px; font-weight:700; text-align:center; }
    .toast.success{ background:var(--success); color:#fff; }
    .toast.error{ background:var(--danger); color:#fff; }

    .modal{
      position:fixed; inset:0; display:none;
      align-items:center; justify-content:center;
      background: rgba(2,6,23,.48);
      backdrop-filter: blur(2px) saturate(1.1);
      z-index: 4000;
    }
    .modal[aria-hidden="false"]{ display:flex; }

    .modal .panel{
      width:560px; max-width:calc(100% - 28px);
      background:#ffffff; border:1px solid var(--border); border-radius:18px; box-shadow:0 24px 60px rgba(2,6,23,.25);
      padding:18px;
      transform: translateY(6px); opacity:.98; animation: pop .14s ease-out;
    }
    @keyframes pop{ from{ transform: translateY(14px); opacity:.0; } to{ transform: translateY(6px); opacity:.98; } }
    .modal .head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .modal .title{ font-size:18px; font-weight:900; letter-spacing:.01em; }
    .modal .close{
      border:1px solid var(--border); background:#f8fafc; border-radius:10px; padding:8px 12px; cursor:pointer; font-weight:800;
      transition: background .2s ease;
    }
    .modal .close:hover{ background:#eef2ff; }
    .modal .grid{ display:grid; gap:12px; }
    .modal .choice{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .btnx{
      padding:14px 12px; border-radius:12px; border:1px solid rgba(0,0,0,.05);
      background: linear-gradient(135deg, #ffffff, #f8fbff);
      font-weight:900; letter-spacing:.01em; cursor:pointer;
      box-shadow: 0 6px 16px rgba(2,6,23,.06);
      transition: transform .06s ease, box-shadow .2s ease, background .2s ease;
      text-align:center;
    }
    .btnx:hover{ transform: translateY(-1px); box-shadow: 0 10px 22px rgba(2,6,23,.08); background: linear-gradient(135deg, #ffffff, #eef6ff); }
    .modal .subgrid{ display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; }
    .opt{
      padding:12px; border-radius:12px; border:1px solid rgba(0,0,0,.05);
      background:#fff; cursor:pointer; text-align:center; font-weight:800;
      transition: transform .06s ease, box-shadow .2s ease, background .2s ease;
      box-shadow: 0 6px 16px rgba(2,6,23,.06);
    }
    .opt:hover{ transform: translateY(-1px); background:#f8fafc; box-shadow: 0 10px 22px rgba(2,6,23,.08); }
    .modal .note{ color:var(--muted); font-size:12px; text-align:center; }
  </style>
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="content with-fixed-sidebar">
      <div class="container">
        <div class="header-wrap">
          <h1 class="page-title">Planes y facturación</h1>
          <div class="page-sub">Nuestro plan Premium incluye todas las funciones que tenemos al alcance para ti.</div>
        </div>

        <div class="grid">

          <?php if ($success): ?>
            <div class="toast success"><?= htmlspecialchars($success) ?></div>
          <?php elseif ($error): ?>
            <div class="toast error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="card">
            <div class="topbar">
              <span class="chip">
                Plan actual: <strong><?= htmlspecialchars(display_plan_name($currentPlan)) ?></strong>
              </span>
              <?php if ($trialActive): ?>
                <span class="chip warn"><span class="dot"></span> Trial activo — quedan <?= (int)$trialDaysLeft ?> día(s)</span>
              <?php else: ?>
                <span class="chip"><span class="dot"></span> Trial finalizado</span>
              <?php endif; ?>
            </div>
            <div class="muted" style="text-align:center">
              <?php if ($trialActive): ?>
                Disfruta todas las funciones durante el periodo de prueba. Al finalizar, puedes continuar en <strong>Premium</strong> o quedarte en <strong>Free</strong> con limitaciones.
              <?php else: ?>
                Tu periodo de prueba terminó. Debes activar un plan para poder seguir utilizando nuestra plataforma.
              <?php endif; ?>
            </div>

            <div class="hr"></div>

            <div class="plans">
              <!-- Plan Premium (código: starter) -->
              <div class="card <?= $currentPlan === 'starter' ? 'current' : '' ?>">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                  <h3><?= htmlspecialchars($PLANS['starter']['name']) ?></h3>
                  <span class="badge"><?= htmlspecialchars($PLANS['starter']['badge']) ?></span>
                </div>
                <div class="price"><?= htmlspecialchars($PLANS['starter']['price_label']) ?></div>
                <ul class="ul">
                  <?php foreach ($PLANS['starter']['features'] as $f): ?>
                    <li><?= htmlspecialchars($f) ?></li>
                  <?php endforeach; ?>
                </ul>

                <!-- Acción: abrir modal de método de pago -->
                <button class="btn" type="button"
                  onclick="openPayModal('starter')"
                  <?= $currentPlan === 'starter' ? 'disabled' : '' ?>>
                  <?= $currentPlan === 'starter' ? 'Plan actual' : 'Elegir plan' ?>
                </button>
              </div>

              <!-- Plan Free (informativo) -->
              <div class="card <?= ($currentPlan === 'free' || !$currentPlan) ? 'current' : '' ?>">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                  <h3><?= htmlspecialchars($PLANS['free']['name']) ?></h3>
                  <span class="badge"><?= htmlspecialchars($PLANS['free']['badge']) ?></span>
                </div>
                <div class="price"><?= htmlspecialchars($PLANS['free']['price_label']) ?></div>
                <ul class="ul">
                  <?php foreach ($PLANS['free']['features'] as $f): ?>
                    <li><?= htmlspecialchars($f) ?></li>
                  <?php endforeach; ?>
                </ul>
                <?php if (!empty($PLANS['free']['help'])): ?>
                  <div class="muted"><?= htmlspecialchars($PLANS['free']['help']) ?></div>
                <?php endif; ?>

                <button class="btn outline" type="button" disabled>
                  <?= ($currentPlan === 'free' || !$currentPlan) ? 'Plan Actual' : 'Free' ?>
                </button>
              </div>
            </div>

            <div class="hr"></div>
            <div class="muted" style="text-align:center">
              ¿Necesitas otro plan o factura local? Podemos añadir pasarela (Stripe/MercadoPago) luego. Por ahora solo cambiamos tu plan en base de datos.
            </div>
          </div>

        </div>
      </div>
    </main>
  </div>

  <!-- ===== Modal de métodos de pago ===== -->
  <div id="payModal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="payTitle">
    <div class="panel">
      <div class="head">
        <div class="title" id="payTitle">Elige un método de pago</div>
        <button class="close" type="button" onclick="closePayModal()">Cerrar</button>
      </div>

      <div class="grid">
        <div class="choice" role="group" aria-label="Grupo de métodos">
          <button class="btnx" type="button" onclick="choosePayGroup('card')">Tarjeta de Débito/Crédito</button>
          <button class="btnx" type="button" onclick="choosePayGroup('others')">Otros métodos de pago</button>
        </div>

        <div id="otherMethods" style="display:none;">
          <div class="muted" style="margin-bottom:6px;">Selecciona una opción:</div>
          <div class="subgrid">
            <button class="opt" type="button" onclick="goOther('binance')">Binance</button>
            <button class="opt" type="button" onclick="goOther('paypal')">PayPal</button>
            <button class="opt" type="button" onclick="goOther('soles')">Soles</button>
            <button class="opt" type="button" onclick="goOther('bolivares')">Bolívares</button>
            <button class="opt" type="button" onclick="goOther('usdt')">USDT</button>
            <button class="opt" type="button" onclick="goOther('bitcoin')">Bitcoin</button>
          </div>
        </div>

        <div class="modal-line muted" style="text-align:center;">
          Plan seleccionado: <strong id="modalPlan">Premium</strong>
        </div>
        <div class="note">Tu pago se procesará de forma segura según el método elegido.</div>
      </div>
    </div>
  </div>

  <script>
    // ===== Modal logic =====
    let chosenPlan = 'starter'; // mantenemos el código 'starter'
    const modal      = document.getElementById('payModal');
    const otherBox   = document.getElementById('otherMethods');
    const modalPlan  = document.getElementById('modalPlan');

    function getPlanLabel(planCode){
      if (!planCode) return '—';
      return (planCode.toLowerCase() === 'starter') ? 'Premium' : (planCode);
    }

    function openPayModal(plan){
      chosenPlan = plan || 'starter';
      modalPlan.textContent = getPlanLabel(chosenPlan);
      otherBox.style.display = 'none';
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
      setTimeout(()=> {
        const first = modal.querySelector('.btnx');
        if (first) first.focus();
      }, 20);
    }
    function closePayModal(){
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
    }
    window.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') {
        closePayModal();
      }
    });
    window.addEventListener('click', (e)=>{
      if (e.target === modal) closePayModal();
    });

    function choosePayGroup(group){
      if (group === 'card') {
        // Página para tarjeta (integraremos Stripe/MercadoPago allí)
        const url = `pago_tarjeta.php?plan=${encodeURIComponent(chosenPlan)}`;
        window.location.href = url;
      } else {
        otherBox.style.display = 'block';
      }
    }

    function goOther(method){
      // Redirige a órdenes con el método elegido
      const url = `orden_compra.php?plan=${encodeURIComponent(chosenPlan)}&method=${encodeURIComponent(method)}`;
      window.location.href = url;
    }
  </script>
</body>
</html>

<?php
// pago_tarjeta.php — Redirección a Stripe Checkout (tarjeta) con UI premium

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php'; // exige login + email verificado
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

// Plan: mantenemos el código 'starter' (contrato), pero mostramos "Premium"
$planCode = $_GET['plan'] ?? 'starter';
$planLabel = (strtolower($planCode) === 'starter') ? 'Premium' : strtoupper($planCode);

// Precio fijo visible (Stripe controla el precio real por STRIPE_PRICE_STARTER)
$displayPrice = '$4.99/mes';

// Diagnóstico de entorno (opcional: útil para no perder tiempo)
$stripeSecret = getenv('STRIPE_SECRET');
$stripePrice  = getenv('STRIPE_PRICE_STARTER');
$stripeOk     = !empty($stripeSecret) && !empty($stripePrice);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Pagar con tarjeta · <?= htmlspecialchars($planLabel) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <style>
    :root{
      --bg:#f5f7fb; --card:#ffffff; --text:#0f172a; --muted:#6b7280;
      --primary:#2563eb; --primary-2:#3b82f6; --ring:rgba(37,99,235,.28);
      --border:#e5e7eb; --shadow:0 18px 38px rgba(2,6,23,.10); --radius:18px;
    }
    *{box-sizing:border-box}
    body{ margin:0; font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background:var(--bg); color:var(--text); }
    .shell{ min-height:100vh; display:grid; place-items:center; padding:24px; }
    .card{
      width:780px; max-width:100%;
      background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
      box-shadow:var(--shadow); padding:26px;
    }
    .header{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
    .brand{ font-size:20px; font-weight:900; letter-spacing:.01em; }
    .muted{ color:var(--muted); font-size:13px; }
    .plan{
      display:flex; gap:16px; align-items:flex-start; margin:10px 0 18px;
      background: #fbfdff; border:1px solid #e6eefc; border-radius:14px; padding:14px;
    }
    .plan .name{ font-size:22px; font-weight:900; letter-spacing:.01em; }
    .plan .price{ font-size:26px; font-weight:900; margin-top:2px; color:#0f172a; }
    .list{ margin:10px 0 6px; padding-left:18px; }
    .list li{ margin:6px 0; }

    .actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
    .btn{
      display:inline-flex; align-items:center; justify-content:center; gap:10px;
      padding:12px 18px; border-radius:12px; border:1px solid rgba(0,0,0,.04);
      background: linear-gradient(135deg, var(--primary), var(--primary-2));
      color:#fff; font-weight:800; letter-spacing:.01em;
      box-shadow: 0 8px 22px rgba(37,99,235,.22);
      cursor:pointer; transition: transform .06s ease, box-shadow .2s ease, filter .2s ease;
      text-decoration:none;
    }
    .btn:hover{ filter:brightness(.98); box-shadow: 0 12px 28px rgba(37,99,235,.26); }
    .btn:active{ transform: translateY(1px); }
    .btn:focus-visible{ outline:none; box-shadow:0 0 0 4px var(--ring); }
    .btn.secondary{
      background:#fff; color:var(--primary);
      border:1px solid rgba(37,99,235,.4); box-shadow:0 6px 14px rgba(2,6,23,.08);
    }
    .btn[disabled]{ opacity:.65; cursor:not-allowed; filter:grayscale(.1); box-shadow:none; }

    .row{ display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width: 820px){ .row{ grid-template-columns:1fr; } }

    .box{
      border:1px solid var(--border); border-radius:14px; padding:16px; background:#fff;
    }
    .box h3{ margin:0 0 8px; font-size:16px; font-weight:900; }
    .summary{ font-size:14px; }
    .hr{ height:1px; background:var(--border); margin:14px 0; }

    .alert{
      background:#fff4ed; border:1px solid #ffd6bf; color:#7a2e0e;
      padding:10px 12px; border-radius:12px; font-weight:700; margin-bottom:12px;
    }
    .ok{
      background:#ecfdf5; border:1px solid #bbf7d0; color:#065f46;
      padding:10px 12px; border-radius:12px; font-weight:700; margin-top:12px; display:none;
    }
    .err{
      background:#fee2e2; border:1px solid #fecaca; color:#7f1d1d;
      padding:10px 12px; border-radius:12px; font-weight:700; margin-top:12px; display:none;
    }

    .spinner{ display:none; width:18px; height:18px; border-radius:999px; border:2px solid rgba(255,255,255,.45); border-top-color:#fff; animation: spin .8s linear infinite; }
    @keyframes spin{ to{ transform: rotate(360deg); } }

    /* Banda de diagnóstico si faltan llaves Stripe */
    .diagnostic{ margin-bottom:12px; }
  </style>
</head>
<body>
  <div class="shell">
    <div class="card">
      <div class="header">
        <div class="brand">Pago con tarjeta</div>
        <div class="muted">Transacción segura · Stripe Checkout</div>
      </div>

      <?php if (!$stripeOk): ?>
        <div class="alert diagnostic">
          Faltan variables de entorno para Stripe.
          <?= empty($stripeSecret) ? 'STRIPE_SECRET ' : '' ?>
          <?= empty($stripePrice)  ? 'STRIPE_PRICE_STARTER ' : '' ?>
        </div>
      <?php endif; ?>

      <div class="plan">
        <div style="flex:1;">
          <div class="name"><?= htmlspecialchars($planLabel) ?></div>
          <div class="muted">Acceso completo a funciones avanzadas.</div>
          <ul class="list">
            <li>Inventario ilimitado</li>
            <li>Reportes y ganancias</li>
            <li>Soporte personalizado</li>
          </ul>
        </div>
        <div style="min-width:180px; text-align:right;">
          <div class="price"><?= htmlspecialchars($displayPrice) ?></div>
          <div class="muted">Se renueva mensualmente</div>
        </div>
      </div>

      <div class="row">
        <div class="box">
          <h3>Resumen</h3>
          <div class="summary">
            <div><strong>Plan:</strong> <?= htmlspecialchars($planLabel) ?></div>
            <div><strong>Precio:</strong> <?= htmlspecialchars($displayPrice) ?></div>
            <div><strong>Método:</strong> Tarjeta de débito/crédito</div>
          </div>
          <div class="hr"></div>
          <div class="muted">Serás redirigido a Stripe Checkout para completar el pago de forma segura.</div>
        </div>

        <div class="box">
          <h3>Acción</h3>
          <div class="muted" style="margin-bottom:8px;">Al continuar, crearemos una sesión segura de Checkout con Stripe.</div>
          <div class="actions">
            <button id="payBtn" class="btn" onclick="startCheckout()" <?= !$stripeOk ? 'disabled' : '' ?>>
              <span>Pagar con tarjeta</span>
              <span id="spn" class="spinner" aria-hidden="true"></span>
            </button>
            <a class="btn secondary" href="billing.php">Volver a planes</a>
          </div>

          <div id="okBox"  class="ok">Redirigiendo a Stripe…</div>
          <div id="errBox" class="err">No se pudo iniciar el pago. Inténtalo nuevamente.</div>
        </div>
      </div>
    </div>
  </div>

  <script>
    async function startCheckout(){
      const btn  = document.getElementById('payBtn');
      const spn  = document.getElementById('spn');
      const ok   = document.getElementById('okBox');
      const err  = document.getElementById('errBox');

      err.style.display = 'none';
      ok.style.display  = 'none';
      btn.disabled = true;
      spn.style.display = 'inline-block';

      try {
        const fd = new FormData();
        // Si tu checkout_create.php necesita el plan:
        fd.append('plan', 'starter');

        const r = await fetch('checkout_create.php', { method:'POST', body: fd });
        const d = await r.json();

        if (d && d.url) {
          ok.textContent = 'Redirigiendo a Stripe…';
          ok.style.display = 'block';
          window.location.href = d.url;
        } else {
          err.textContent = (d && d.error) ? d.error : 'No se pudo iniciar el pago.';
          err.style.display = 'block';
          btn.disabled = false;
          spn.style.display = 'none';
        }
      } catch (e) {
        err.textContent = 'Error de red al crear la sesión.';
        err.style.display = 'block';
        btn.disabled = false;
        spn.style.display = 'none';
      }
    }
  </script>
</body>
</html>

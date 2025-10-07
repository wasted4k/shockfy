<?php
// waiting_confirmation.php — Pantalla para usuarios con pago pendiente de confirmación.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

// Leer estado de cuenta y, si no es pending_confirmation, redirigir a billing
$stmt = $pdo->prepare("SELECT id, full_name, email, account_state FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) { header('Location: login.php'); exit; }
if (($user['account_state'] ?? 'active') !== 'pending_confirmation') {
  header('Location: billing.php');
  exit;
}

// Buscar la última solicitud de pago del usuario (por si queremos mostrar info)
$pr = null;
$stmt2 = $pdo->prepare("
  SELECT id, method, amount_usd, currency, notes, receipt_path, status, created_at
  FROM payment_requests
  WHERE user_id = :uid
  ORDER BY created_at DESC, id DESC
  LIMIT 1
");
$stmt2->execute(['uid' => $user_id]);
$pr = $stmt2->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Pago en validación</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
  <style>
    :root{
      --sidebar-w:260px; --bg:#f5f7fb; --card:#ffffff; --text:#0f172a; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb; --shadow:0 16px 32px rgba(2,6,23,.08); --radius:16px;
      --success:#16a34a; --success-strong:#15803d;
    }
    body{ margin:0; background:var(--bg); color:var(--text); font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
    .app-shell{ display:flex; min-height:100vh; }
    .content{ flex:1; padding:28px 24px 48px; }
    .with-fixed-sidebar{ margin-left:var(--sidebar-w); }
    .container{ max-width:900px; margin:0 auto; display:flex; flex-direction:column; align-items:center; text-align:center; }
    .card{ background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:24px; width:100%; }
    h1{ margin:0 0 8px; font-size:26px; font-weight:900; }
    p{ color:var(--muted); margin:8px 0; }
    .btn{ display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:12px 18px; border-radius:12px; border:1px solid rgba(0,0,0,.04); background:#111827; color:#fff; font-weight:800; text-decoration:none; cursor:pointer; }
    .btn.secondary{ background:#fff; color:#111827; border:1px solid var(--border); }
    .btn.success{ background:var(--success); border:1px solid var(--success-strong); color:#fff; }
    .muted{ color:var(--muted); font-size:12px; }
    .grid{ display:grid; gap:18px; grid-template-columns:1fr; width:100%; }
    .row{ display:grid; grid-template-columns:1fr; gap:18px; }
    @media (min-width:860px){ .row{ grid-template-columns:1fr 1fr; } }
    .kvs{ display:flex; flex-direction:column; gap:8px; text-align:left; }
    .kv{ display:flex; gap:8px; }
    .kv .k{ width:160px; color:#6b7280; }
    .kv .v{ font-weight:700; color:#111827; }
    .receipt-preview{
      display:flex; align-items:center; justify-content:center; background:#fafafa; border:1px dashed var(--border);
      border-radius:12px; padding:12px; min-height:120px;
    }
    .receipt-preview a{ text-decoration:none; color:var(--primary); font-weight:700; }
    .tips{ text-align:left; color:#374151; }
    .tips li{ margin:6px 0; }
    .badge{
      display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px;
      background:#fff7ed; border:1px solid #fed7aa; color:#92400e; font-weight:800; font-size:12px;
    }


/* ===== Waiting Confirmation: responsive móvil ===== */

/* 0) Seguridad: evita scroll lateral y medios fluidos */
html, body { overflow-x: hidden; }
img, svg, video { max-width: 100%; height: auto; }

/* 1) Quitar el “push” de la sidebar en móvil */
@media (max-width:1024px){
  .content.with-fixed-sidebar{ margin-left: 0 !important; }
  .app-shell{ overflow-x:hidden; }
}

/* 2) Contenedor y tarjetas cómodas en pantallas chicas */
@media (max-width:700px){
  .content{ padding: 20px 14px 60px; }
  .container{ max-width: 100%; margin: 0 auto; }
  .card{ padding: 16px; border-radius: 14px; }
  h1{ font-size: 22px; }
  .grid{ gap: 12px; }
  .row{ gap: 12px; } /* ya es 1 columna en <860px */
  .badge{ font-size: 11px; padding: 6px 10px; }
  .tips{ font-size: 14px; }
}

/* 3) Pares clave–valor: que no se corten ni desborden */
@media (max-width:700px){
  .kvs{ gap: 10px; }
  .kv{ display: grid; grid-template-columns: auto 1fr; column-gap: 8px; row-gap: 2px; }
  .kv .k{ width: auto; font-weight: 600; color:#6b7280; }
  .kv .v{ min-width: 0; overflow-wrap: anywhere; }
}
@media (max-width:380px){
  .kv{ grid-template-columns: 1fr; }
}

/* 4) Vista del comprobante y enlaces largos */
@media (max-width:700px){
  .receipt-preview{ min-height: 96px; padding: 10px; }
  .receipt-preview a{ word-break: break-word; }
}

/* 5) Botones a ancho completo en móvil */
@media (max-width:700px){
  .btn{ width: 100%; text-align: center; }
  /* si hay varios botones en la misma fila, que se apilen con respiro */
  .card .btn + .btn{ margin-top: 8px; }
}



  </style>
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="content with-fixed-sidebar">
      <div class="container">
        <div class="card" style="margin-bottom:16px;">
          <h1>Tu pago está en revisión</h1>
          <p>Hemos recibido tu comprobante. Un agente validará tu pago y tu cuenta será activada automáticamente.</p>
          <div style="margin-top:10px;">
            <span class="badge" title="Estado del usuario">Estado: pendiente de confirmación</span>
          </div>
        </div>

        <div class="grid">
          <div class="card">
            <h2 style="margin:0 0 10px; font-size:18px; font-weight:900;">Resumen de tu solicitud</h2>
            <?php if ($pr): ?>
              <div class="row">
                <div class="kvs">
                  <div class="kv"><div class="k">Método:</div><div class="v"><?= htmlspecialchars(strtoupper($pr['method'])) ?></div></div>
                  <div class="kv"><div class="k">Monto:</div><div class="v">$<?= number_format((float)$pr['amount_usd'], 2) ?> (<?= htmlspecialchars($pr['currency']) ?>)</div></div>
                  <div class="kv"><div class="k">Enviado:</div><div class="v"><?= htmlspecialchars($pr['created_at']) ?></div></div>
                  <div class="kv"><div class="k">Estado interno:</div><div class="v"><?= htmlspecialchars($pr['status']) ?></div></div>
                  <?php if (!empty($pr['notes'])): ?>
                    <div class="kv"><div class="k">Notas:</div><div class="v"><?= htmlspecialchars($pr['notes']) ?></div></div>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="receipt-preview">
                    <?php if (!empty($pr['receipt_path'])): ?>
                      <a href="<?= htmlspecialchars($pr['receipt_path']) ?>" target="_blank" rel="noopener noreferrer">Ver comprobante</a>
                    <?php else: ?>
                      <span class="muted">No se adjuntó archivo</span>
                    <?php endif; ?>
                  </div>
                  <div class="muted" style="margin-top:8px;">Si el comprobante no es correcto, podrás enviar uno nuevo desde el soporte.</div>
                </div>
              </div>
            <?php else: ?>
              <p class="muted">No encontramos una solicitud reciente. Si crees que es un error, contáctanos.</p>
            <?php endif; ?>
          </div>

          <div class="card">
            <h2 style="margin:0 0 10px; font-size:18px; font-weight:900;">¿Qué sigue?</h2>
            <ul class="tips">
              <li>Un agente verificará tu pago en breve.</li>
              <li>Cuando se confirme, tu cuenta se activará automáticamente y podrás volver a utilizar las funciones Premium.</li>
              <li>Si tu correo no estaba en la nota de pago, puede demorar más la validación.</li>
            </ul>
            <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-top:12px;">
              <button id="btn-refresh" class="btn secondary" type="button">Actualizar estado</button>
              <a class="btn" href="logout.php">Cerrar sesión</a>
            </div>
            <p class="muted" style="margin-top:8px;">La página se refrescará automáticamente cada 60 segundos.</p>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    // Botón manual de refresco
    document.getElementById('btn-refresh')?.addEventListener('click', () => {
      window.location.reload();
    });

    // Auto-refresh cada 60s
    setInterval(() => {
      window.location.reload();
    }, 60000);
  </script>
</body>
</html>

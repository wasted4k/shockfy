<?php
// --- INICIAR SESIÓN ANTES DE CUALQUIER INCLUDE QUE LEA $_SESSION ---
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/auth.php';


$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

// ===== CSRF =====
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$__CSRF = $_SESSION['csrf'];

// ===== Cargar datos mínimos del usuario =====
$stmt = $pdo->prepare("SELECT id, full_name, username, email, currency_pref, plan, trial_started_at, trial_ends_at, trial_cancelled_at, account_state FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];


$currentPlan  = $user['plan'] ?: null;

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$trialEndsAt = $user['trial_ends_at'] ?? null;
$trialCancelledAt = $user['trial_cancelled_at'] ?? null;
$trialActive = false; $trialDaysLeft = null;
if ($trialEndsAt && !$trialCancelledAt) {
  try { 
    $trialEnd = new DateTimeImmutable($trialEndsAt, new DateTimeZone('UTC'));
    if ($now < $trialEnd) { 
      $trialActive = true; 
      $diff = $now->diff($trialEnd); 
      $trialDaysLeft = max(0, (int)$diff->format('%a')); 
    }
  } catch (Throwable $e) {}
}
function display_plan_name(?string $code): string { 
  if (!$code) return '—'; 
  $map=['starter'=>'Premium','free'=>'Free']; 
  return $map[strtolower($code)]??strtoupper($code); 
}

// ===== Parámetros de UI =====
$plan = $_GET['plan'] ?? $_POST['plan'] ?? 'starter';
$amountUSD = 4.99;
$description = 'ShockFy Premium';
$next = $_GET['next'] ?? $_POST['next'] ?? '';

// Link de suscripción PayPal (directo, el que nos diste)
$PAYPAL_SUBSCRIBE_URL = 'https://www.paypal.com/webapps/billing/plans/subscribe?plan_id=P-8D38183960655060NNDNXHZA';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Otros métodos de pago</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
  <!-- CSRF meta para leerlo desde JS sin alterar tu UI -->
  <meta name="csrf-token" content="<?= htmlspecialchars($__CSRF) ?>">
  <style>
    :root{
      --sidebar-w:260px; --bg:#f5f7fb; --card:#ffffff; --text:#0f172a; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb; --shadow:0 16px 32px rgba(2,6,23,.08); --radius:16px;
      --bn-yellow:#FCD34D; --bn-yellow-strong:#F59E0B; --bn-text:#111827;
      --pp-blue:#2563EB; --pp-blue-strong:#1D4ED8;
      --success:#16a34a; --success-strong:#15803d; --success-shadow: 0 8px 20px rgba(22,163,74,.25);
    }
    body{ margin:0; background:var(--bg); color:var(--text); font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
    .app-shell{ display:flex; min-height:100vh; transition:filter .2s ease; }
    .content{ flex:1; padding:28px 24px 48px; }
    .with-fixed-sidebar{ margin-left:var(--sidebar-w); }
    .container{ max-width:1100px; margin:0 auto; display:flex; flex-direction:column; align-items:center; text-align:center; }
    .header-wrap{ text-align:center; margin:0 auto 18px; }
    .page-title{ font-size:30px; font-weight:800; margin:0; }
    .page-sub{ color:var(--muted); margin-top:6px; font-size:13px; }
    .grid{ display:grid; gap:18px; grid-template-columns:1fr; width:100%; }
    .card{ background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:22px; }
    .btn{ display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:12px 18px; border-radius:12px; border:1px solid rgba(0,0,0,.04); background:#111827; color:#fff; font-weight:800; text-decoration:none; cursor:pointer; transition:all .2s ease; }
    .btn.outline{ background:#fff; color:#111827; border:1px solid var(--border); }
    .muted{ color:var(--muted); font-size:12px; }
    .row{ display:grid; grid-template-columns:1fr; gap:18px; }
    @media (min-width:901px){ .row{ grid-template-columns:1.2fr .8fr; } }

    .opt-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
    .opt{ padding:16px; border-radius:12px; border:1px solid var(--border); background:#fff; box-shadow:0 6px 16px rgba(2,6,23,.06); cursor:pointer; display:flex; align-items:center; justify-content:center; gap:10px; font-weight:800; color:#111827; transition:all .2s ease; }
    .opt img.icon{ width:20px; height:20px; display:inline-block; object-fit:contain; transition:filter .2s ease; }

    /* Hover Binance */
    .opt[data-method="binance"]:hover, .opt[data-method="binance"]:focus-visible{
      background:var(--bn-yellow); border-color:var(--bn-yellow-strong); box-shadow:0 8px 20px rgba(245,158,11,.25); outline:none;
    }
    .opt[data-method="binance"]:hover .icon, .opt[data-method="binance"]:focus-visible .icon{ filter:brightness(0) invert(1); }

    /* Hover PayPal */
    .opt[data-method="paypal"]:hover, .opt[data-method="paypal"]:focus-visible{
      background:var(--pp-blue); border-color:var(--pp-blue-strong); box-shadow:0 8px 20px rgba(29,78,216,.25); color:#fff; outline:none;
    }
    .opt[data-method="paypal"]:hover .icon, .opt[data-method="paypal"]:focus-visible .icon{ filter:brightness(0) invert(1); }

    #methodDetail{ margin-top:14px; display:none; text-align:center; }
    #methodDetail .btn{ margin-top:10px; }

    /* Botones de pago del panel */
    #methodDetail .btn.pay:hover, #methodDetail .btn.pay:focus-visible{
      background:var(--bn-yellow); border-color:var(--bn-yellow-strong); color:var(--bn-text); box-shadow:0 8px 20px rgba(245,158,11,.25); outline:none;
    }
    #methodDetail .btn.pay.pay--paypal:hover, #methodDetail .btn.pay.pay--paypal:focus-visible{
      background:var(--pp-blue); border-color:var(--pp-blue-strong); color:#fff; box-shadow:0 8px 20px rgba(29,78,216,.25); outline:none;
    }

    /* utilidades panel Binance */
    .stack { display:flex; flex-direction:column; align-items:center; gap:12px; }
    .stack-lg { gap:16px; }
    .hr { width:100%; height:1px; background:var(--border); margin:8px 0; }
    .qr-box { display:flex; justify-content:center; }
    .qr-img { width:220px; max-width:80vw; border-radius:12px; border:1px solid var(--border); box-shadow:0 8px 20px rgba(2,6,23,.08); background:#fff; }
    .note { font-size:13px; color:var(--text); background:#fff7ed; border:1px solid #fed7aa; padding:10px 12px; border-radius:10px; }
    .small { font-size:12px; color:var(--muted); }
    .btn.secondary { background:#fff; color:#111827; border:1px solid var(--border); }
    .btn.disabled, .btn[disabled] { opacity:.6; cursor:not-allowed; }
    .checkbox-row { display:flex; align-items:flex-start; gap:10px; text-align:left; }

    /* Modal instrucciones */
    body.modal-open { overflow:hidden; }
    body.modal-open .app-shell { filter: blur(6px); }
    .modal-overlay{ position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; z-index: 9999; }
    .modal{ background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:0 20px 60px rgba(2,6,23,.25); width:min(92vw, 980px); padding:16px; }
    .modal-header{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
    .modal-title{ font-size:18px; font-weight:900; margin:0; }
    .modal-close{ background:#fff; border:1px solid var(--border); border-radius:10px; padding:8px 12px; cursor:pointer; font-weight:800; }
    .video-wrap{ position:relative; width:100%; padding-top:56.25%; border-radius:12px; overflow:hidden; background:#000; }
    .video-wrap iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; }

    /* Botón "Instrucciones" en verde */
    #mdBody #bn-instrucciones{
      background: var(--success);
      border-color: var(--success-strong);
      color:#fff;
    }
    #mdBody #bn-instrucciones:hover,
    #mdBody #bn-instrucciones:focus-visible{
      background: var(--success-strong);
      border-color: var(--success-strong);
      box-shadow: var(--success-shadow);
      outline: none;
    }
    .btn.success{
      background: var(--success);
      border-color: var(--success-strong);
      color:#fff;
    }
    .btn.success:hover,
    .btn.success:focus-visible{
      background: var(--success-strong);
      border-color: var(--success-strong);
      box-shadow: var(--success-shadow);
      outline: none;
    }

/* ====== Otros métodos de pago: responsive móvil ====== */

/* 0) Lista utilitaria que usas en "Estado de cuenta" */
.ul{ list-style: disc; padding-left: 20px; margin: 12px 0 0; text-align: left; }
.ul li{ margin: 6px 0; }

/* 1) Quitar empuje de sidebar en móvil y evitar scroll lateral */
@media (max-width:1024px){
  .content.with-fixed-sidebar{ margin-left:0 !important; }
  .app-shell{ overflow-x:hidden; }
}

/* 2) Contenedor, títulos y cards más compactos */
@media (max-width:700px){
  .content{ padding:20px 14px 56px; }
  .container{ max-width:100%; margin:0 auto; }
  .header-wrap{ margin-bottom:12px; }
  .page-title{ font-size:22px; }
  .page-sub{ font-size:12px; }

  .card{ padding:16px; border-radius:14px; }
}

/* 3) Grid de opciones: 1 columna y targets grandes */
@media (max-width:700px){
  .opt-grid{ grid-template-columns: 1fr !important; gap: 10px; }
  .opt{ padding:12px; font-size:14px; }
  .opt img.icon{ width:18px; height:18px; }
}

/* 4) Panel/detalle: botones full-width, checkboxes alineados, QR fluido */
@media (max-width:700px){
  #methodDetail .btn{ width:100%; text-align:center; }

  .checkbox-row{ align-items: flex-start; gap: 10px; }
  .checkbox-row input{ margin-top: 3px; }

  .qr-img{ width: 100%; max-width: 92vw; height: auto; }
}

/* 5) Modal en móvil: ancho fluido y tipografías */
@media (max-width:700px){
  .modal{ width: min(96vw, 640px) !important; padding: 12px !important; }
  .modal-title{ font-size:16px; }
}
@media (max-width:380px){
  .page-title{ font-size:20px; }
  .opt{ font-size:13px; }
  .modal{ width: 96vw !important; }
}


  </style>
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="content with-fixed-sidebar">
      <div class="container">
        <div class="header-wrap">
          <h1 class="page-title">Otros métodos de pago</h1>
          <div class="page-sub">Activa <strong>Premium</strong> con el método que prefieras.</div>
        </div>

        <div class="grid">
          <div class="card">
            <div class="row">
              <div>
                <h3 style="margin:0 0 6px; font-size:20px; font-weight:800;">Plan seleccionado</h3>
                <h5> Plan Premium</h5>
                <div style="font-size:26px; font-weight:900; margin:10px 0;">$<?= number_format($amountUSD,2) ?>/mes</div>

                <!-- Opciones -->
                <div class="opt-grid" role="group" aria-label="Opciones de pago">
                  <button type="button" class="opt" data-method="binance" aria-label="Binance">
                    <img src="assets/img/binance-logo.svg" alt="" class="icon" aria-hidden="true"> Binance
                  </button>
                  <button type="button" class="opt" data-method="paypal" aria-label="PayPal">
                    <img src="assets/img/paypal-logo.svg" alt="" class="icon" aria-hidden="true"> PayPal
                  </button>
                  <button type="button" class="opt" data-method="soles" aria-label="Soles (Perú)">
                    <img src="assets/img/coin.png" alt="" class="icon" aria-hidden="true"> Soles
                  </button>
                  <button type="button" class="opt" data-method="bolivares" aria-label="Bolívares (Venezuela)">
                    <img src="assets/img/coin.png" alt="" class="icon" aria-hidden="true"> Bolívares
                  </button>
                </div>

                <!-- Panel de detalle -->
                <div id="methodDetail" class="card" aria-live="polite">
                  <h2 id="mdTitle" style="margin:6px 0 4px; font-size:22px; font-weight:900;"></h2>
                  <div id="mdSubtitle" style="font-size:18px; font-weight:800; margin-bottom:6px;"></div>
                  <div id="mdBody" class="muted"></div>
                </div>

                <script>
                  (function(){
                    const grid = document.querySelector('.opt-grid');
                    const detail = document.getElementById('methodDetail');
                    const title = document.getElementById('mdTitle');
                    const subtitle = document.getElementById('mdSubtitle');
                    const body = document.getElementById('mdBody');
                    const price = '$<?= number_format($amountUSD,2) ?>';
                    const PAYPAL_URL = '<?= $PAYPAL_SUBSCRIBE_URL ?>';

                    function setActive(btn){
                      grid.querySelectorAll('.opt').forEach(b=> b.style.outline = '');
                      btn.style.outline = '3px solid #93c5fd';
                    }

                    // Modal video
                    function openVideoModal(src, titleText = 'Instrucciones'){
                      const overlay = document.createElement('div');
                      overlay.className = 'modal-overlay';
                      overlay.setAttribute('role', 'dialog');
                      overlay.setAttribute('aria-modal', 'true');
                      overlay.innerHTML = `
                        <div class="modal" aria-label="${titleText}">
                          <div class="modal-header">
                            <h3 class="modal-title">${titleText}</h3>
                            <button class="modal-close" type="button" aria-label="Cerrar">Cerrar ✕</button>
                          </div>
                          <div class="video-wrap">
                            <iframe src="${src}" title="${titleText}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                          </div>
                        </div>
                      `;
                      document.body.appendChild(overlay);
                      const close = () => {
                        document.body.classList.remove('modal-open');
                        overlay.style.display = 'none';
                        overlay.remove();
                        document.removeEventListener('keydown', onEsc);
                      };
                      const onEsc = (e) => { if (e.key === 'Escape') close(); };
                      overlay.addEventListener('click', (e)=>{ if (e.target === overlay) close(); });
                      overlay.querySelector('.modal-close').addEventListener('click', close);
                      document.addEventListener('keydown', onEsc);
                      document.body.classList.add('modal-open');
                      overlay.style.display = 'flex';
                    }

                    function renderDetail(method){
                      detail.style.display = 'block';

                      if (method === 'binance'){
                        title.textContent = 'Pago por Binance Pay';
                        subtitle.textContent = 'Plan Premium - ' + price;

                        body.innerHTML = `
                          <div class="stack stack-lg" style="max-width:560px; margin:0 auto; text-align:center;">
                            <div>Completa tu pago por Binance Pay.</div>

                            <!-- Botones principales -->
                            <div class="stack" style="gap:10px;">
                              <a class="btn pay" href="https://s.binance.com/j8dy27VF" target="_blank" rel="noopener noreferrer">Pagar por Pay</a>
                              <button id="bn-instrucciones" type="button" class="btn secondary">Instrucciones</button>
                              <div class="small">Se abrirá en una nueva pestaña (pago) / modal (instrucciones).</div>
                            </div>

                            <div class="hr" role="separator" aria-hidden="true"></div>

                            <!-- QR centrado -->
                            <div class="qr-box">
                              <img src="assets/img/qr-binance.png" alt="QR de pago Binance" class="qr-img" />
                            </div>

                            <!-- ID -->
                            <div style="font-weight:800; font-size:18px; margin-top:4px;">ID: 319 852 186</div>

                            <!-- Nota importante -->
                            <div class="note" role="note">
                              <strong>IMPORTANTE:</strong> Coloca tu correo electrónico en la <em>nota de pago</em>. Esto será necesario para validarlo.
                            </div>

                            <!-- Adjuntar comprobante -->
                            <div class="stack" style="gap:8px;">
                              <input id="bn-file" type="file" accept="image/*,.pdf" style="display:none">
                              <button id="bn-file-btn" type="button" class="btn secondary">Adjuntar comprobante</button>
                              <div id="bn-file-name" class="small" aria-live="polite"></div>
                            </div>

                            <!-- Confirmaciones y finalizar -->
                            <div id="bn-confirm" class="stack" style="display:none;">
                              <label class="checkbox-row">
                                <input id="chk-paid" type="checkbox">
                                <span>He realizado el pago de <strong>4,99 USDT</strong>.</span>
                              </label>
                              <label class="checkbox-row">
                                <input id="chk-terms" type="checkbox">
                                <span>Acepto los <a href="terminos.php" target="_blank" rel="noopener noreferrer">términos y condiciones del servicio</a>.</span>
                              </label>
                              <button id="bn-done" type="button" class="btn disabled" disabled>Pago realizado</button>
                              <div class="small">Para habilitar el botón, adjunta el comprobante y marca ambas casillas.</div>
                            </div>
                          </div>
                        `;
                        
                        // Lógica de interacción
                        const fileInput   = body.querySelector('#bn-file');
                        const fileBtn     = body.querySelector('#bn-file-btn');
                        const fileNameEl  = body.querySelector('#bn-file-name');
                        const confirmBox  = body.querySelector('#bn-confirm');
                        const chkPaid     = body.querySelector('#chk-paid');
                        const chkTerms    = body.querySelector('#chk-terms');
                        const doneBtn     = body.querySelector('#bn-done');
                        const btnInstr    = body.querySelector('#bn-instrucciones');

                        btnInstr.addEventListener('click', () => {
                          openVideoModal('https://www.youtube.com/embed/MhyXPLt8UNM', 'Instrucciones de pago por Binance');
                        });

                        fileBtn.addEventListener('click', () => fileInput.click());

                        fileInput.addEventListener('change', () => {
                          const file = fileInput.files && fileInput.files[0];
                          if (!file) return;
                          fileNameEl.textContent = `Comprobante: ${file.name}`;
                          confirmBox.style.display = 'flex';
                          confirmBox.classList.add('stack');
                          updateDoneState();
                        });

                        function updateDoneState(){
                          const ready = chkPaid.checked && chkTerms.checked && (fileInput.files && fileInput.files.length > 0);
                          doneBtn.disabled = !ready;
                          doneBtn.classList.toggle('disabled', !ready);
                        }
                        chkPaid.addEventListener('change', updateDoneState);
                        chkTerms.addEventListener('change', updateDoneState);

                        // Handler: mismo diseño/flujo, pero añadimos CSRF + campos backend moderno
                        doneBtn.addEventListener('click', async () => {
                          if (doneBtn.disabled) return;

                          const fd = new FormData();
                          if (fileInput.files && fileInput.files[0]) {
                            fd.append('receipt', fileInput.files[0]);
                          }
                          // --- Tus campos originales (compatibilidad) ---
                          fd.append('paid', '1');
                          fd.append('terms', '1');
                          fd.append('amount', '<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?>');
                          fd.append('currency', 'USDT');
                          // --- Nuevos campos para backend robusto ---
                          const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                          fd.append('csrf', csrf);
                          fd.append('method', 'binance_manual');
                          fd.append('amount_usd', '<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?>');
                          fd.append('notes', '');

                          const prevText = doneBtn.textContent;
                          doneBtn.textContent = 'Enviando...';
                          doneBtn.disabled = true;

                          try {
                            const resp = await fetch('pago_binance.php', { method: 'POST', body: fd, headers: { 'Accept':'application/json' } });
                            const raw  = await resp.text();
                            let data;
                            try { data = JSON.parse(raw); }
                            catch { throw new Error('Respuesta no JSON:\n' + raw.slice(0, 300)); }

                            if (resp.ok && data && data.ok) {
                              window.location.href = 'waiting_confirmation.php';
                            } else {
                              alert('No pudimos registrar tu pago. Código: ' + (data?.error || 'ERR'));
                              doneBtn.textContent = prevText;
                              doneBtn.disabled = false;
                            }
                          } catch (e) {
                            alert('Error de red: ' + e.message);
                            doneBtn.textContent = prevText;
                            doneBtn.disabled = false;
                          }
                        });

                        return;
                      }

                     if (method === 'paypal'){
  title.textContent = 'Pago por PayPal (suscripción)';
  subtitle.textContent = 'Plan Premium - ' + price;

  body.innerHTML = `
    <div class="stack stack-lg" style="max-width:560px; margin:0 auto; text-align:center;">
      <div>Serás redirigido al flujo seguro de PayPal para completar tu suscripción, o puedes adjuntar un comprobante si realizaste un pago manual.</div>

      <!-- Botón PayPal oficial -->
      <a class="btn pay pay--paypal" href="${PAYPAL_URL}" target="_blank" rel="noopener noreferrer">Suscribirse con PayPal</a>
      <div class="small">Se abrirá en una nueva pestaña.</div>
      <button id="bn-instrucciones" type="button" class="btn secondary">Instrucciones</button>
                              <div class="small">Se abrirá en una nueva pestaña (pago) / modal (instrucciones).</div>
                            </div>

      <div class="hr" role="separator" aria-hidden="true"></div>

      <!-- Adjuntar comprobante (flujo manual como Binance) -->
      <div class="stack" style="gap:8px;">
        <input id="pp-file" type="file" accept="image/*,.pdf" style="display:none">
        <button id="pp-file-btn" type="button" class="btn secondary">Adjuntar comprobante</button>
        <div id="pp-file-name" class="small" aria-live="polite"></div>
      </div>

      <!-- Confirmaciones y finalizar -->
      <div id="pp-confirm" class="stack" style="display:none;">
        <label class="checkbox-row">
          <input id="pp-chk-paid" type="checkbox">
          <span>He realizado el pago de <strong>$<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?> USD</strong> por PayPal.</span>
        </label>
        <label class="checkbox-row">
          <input id="pp-chk-terms" type="checkbox">
          <span>Acepto los <a href="terminos.php" target="_blank" rel="noopener noreferrer">términos y condiciones del servicio</a>.</span>
        </label>
        <button id="pp-done" type="button" class="btn disabled" disabled>Pago realizado</button>
        <div class="small">Para habilitar el botón, adjunta el comprobante y marca ambas casillas.</div>
      </div>

      <!-- Nota -->
      <div class="note" role="note" style="margin-top:8px;">
        <strong>IMPORTANTE: </strong>Debes subir el comprobante o correo de <strong>PayPal</strong> de tu pago. Debes aceptar los términos y condiciones del servicio para continuar.
      </div>
    </div>


    
  `;

  // Lógica de interacción (idéntica a Binance, pero con IDs pp-*)
  const fileInput   = body.querySelector('#pp-file');
  const fileBtn     = body.querySelector('#pp-file-btn');
  const fileNameEl  = body.querySelector('#pp-file-name');
  const confirmBox  = body.querySelector('#pp-confirm');
  const chkPaid     = body.querySelector('#pp-chk-paid');
  const chkTerms    = body.querySelector('#pp-chk-terms');
  const doneBtn     = body.querySelector('#pp-done');
   const btnInstr    = body.querySelector('#bn-instrucciones');

                        btnInstr.addEventListener('click', () => {
                          openVideoModal('https://www.youtube.com/embed/qNx22A7pJ3c?list=PLFo_FFi1lfVi1n6UfGHBTH4-XKwNvSFYv', 'Instrucciones de pago por PayPal');
                        });

  
  
  

  fileBtn.addEventListener('click', () => fileInput.click());

  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    fileNameEl.textContent = `Comprobante: ${file.name}`;
    confirmBox.style.display = 'flex';
    confirmBox.classList.add('stack');
    updateDoneState();
  });

  function updateDoneState(){
    const ready = chkPaid.checked && chkTerms.checked && (fileInput.files && fileInput.files.length > 0);
    doneBtn.disabled = !ready;
    doneBtn.classList.toggle('disabled', !ready);
  }
  chkPaid.addEventListener('change', updateDoneState);
  chkTerms.addEventListener('change', updateDoneState);

  // Handler: mismo backend y formato; sólo cambia 'method' a 'paypal_manual'
  doneBtn.addEventListener('click', async () => {
    if (doneBtn.disabled) return;

    const fd = new FormData();
    if (fileInput.files && fileInput.files[0]) {
      fd.append('receipt', fileInput.files[0]);
    }
    // --- Compat (igual que Binance) ---
    fd.append('paid', '1');
    fd.append('terms', '1');
    fd.append('amount', '<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?>');
    fd.append('currency', 'USD');
    // --- Campos robustos ---
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fd.append('csrf', csrf);
    fd.append('method', 'paypal_manual'); // ⬅️ distinguir PayPal manual en backend
    fd.append('amount_usd', '<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?>');
    fd.append('notes', '');

    const prevText = doneBtn.textContent;
    doneBtn.textContent = 'Enviando...';
    doneBtn.disabled = true;

    try {
      const resp = await fetch('pago_binance.php', { method: 'POST', body: fd, headers: { 'Accept':'application/json' } });
      const raw  = await resp.text();
      let data;
      try { data = JSON.parse(raw); }
      catch { throw new Error('Respuesta no JSON:\\n' + raw.slice(0, 300)); }

      if (resp.ok && data && data.ok) {
        // Tu auth_check ya redirige por estado, pero navegamos directo por UX:
        window.location.href = 'waiting_confirmation.php';
      } else {
        alert('No pudimos registrar tu pago. Código: ' + (data?.error || 'ERR'));
        doneBtn.textContent = prevText;
        doneBtn.disabled = false;
      }
    } catch (e) {
      alert('Error de red: ' + e.message);
      doneBtn.textContent = prevText;
      doneBtn.disabled = false;
    }
  });

  return;
}


                      if (method === 'soles'){
  title.textContent = 'Pago manual en Soles (Perú)';
  subtitle.textContent = 'Plan Premium - ' + price;

  body.innerHTML = `
    <div style="font-size:14px; text-align:left; margin:0 auto; max-width:560px;">
      <p><strong>Transferencia manual:</strong> requiere <em>verificación humana</em>. La validación puede demorar <strong>entre 5 minutos y 2 horas</strong>.</p>
      <ul style="padding-left:18px; margin:10px 0;">
        <li><strong>Banco:</strong> BCP - Banco de Credito del Perú</li>
        <li><strong>Numero de cuenta:</strong> 570-954-8915-8047</li>
        <li><strong>Yape:</strong> 925 578 960</li>
        <li><strong>Titular:</strong> Freibel Villalobos Villalobos</li>
        <li><strong>Monto:</strong> S/. 18.00 </li>
      </ul>
      <p>Guarda tu comprobante; el equipo lo validará y activará tu plan.</p>
    </div>
    <button id="bn-instrucciones" type="button" class="btn secondary">Instrucciones</button>
                              <div class="small">Si tienes dudas, puedes ver el video de instrucciones.</div>
                            </div>



             <!-- QR del yape centrado -->
                            <div class="qr-box">
                              <img src="assets/img/qr-yape.jpeg" alt="QR de pago Binance" class="qr-img" />
                            </div>
                  

    <div class="hr" role="separator" aria-hidden="true"></div>

    <!-- Adjuntar comprobante -->
    <div class="stack" style="gap:8px; text-align:center;">
      <input id="pe-file" type="file" accept="image/*,.pdf" style="display:none">
      <button id="pe-file-btn" type="button" class="btn secondary">Adjuntar comprobante</button>
      <div id="pe-file-name" class="small" aria-live="polite"></div>
    </div>

    <!-- Confirmaciones y finalizar -->
    <div id="pe-confirm" class="stack" style="display:none; text-align:left; max-width:560px; margin:10px auto 0;">
      <label class="checkbox-row">
        <input id="pe-chk-paid" type="checkbox">
        <span>He realizado la transferencia por el equivalente a <strong>$<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?> USD</strong> en <strong>PEN</strong>.</span>
      </label>
      <label class="checkbox-row">
        <input id="pe-chk-terms" type="checkbox">
        <span>Acepto los <a href="terminos.php" target="_blank" rel="noopener noreferrer">términos y condiciones del servicio</a>.</span>
      </label>
      <button id="pe-done" type="button" class="btn disabled" disabled>Pago realizado</button>
      <div class="small">Para habilitar el botón, adjunta el comprobante y marca ambas casillas.</div>
    </div>

     <div class="note" role="note" style="margin-top:8px; text-align:center;">
      <strong>IMPORTANTE:</strong> Para acelerar el proceso de verificacion, coloca el correo con el que te has registrado en ShockFy en la descripcion del pago.
    </div>
  `;

  // Interacción
  const fileInput   = body.querySelector('#pe-file');
  const fileBtn     = body.querySelector('#pe-file-btn');
  const fileNameEl  = body.querySelector('#pe-file-name');
  const confirmBox  = body.querySelector('#pe-confirm');
  const chkPaid     = body.querySelector('#pe-chk-paid');
  const chkTerms    = body.querySelector('#pe-chk-terms');
  const doneBtn     = body.querySelector('#pe-done');
  const btnInstr    = body.querySelector('#bn-instrucciones');

                        btnInstr.addEventListener('click', () => {
                          openVideoModal('https://www.youtube.com/embed/4bGPgFMGUGw', 'Instrucciones de pago por Soles');
                        });

  fileBtn.addEventListener('click', () => fileInput.click());

  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    fileNameEl.textContent = `Comprobante: ${file.name}`;
    confirmBox.style.display = 'flex';
    confirmBox.classList.add('stack');
    updateDoneState();
  });

  function updateDoneState(){
    const ready = chkPaid.checked && chkTerms.checked && (fileInput.files && fileInput.files.length > 0);
    doneBtn.disabled = !ready;
    doneBtn.classList.toggle('disabled', !ready);
  }
  chkPaid.addEventListener('change', updateDoneState);
  chkTerms.addEventListener('change', updateDoneState);

  // Envío: igual que Binance/PayPal, pero con method = 'soles_manual'
  doneBtn.addEventListener('click', async () => {
    if (doneBtn.disabled) return;

    const fd = new FormData();
    if (fileInput.files && fileInput.files[0]) {
      fd.append('receipt', fileInput.files[0]);
    }
    // Compat
    fd.append('paid', '1');
    fd.append('terms', '1');
    fd.append('amount', '<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?>');
    fd.append('currency', 'PEN');
    // Robusto
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fd.append('csrf', csrf);
    fd.append('method', 'soles_manual'); // ← distinguir en backend
    fd.append('amount_usd', '<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?>');
    fd.append('notes', '');

    const prevText = doneBtn.textContent;
    doneBtn.textContent = 'Enviando...';
    doneBtn.disabled = true;

    try {
      const resp = await fetch('pago_binance.php', { method: 'POST', body: fd, headers: { 'Accept':'application/json' } });
      const raw  = await resp.text();
      let data;
      try { data = JSON.parse(raw); }
      catch { throw new Error('Respuesta no JSON:\n' + raw.slice(0, 300)); }

      if (resp.ok && data && data.ok) {
        window.location.href = data.redirect || 'waiting_confirmation.php';
      } else {
        alert('No pudimos registrar tu pago. Código: ' + (data?.error || 'ERR'));
        doneBtn.textContent = prevText;
        doneBtn.disabled = false;
      }
    } catch (e) {
      alert('Error de red: ' + e.message);
      doneBtn.textContent = prevText;
      doneBtn.disabled = false;
    }
  });

  return;
}


                      if (method === 'bolivares') {
  title.textContent = 'Pago en Bolívares (Venezuela) - 1,500 VES';
  subtitle.textContent = 'Plan Premium - ' + price; 

  body.innerHTML = `
    <div style="font-size:14px; text-align:left; margin:0 auto; max-width:560px;">
      <p><strong>Transferencia manual:</strong> requiere <em>verificación humana</em>. La validación puede demorar <strong>entre 5 minutos y 2 horas</strong>.</p>
      <ul style="padding-left:18px; margin:10px 0;">
        <li><strong>Banco:</strong> Banesco</li>
        <li><strong>Número de cuenta:</strong> 01340002440021044691</li>
        <li><strong>Titular:</strong> Freibel Villalobos Villalobos</li>
        <li><strong>Monto:</strong> 1,500 VES </li>
         <div class="hr" role="separator" aria-hidden="true"></div>
         <li><strong>Datos Pago Movil:</strong></li>
         <li><strong>Numero:</strong> 0426-9636029</li>
         <li><strong>Cédula:</strong> V-29977239</li>
         <li><strong>Banco:</strong> Banesco
      </ul>
      <p>Guarda tu comprobante; el equipo lo validará y activará tu plan.</p>
    </div>

    <button id="ve-instrucciones" type="button" class="btn success">Instrucciones</button>
    <div class="small">Se abrirá un modal con instrucciones.</div>

    <div class="hr" role="separator" aria-hidden="true"></div>

    <div class="stack" style="gap:8px; text-align:center;">
      <input id="ve-file" type="file" accept="image/*,.pdf" style="display:none">
      <button id="ve-file-btn" type="button" class="btn secondary">Adjuntar comprobante</button>
      <div id="ve-file-name" class="small" aria-live="polite"></div>
    </div>

    <div id="ve-confirm" class="stack" style="display:none; text-align:left; max-width:560px; margin:10px auto 0;">
      <label class="checkbox-row">
        <input id="ve-chk-paid" type="checkbox">
        <span>He realizado la transferencia por el equivalente a <strong>$<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?> USD</strong> en <strong>VES</strong>.</span>
      </label>
      <label class="checkbox-row">
        <input id="ve-chk-terms" type="checkbox">
        <span>Acepto los <a href="terminos.php" target="_blank" rel="noopener noreferrer">términos y condiciones del servicio</a>.</span>
      </label>
      <button id="ve-done" type="button" class="btn disabled" disabled>Pago realizado</button>
      <div class="small">Para habilitar el botón, adjunta el comprobante y marca ambas casillas.</div>
    </div>

    <div class="note" role="note" style="margin-top:8px; text-align:center;">
      <strong>IMPORTANTE:</strong> Para acelerar el proceso de verificacion, coloca el correo con el que te has registrado en ShockFy en la descripcion del pago.
    </div>
  `;

  // === Interacción ===
  const btnInstr   = body.querySelector('#ve-instrucciones');
  const fileInput  = body.querySelector('#ve-file');
  const fileBtn    = body.querySelector('#ve-file-btn');
  const fileNameEl = body.querySelector('#ve-file-name');
  const confirmBox = body.querySelector('#ve-confirm');
  const chkPaid    = body.querySelector('#ve-chk-paid');
  const chkTerms   = body.querySelector('#ve-chk-terms');
  const doneBtn    = body.querySelector('#ve-done');

  // Instrucciones (mismo video/modal que usas en otros)
  btnInstr.addEventListener('click', () => {
    openVideoModal(
      'https://www.youtube.com/embed/qNx22A7pJ3c?list=PLFo_FFi1lfVi1',
      'Instrucciones de pago en Bolívares'
    );
  });

  fileBtn.addEventListener('click', () => fileInput.click());

  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    fileNameEl.textContent = `Comprobante: ${file.name}`;
    confirmBox.style.display = 'flex';
    confirmBox.classList.add('stack');
    updateDoneState();
  });

  function updateDoneState() {
    const ready = chkPaid.checked && chkTerms.checked && (fileInput.files && fileInput.files.length > 0);
    doneBtn.disabled = !ready;
    doneBtn.classList.toggle('disabled', !ready);
  }
  chkPaid.addEventListener('change', updateDoneState);
  chkTerms.addEventListener('change', updateDoneState);

  // Envío — mismo endpoint que ya te funciona: pago_binance.php
  doneBtn.addEventListener('click', async () => {
    if (doneBtn.disabled) return;

    const fd = new FormData();
    if (fileInput.files && fileInput.files[0]) {
      fd.append('receipt', fileInput.files[0]);
    }
    // Compat
    fd.append('paid', '1');
    fd.append('terms', '1');
    fd.append('amount', '<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?>');
    fd.append('currency', 'VES');
    // Robusto
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fd.append('csrf', csrf);
    fd.append('method', 'bolivares_manual'); // ← distinguir en backend
    fd.append('amount_usd', '<?= htmlspecialchars(number_format($amountUSD,2,'.','')) ?>');
    fd.append('notes', '');

    const prevText = doneBtn.textContent;
    doneBtn.textContent = 'Enviando...';
    doneBtn.disabled = true;

    try {
      const resp = await fetch('pago_binance.php', {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });

      const ct = resp.headers.get('content-type') || '';
      let data = null;

      if (ct.includes('application/json')) {
        data = await resp.json();
      } else {
        const raw = await resp.text();
        throw new Error('Respuesta no JSON:\n' + raw.slice(0, 300));
      }

      if (resp.ok && data && data.ok) {
        window.location.href = data.redirect || 'waiting_confirmation.php';
      } else {
        const err = (data && (data.error || data.code)) || (resp.status + ' ' + resp.statusText) || 'ERR';
        throw new Error('No pudimos registrar tu pago. Código: ' + err);
      }
    } catch (e) {
      alert(e.message || 'Error de red');
    } finally {
      doneBtn.textContent = prevText;
      doneBtn.disabled = false;
      doneBtn.classList.add('disabled');
    }
  });

  return;
}


                      // Fallback
                      title.textContent = method.charAt(0).toUpperCase() + method.slice(1);
                      subtitle.textContent = 'Plan Premium - ' + price;
                      body.textContent = 'Próximamente';
                    }

                    grid.addEventListener('click', (e)=>{
                      const btn = e.target.closest('.opt');
                      if (!btn) return;
                      setActive(btn);
                      renderDetail(btn.dataset.method);
                    });
                  })();
                </script>


<script>
/* ===== Límite global de comprobantes: 2 MB ===== */
(function(){
  const MAX_MB = 2;
  const MAX_BYTES = MAX_MB * 1024 * 1024;

  // Mapea cada input con sus elementos de UI para limpiar
  const MAP = {
    'bn-file': { name: '#bn-file-name', confirm: '#bn-confirm' },
    'pp-file': { name: '#pp-file-name', confirm: '#pp-confirm' },
    'pe-file': { name: '#pe-file-name', confirm: '#pe-confirm' },
    've-file': { name: '#ve-file-name', confirm: '#ve-confirm' },
  };

  // Delegación: los inputs se crean dinámicamente dentro de #mdBody
  document.addEventListener('change', (e) => {
    const input = e.target;
    if (!input.matches('#bn-file, #pp-file, #pe-file, #ve-file')) return;

    const file = input.files && input.files[0];
    const ids  = MAP[input.id] || {};
    const nameEl = ids.name ? document.querySelector(ids.name) : null;
    const confirmEl = ids.confirm ? document.querySelector(ids.confirm) : null;

    if (file && file.size > MAX_BYTES) {
      alert('El comprobante no puede superar 2 MB.');
      input.value = '';                 // vacía el archivo
      if (nameEl)   nameEl.textContent = '';
      if (confirmEl){                   // oculta el panel de confirmación
        confirmEl.style.display = 'none';
        confirmEl.classList.remove('stack');
      }
    }
  }, false);

  // Defensa extra: antes de enviar (por si alguien manipula el DOM)
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('#bn-done, #pp-done, #pe-done, #ve-done');
    if (!btn) return;

    const inputId = btn.id.replace('-done','-file');
    const input   = document.getElementById(inputId);
    const file    = input && input.files && input.files[0];

    if (file && file.size > MAX_BYTES) {
      e.preventDefault();
      e.stopPropagation();
      alert('El comprobante no puede superar 2 MB.');
    }
  }, true); // capturando, para correr antes del handler original
})();
</script>


              </div>

              <!-- Estado de cuenta -->
              <div class="card" style="background:#f9fafb; border-style:dashed;">
                <div class="muted">Estado de tu cuenta</div>
                <ul class="ul" style="padding-left:18px; margin:8px 0 0; text-align:left;">
                  <li>Plan actual: <strong><?= htmlspecialchars(display_plan_name($currentPlan)) ?></strong></li>
                  <?php if ($trialActive): ?>
                    <li>Trial activo — quedan <?= (int)$trialDaysLeft ?> día(s)</li>
                  <?php else: ?>
                    <li>Trial finalizado</li>
                  <?php endif; ?>
                </ul>
                <div class="muted" style="margin-top:10px;">El precio es fijo en USD y no depende de tu moneda local.</div>
                <div style="margin-top:12px;"><a class="btn outline" href="billing.php">Volver a planes</a></div>
              </div>

            </div>
          </div>
        </div>

      </div>
    </main>
  </div>
</body>
</html>

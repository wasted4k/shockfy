<?php
// sidebar.php

// (Opcional) Protección / Bootstrap si existen en tu proyecto
if (file_exists(__DIR__ . '/auth_check.php')) {
  require_once __DIR__ . '/auth_check.php';
} elseif (file_exists(__DIR__ . '/bootstrap.php')) {
  require_once __DIR__ . '/bootstrap.php';
}

// Intentar obtener la URL del avatar desde sesión
$avatarUrl = $_SESSION['avatar_url'] ?? null;

// Si no está en sesión, intentar cargarla una vez desde BD y cachearla en sesión
if (!$avatarUrl && !empty($_SESSION['user_id'] ?? null) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('SELECT profile_photo FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['profile_photo'])) {
            $_SESSION['avatar_url'] = $row['profile_photo'];
            $avatarUrl = $_SESSION['avatar_url'];
        }
    } catch (Throwable $e) {
        // Silencio: no romper el sidebar por errores de BD
    }
}

// Helpers para nombres y preferencias
$__display_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Usuario';
$__currency = $_SESSION['currency_pref'] ?? 'S/.';
$__tz = $_SESSION['timezone'] ?? 'America/New_York';

// ---- CONFIGURACIÓN BASE DEL PROYECTO ----
// Si tu app vive en /shockfy, deja así. Si algún día la mueves a raíz, cambia a ''.
if (!defined('APP_SLUG')) {
  define('APP_SLUG', '/');
}

?>
<link rel="icon" type="image/png" href="assets/img/favicon.png">

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@200;300;400;500;600;700&display=swap');
*{ margin:0; padding:0; box-sizing:border-box; font-family:"Poppins",sans-serif; }

/* ===== Base (desktop) ===== */
.sidebar{
  position:fixed; left:0; top:0; height:100%; width:78px;
  background:#11101D; padding:6px 14px; z-index:99; transition:all .5s ease;
}
.sidebar.open{ width:250px; }

.sidebar .logo-details{ height:60px; display:flex; align-items:center; position:relative; }
.sidebar .logo-details img.logo-icon{ height:40px; width:40px; margin-right:8px; object-fit:contain; opacity:0; visibility:hidden; transition:all .4s ease; }
.sidebar.open .logo-details img.logo-icon{ opacity:1; visibility:visible; }
.sidebar .logo-details .logo_name{ color:#fff; font-size:20px; font-weight:600; opacity:0; transition:all .5s ease; }
.sidebar.open .logo-details .logo_name{ opacity:1; }

.sidebar .logo-details #btn{
  position:absolute; top:50%; right:0; transform:translateY(-50%);
  font-size:23px; cursor:pointer; transition:all .5s ease; z-index:100; pointer-events:auto; color:#fff;
}

.sidebar i{ color:#fff; height:60px; min-width:50px; font-size:28px; text-align:center; line-height:60px; }
.sidebar .nav-list{ margin-top:20px; height:100%; }
.sidebar li{ position:relative; margin:8px 0; list-style:none; }

/* Tooltips */
.sidebar li .tooltip{
  position:absolute; top:-20px; left:calc(100% + 15px); z-index:3; background:#0c95b8;
  box-shadow:0 5px 10px rgba(0,0,0,.3); padding:6px 12px; border-radius:4px; font-size:15px;
  opacity:0; white-space:nowrap; pointer-events:none; transition:0s;
}
.sidebar li:hover .tooltip{ opacity:1; pointer-events:auto; transition:all .4s ease; top:50%; transform:translateY(-50%); }
.sidebar.open li .tooltip{ display:none; }

.sidebar li a{
  display:flex; height:100%; width:100%; border-radius:12px; align-items:center; text-decoration:none; transition:all .4s ease; background:#11101D;
}
.sidebar li a:hover{ background:#FFF; }
.sidebar li a .links_name{ color:#fff; font-size:15px; white-space:nowrap; opacity:0; pointer-events:none; transition:.4s; }
.sidebar.open li a .links_name{ opacity:1; pointer-events:auto; }
.sidebar li a:hover .links_name, .sidebar li a:hover i{ transition:all .5s ease; color:#11101D; }
.sidebar li i{ height:50px; line-height:50px; font-size:18px; border-radius:12px; }

/* Footer perfil */
.sidebar li.profile{
  position:fixed; height:auto; width:78px; left:0; bottom:0; padding:10px 14px;
  background:#1d1b31; transition:all .5s ease; overflow:hidden;
}
.sidebar.open li.profile{ width:250px; }

.sidebar li .profile-details{ display:flex; align-items:center; gap:10px; min-height:48px; }
.profile-avatar{ width:44px; height:44px; border-radius:10px; background:linear-gradient(135deg,#202040,#2a2850); display:grid; place-items:center; border:1px solid rgba(255,255,255,.12); flex:0 0 44px; }
.profile-avatar svg{ width:26px; height:26px; opacity:.95; }
.profile-avatar .avatar-img{ display:block; width:44px; height:44px; border-radius:10px; object-fit:cover; border:1px solid rgba(255,255,255,.12); }
.profile-avatar .avatar-fallback{ display:none; }
.profile-avatar.avatar-fallback-active .avatar-img{ display:none; }
.profile-avatar.avatar-fallback-active .avatar-fallback{ display:block; }

.profile-texts{ display:flex; flex-direction:column; gap:4px; min-width:0; }
.profile-name{ color:#fff; font-size:14px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.profile-meta{ display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.meta-chip{ display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.08); color:#e5e7eb; border:1px solid rgba(255,255,255,.18); padding:2px 8px; border-radius:999px; font-size:11px; line-height:1.6; }
.meta-chip .dot{ width:6px; height:6px; border-radius:999px; background:#60a5fa; opacity:.9; }
.meta-chip.tz .dot{ background:#34d399; }

.sidebar:not(.open) .profile-texts{ display:none; }
.sidebar .profile #log_out{ position:absolute; top:50%; right:0; transform:translateY(-50%); background:#1d1b31; width:100%; height:60px; line-height:60px; border-radius:0; transition:all .5s ease; }
.sidebar.open .profile #log_out{ width:50px; background:none; }

/* ===== Trial overlay ===== */
.trial-overlay { position: fixed; inset: 0; z-index: 9999; display: grid; place-items: center; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(2px); }
.trial-modal { width: min(92vw, 640px); background: #fff; color: #0f172a; border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 20px 50px rgba(0,0,0,.25); padding: 22px; text-align: center; }
.trial-modal h2 { margin: 0 0 6px; font-size: 22px; font-weight: 800; }
.trial-modal p  { margin: 6px 0 16px; color: #6b7280; font-size: 14px; }
.trial-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.trial-btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 16px; border-radius: 12px; border: 1px solid transparent; background: #299EE6; color: #fff; font-weight: 700; text-decoration: none; }
.trial-btn.secondary { background: #e5e7eb; color: #0f172a; border-color: #d1d5db; }
body.trial-locked { overflow: hidden; }

/* ===== Responsive: off-canvas en móvil ===== */

/* En desktop empuja el contenido (si existe .page) */
.sidebar ~ .page{ margin-left:78px; transition:margin-left .4s ease; }
.sidebar.open ~ .page{ margin-left:250px; }

@media (max-width:1024px){
  .sidebar{
    width:250px;
    transform: translateX(-100%); /* oculto por la izquierda */
    padding:10px 14px;
  }
  .sidebar.open{ transform: translateX(0); }

  /* En móvil NO empujamos el contenido */
  .sidebar ~ .page, .sidebar.open ~ .page{ margin-left:0; }

  .sidebar li .tooltip{ display:none !important; }
}

/* Overlay táctil (solo visible en móvil cuando la barra está abierta) */
.sidebar-overlay{
  position: fixed; inset: 0; background: rgba(15,23,42,.35);
  backdrop-filter: blur(2px); z-index: 98; opacity:0; pointer-events:none; transition: opacity .2s ease;
}
@media (max-width:1024px){
  .sidebar.open + .sidebar-overlay{ opacity:1; pointer-events:auto; }
}

/* Botón hamburguesa flotante para abrir/cerrar en móvil */
.sidebar-mobile-toggle{
  position: fixed; top: 12px; left: 12px;
  display: none; align-items: center; justify-content: center;
  gap: 6px; padding: 10px; border-radius: 12px;
  background: rgba(17,16,29,.9); color: #fff;
  border: 1px solid rgba(255,255,255,.15);
  box-shadow: 0 10px 24px rgba(0,0,0,.25);
  z-index: 101; cursor: pointer;
  -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
}
.sidebar-mobile-toggle i{ font-size: 22px; line-height: 1; }
@media (max-width:1024px){
  .sidebar-mobile-toggle{ display: inline-flex; }
}

/* ===== FAB Soporte (bottom-right) ===== */
.support-fab{
  position: fixed; right: 16px; bottom: 16px; z-index: 120;
  display: inline-flex; align-items: center; justify-content: center;
  width: 56px; height: 56px; border-radius: 16px;
  background: linear-gradient(180deg,#1999bd 0%, #127a95 100%);
  border: 1px solid rgba(255,255,255,.22);
  color: #fff; box-shadow: 0 12px 28px rgba(2,6,23,.35);
  cursor: pointer;
}
.support-fab i{ font-size: 24px; line-height: 1; }
.support-fab .label{ display:none; margin-left:8px; font-weight:600; }
@media (min-width:768px){
  .support-fab{ width:auto; padding:0 14px; gap:8px; }
  .support-fab .label{ display:inline; }
}

/* Overlay del chat */
.support-overlay{
  position: fixed; inset: 0; background: rgba(2,6,23,.45);
  backdrop-filter: blur(2px); z-index: 119;
  opacity: 0; visibility: hidden; transition: opacity .2s ease, visibility .2s ease;
}
.support-overlay.show{ opacity:1; visibility:visible; }

/* Ventana del chat */
.support-chat{
  position: fixed; right: 12px; bottom: 84px; z-index: 121;
  width: min(92vw, 360px); max-height: min(72vh, 640px);
  display: grid; grid-template-rows: auto 1fr auto; gap: 0;
  background: #11101D; color: #fff; border: 1px solid rgba(255,255,255,.15);
  border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,.35);
  transform: translateY(8px); opacity: 0; visibility: hidden;
  transition: transform .18s ease, opacity .18s ease, visibility .18s ease;
}
.support-chat.open{ transform: translateY(0); opacity: 1; visibility: visible; }

.support-chat .chat-header{
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding:12px 14px; border-bottom: 1px solid rgba(255,255,255,.12);
}
.support-chat .chat-title{ font-weight: 700; font-size: 15px; }
.support-chat .chat-actions{ display:flex; gap:6px; }
.support-chat .icon-btn{
  width:34px; height:34px; display:grid; place-items:center;
  border-radius:10px; border:1px solid rgba(255,255,255,.18);
  background: rgba(255,255,255,.08); color:#fff; cursor:pointer;
}

.support-chat .chat-body{
  padding:10px 12px; overflow:auto; display:flex; flex-direction:column; gap:10px;
}
.support-msg{
  display:grid; gap:6px; max-width:90%;
  background: rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.14);
  color:#e5e7eb; padding:8px 10px; border-radius:12px;
}
.support-msg.me{ margin-left:auto; background: rgba(32, 181, 151, .18); border-color: rgba(32,181,151,.35); }

.support-chat .row-tools{
  display:flex; align-items:center; justify-content:space-between;
  padding:8px 10px; gap:8px; border-top: 1px solid rgba(255,255,255,.12);
}
.support-chat .chat-input{
  display:grid; grid-template-columns: 1fr auto; gap:8px;
  padding:10px; border-top: 1px solid rgba(255,255,255,.12);
}
.support-chat textarea{
  width:100%; min-height:44px; max-height:120px; resize:vertical;
  padding:.6rem .7rem; border-radius:12px; border:1px solid rgba(255,255,255,.18);
  background: rgba(255,255,255,.04); color:#fff; outline:none;
}
.support-chat .send-btn{
  display:inline-flex; align-items:center; justify-content:center; gap:6px;
  padding:0 14px; border-radius:12px; border:1px solid rgba(255,255,255,.22);
  background: linear-gradient(180deg,#22c55e .0%, #16a34a 100%); color:#fff; font-weight:700;
}
.support-chat .row-tools input[type="file"]{ display:none; }
.support-chat .attach-label{
  display:inline-flex; align-items:center; gap:6px; padding:6px 10px;
  border-radius:10px; border:1px solid rgba(255,255,255,.18);
  background: rgba(255,255,255,.06); color:#e5e7eb; cursor:pointer;
}

/* Evitar que el FAB tape el footer del sidebar móvil */
@media (max-width:1024px){
  .support-chat{ bottom: 92px; right: 10px; }
  .support-fab{ right: 12px; bottom: 12px; }
}
</style>

<link href="https://unpkg.com/boxicons@2.0.7/css/boxicons.min.css" rel="stylesheet">

<!-- Sidebar -->
<div class="sidebar">
  <div class="logo-details">
    <img src="assets/img/icono_menu.png" alt="Logo" class="logo-icon">
    <div class="logo_name">ShockFy</div>
    <i class="bx bx-menu" id="btn" aria-label="Abrir/cerrar menú" role="button" tabindex="0"></i>
  </div>

  <ul class="nav-list">
    <li>
      <a href="index.php">
        <i class="bx bx-grid-alt"></i>
        <span class="links_name">Principal</span>
      </a>
      <span class="tooltip">Principal</span>
    </li>
    <li>
      <a href="add_product.php">
        <i class="bx bx-plus-circle"></i>
        <span class="links_name">Agregar producto</span>
      </a>
      <span class="tooltip">Agregar producto</span>
    </li>
    <li>
      <a href="products.php">
        <i class="bx bx-package"></i>
        <span class="links_name">Productos</span>
      </a>
      <span class="tooltip">Productos</span>
    </li>
    <li>
      <a href="categories.php">
        <i class="bx bx-category"></i>
        <span class="links_name">Categorías</span>
      </a>
      <span class="tooltip">Categorías</span>
    </li>
    <li>
      <a href="sell.php">
        <i class="bx bx-cart-alt"></i>
        <span class="links_name">Registrar venta</span>
      </a>
      <span class="tooltip">Registrar venta</span>
    </li>
    <li>
      <a href="sales_report.php">
        <i class="bx bx-bar-chart"></i>
        <span class="links_name">Estadísticas</span>
      </a>
      <span class="tooltip">Estadísticas</span>
    </li>
    <li>
      <a href="ajustes.php">
        <i class="bx bx-cog"></i>
        <span class="links_name">Ajustes</span>
      </a>
      <span class="tooltip">Ajustes</span>
    </li>

    <!-- Footer usuario -->
    <li class="profile">
      <div class="profile-details">
        <div class="profile-avatar" aria-hidden="true">
          <?php if (!empty($avatarUrl)): ?>
            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Foto de perfil" class="avatar-img" referrerpolicy="no-referrer">
            <svg class="avatar-fallback" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="8" r="4.5" stroke="#9fb6ff" stroke-width="1.5"/>
              <path d="M4.5 19.2c1.8-3.2 5-5.2 7.5-5.2s5.7 2 7.5 5.2" stroke="#9fb6ff" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
          <?php else: ?>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="8" r="4.5" stroke="#9fb6ff" stroke-width="1.5"/>
              <path d="M4.5 19.2c1.8-3.2 5-5.2 7.5-5.2s5.7 2 7.5 5.2" stroke="#9fb6ff" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
          <?php endif; ?>
        </div>
        <div class="profile-texts">
          <div class="profile-name" title="<?= htmlspecialchars($__display_name) ?>"><?= htmlspecialchars($__display_name) ?></div>
          <div class="profile-meta">
            <span class="meta-chip cur" title="Moneda"><span class="dot"></span><?= htmlspecialchars($__currency) ?></span>
            <span class="meta-chip tz" title="Zona horaria"><span class="dot"></span><?= htmlspecialchars($__tz) ?></span>
          </div>
        </div>
      </div>
      <a href="logout.php" id="log_out" title="Cerrar sesión"><i class="bx bx-log-out"></i></a>
    </li>
  </ul>
</div>

<!-- Overlay (móvil) -->
<div class="sidebar-overlay" aria-hidden="true"></div>

<!-- Botón hamburguesa flotante (móvil) -->
<button class="sidebar-mobile-toggle" id="sidebarMobileToggle" aria-label="Abrir menú"><i class="bx bx-menu"></i></button>

<?php
$trialOverlay = (defined('TRIAL_EXPIRED_OVERLAY') && TRIAL_EXPIRED_OVERLAY);
?>
<?php if ($trialOverlay): ?>
  <div class="trial-overlay" role="dialog" aria-modal="true">
    <div class="trial-modal">
      <h2>Tu prueba gratuita ha finalizado</h2>
      <p>Para seguir usando ShockFy, activa un plan. Mientras no tengas un plan activo, tu acceso a inventario y demás módulos permanecerá bloqueado.</p>
      <div class="trial-actions">
        <a class="trial-btn" href="billing.php">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z" stroke="currentColor" stroke-width="2" /></svg>
          Ir a planes y activar
        </a>
        <a class="trial-btn secondary" href="logout.php">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 17l5-5-5-5M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Cerrar sesión
        </a>
      </div>
    </div>
  </div>
  <script> document.body.classList.add('trial-locked'); </script>
<?php endif; ?>

<!-- ===== FAB Soporte + Chat ===== -->
<button class="support-fab" id="supportFab" aria-label="Abrir chat de soporte" title="Soporte">
  <i class="bx bx-message-dots" aria-hidden="true"></i>
  <span class="label">Soporte</span>
</button>

<div class="support-overlay" id="supportOverlay" aria-hidden="true"></div>

<section class="support-chat" id="supportChat" role="dialog" aria-modal="true" aria-labelledby="supportChatTitle">
  <header class="chat-header">
    <div class="chat-title" id="supportChatTitle">Soporte en línea</div>
    <div class="chat-actions">
      <button class="icon-btn" id="supportMin" title="Minimizar" aria-label="Minimizar chat"><i class="bx bx-chevron-down"></i></button>
      <button class="icon-btn" id="supportClose" title="Cerrar" aria-label="Cerrar chat"><i class="bx bx-x"></i></button>
    </div>
  </header>

  <div class="chat-body" id="supportBody" aria-live="polite">
    <div class="support-msg">
      <div><strong>Agente</strong></div>
      <div>¡Hola <?= htmlspecialchars($__display_name) ?>! ¿En qué puede ayudar hoy?</div>
    </div>
  </div>

  <div class="row-tools">
    <label class="attach-label" for="supportFile">
      <i class="bx bx-paperclip" aria-hidden="true"></i> Adjuntar
    </label>
    <input type="file" id="supportFile" data-max-mb="2" accept=".png,.jpg,.jpeg,.pdf">
    <small style="color:#9ca3af">Máx. 2&nbsp;MB</small>
  </div>

  <div class="chat-input">
    <textarea id="supportText" placeholder="Escribe tu mensaje…"></textarea>
    <button class="send-btn" id="supportSend">
      <i class="bx bx-send" aria-hidden="true"></i> Enviar
    </button>
  </div>
</section>

<script>
// Configurar URL absoluta del endpoint del chat (sin fallbacks)
(function(){
  var slug = <?php echo json_encode(APP_SLUG); ?>;      // "/shockfy" o ""
  var base = slug ? slug.replace(/\/$/, '') : '';       // "/shockfy" -> "/shockfy"
  window.API_SUPPORT_URL = window.location.origin + '/api/support_chat.php';
  // Ej.: "http://localhost/shockfy/api/support_chat.php"
})();
</script>


<!-- Chat -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  const $ = (s, r=document)=>r.querySelector(s);

  const body      = document.body;
  const fab       = $('#supportFab');
  const chat      = $('#supportChat');
  const overlay   = $('#supportOverlay');
  const btnClose  = $('#supportClose');
  const btnMin    = $('#supportMin');
  const bodyMsgs  = $('#supportBody');
  const inputOrig = $('#supportText');
  const file      = $('#supportFile');

  const KEY = 'supportChatOpen';
  const API_SUPPORT_URL = window.API_SUPPORT_URL;

  // --- Estado ---
  const state = {
    lastTs: null,
    pollTimer: null,
    POLL_MS: 5000,
    isOpen: false,
    ticketId: null
  };

  // ---------- helpers ----------
  function replaceNodeWithClone(el){
    if (!el) return el;
    const clone = el.cloneNode(true);
    el.replaceWith(clone);
    return clone;
  }
  const sendBtn   = replaceNodeWithClone(document.getElementById('supportSend'));
  const input     = replaceNodeWithClone(inputOrig);

  // ✅ FIX: esc ahora está bien cerrada (antes faltaba paréntesis/cierre)
  function esc(str){
    return (str||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  }

  function addMsg({who, text, att, ts}){
    const wrap = document.createElement('div');
    wrap.className = 'support-msg' + (who==='me' ? ' me' : '');
    wrap.innerHTML = `
      <div><strong>${who==='me'?'Tú':'Agente'}</strong> ${ts ? `<small style="opacity:.7">· ${ts}</small>`:''}</div>
      <div>${esc(text)}</div>
      ${att ? `<div style="margin-top:6px"><a href="${att}" target="_blank" rel="noopener">Ver adjunto</a></div>` : ''}
    `;
    bodyMsgs.appendChild(wrap);
    bodyMsgs.scrollTop = bodyMsgs.scrollHeight + 120;
  }

  function clearMsgs(){
    if (!bodyMsgs) return;
    bodyMsgs.innerHTML = `
      <div class="support-msg">
        <div><strong>Agente</strong></div>
        <div>¡Hola! ¿En qué podemos ayudar?</div>
      </div>`;
    // contenedor para el widget de rating (si aplica)
    const ratingWrap = document.createElement('div');
    ratingWrap.id = 'supportRatingWrap';
    ratingWrap.style.margin = '12px 0';
    bodyMsgs.appendChild(ratingWrap);
  }

  const toDate = (iso) => (iso ? new Date(iso.replace(' ','T') + 'Z') : null);

  async function parseJsonResponse(res){
    const raw = await res.text();
    let data = null;
    try { data = raw ? JSON.parse(raw) : null; }
    catch (e) {
      console.error('Respuesta no JSON:', (raw||'').slice(0,400));
      throw new Error('Respuesta no válida del servidor');
    }
    if (!res.ok) {
      const msg = data?.error || `HTTP ${res.status}`;
      throw new Error(msg);
    }
    return data;
  }

  // ---------- Rating widget ----------
  function renderRatingWidgetIfNeeded(messages){
    const wrap = document.getElementById('supportRatingWrap');
    if (!wrap || !state.ticketId) return;

    const key = 'ticketRated_' + state.ticketId;
    if (localStorage.getItem(key) === '1') { wrap.innerHTML=''; return; }

    const hasReq = Array.isArray(messages) && messages.some(m => (m.message||'').includes('[RATING_REQUEST]'));
    if (!hasReq) { wrap.innerHTML=''; return; }

    wrap.innerHTML = `
      <div style="padding:10px; border:1px solid #1f2937; border-radius:10px; background:#0b1220">
        <div style="margin-bottom:6px">¿Cómo calificarías la atención?</div>
        <div id="supportStars" style="display:flex; gap:8px; font-size:20px; cursor:pointer;">
          <span data-v="1">★</span><span data-v="2">★</span><span data-v="3">★</span><span data-v="4">★</span><span data-v="5">★</span>
        </div>
        <div id="supportStarsMsg" style="margin-top:6px; opacity:.8;"></div>
      </div>
    `;
    const stars = $('#supportStars');
    const starsMsg = $('#supportStarsMsg');
    if (stars){
      stars.addEventListener('click', async (e)=>{
        const el = e.target.closest('span[data-v]'); if (!el) return;
        const v = parseInt(el.getAttribute('data-v')||'0',10); if (!(v>=1 && v<=5)) return;
        try{
          const form = new FormData();
          form.append('ticket_id', String(state.ticketId));
          form.append('rating', String(v));
          const res = await fetch('/api/support_rating.php', { method:'POST', body: form, credentials:'same-origin' });
          const data = await res.json();
          if (!data || !data.ok) {
            starsMsg.textContent = (data && data.error) ? data.error : 'No se pudo registrar tu calificación';
          } else {
            starsMsg.textContent = '¡Gracias por tu calificación!';
            try{ localStorage.setItem(key, '1'); }catch(e){}
            setTimeout(()=>{ wrap.innerHTML=''; }, 1200);
            try { await fetchAndAppendNew(); } catch(e){}
          }
        }catch(err){ starsMsg.textContent = 'Error de red'; }
      });
    }
  }

  // ---------- Polling control ----------
  function startPolling(){
    stopPolling();
    if (!state.isOpen) return;
    if (document.hidden) return;
    state.pollTimer = setInterval(async ()=>{ try { await fetchAndAppendNew(); } catch (e) {} }, state.POLL_MS);
  }
  function stopPolling(){
    if (state.pollTimer){ clearInterval(state.pollTimer); state.pollTimer = null; }
  }

  // ---------- Abrir/cerrar chat ----------
  function openChat(){
    if (!chat || !overlay) return;
    chat.classList.add('open');
    overlay.classList.add('show');
    body.style.overflow = 'hidden';
    state.isOpen = true;
    try{ localStorage.setItem(KEY,'1'); }catch(e){}
    loadThread(true).then(()=> input?.focus());
  }
  function closeChat(){
    if (!chat || !overlay) return;
    chat.classList.remove('open');
    overlay.classList.remove('show');
    body.style.overflow = '';
    state.isOpen = false;
    try{ localStorage.setItem(KEY,'0'); }catch(e){}
    stopPolling();
  }
  function toggleChat(){ (chat && chat.classList.contains('open')) ? closeChat() : openChat(); }

  // restaurar estado
  try{ if (localStorage.getItem(KEY)==='1') { openChat(); } }catch(e){}

  fab?.addEventListener('click', toggleChat);
  btnClose?.addEventListener('click', closeChat);
  overlay?.addEventListener('click', closeChat);
  document.addEventListener('keydown', (e)=>{ if(e.key==='Escape'){ closeChat(); }});
  btnMin?.addEventListener('click', ()=>{
    if (!chat || !overlay) return;
    chat.classList.remove('open');
    overlay.classList.remove('show');
    body.style.overflow = '';
    state.isOpen = false;
    stopPolling();
    try{ localStorage.setItem(KEY,'0'); }catch(e){}
  });

  document.addEventListener('visibilitychange', ()=>{ if (document.hidden) stopPolling(); else if (state.isOpen) startPolling(); });

  // ---------- Cargar hilo ----------
  async function loadThread(reset=false){
    const res = await fetch(API_SUPPORT_URL + '?action=thread', { method:'GET', headers:{'Accept':'application/json'} });
    const data = await parseJsonResponse(res);

    if (reset){
      clearMsgs();
      state.lastTs = null;
    }

    if (data && typeof data.ticket !== 'undefined') {
      state.ticketId = data.ticket;
    }

    if (data && Array.isArray(data.messages)){
      if (reset){
        for (const m of data.messages){
          addMsg({ who: m.sender === 'user' ? 'me' : 'agent', text: m.message || '', att: m.file_path || '', ts: m.created_at || '' });
        }
      }
      for (const m of data.messages){
        if (!m.created_at) continue;
        if (!state.lastTs || toDate(m.created_at) > toDate(state.lastTs)){
          state.lastTs = m.created_at;
        }
      }
      renderRatingWidgetIfNeeded(data.messages);
    }

    if (reset) startPolling();
  }

  // ---------- Obtener y anexar nuevos ----------
  async function fetchAndAppendNew(){
    const res = await fetch(API_SUPPORT_URL + '?action=thread', { method:'GET', headers:{'Accept':'application/json'} });
    const data = await parseJsonResponse(res);
    if (!data || !Array.isArray(data.messages)) return;

    if (typeof data.ticket !== 'undefined') state.ticketId = data.ticket;

    const news = [];
    for (const m of data.messages){
      if (!state.lastTs) { news.push(m); continue; }
      if (!m.created_at) continue;
      if (toDate(m.created_at) > toDate(state.lastTs)) news.push(m);
    }
    if (!news.length) return;

    news.sort((a,b)=> (toDate(a.created_at) - toDate(b.created_at)));
    for (const m of news){
      addMsg({ who: m.sender === 'user' ? 'me' : 'agent', text: m.message || '', att: m.file_path || '', ts: m.created_at || '' });
      if (!state.lastTs || toDate(m.created_at) > toDate(state.lastTs)){
        state.lastTs = m.created_at;
      }
    }
    renderRatingWidgetIfNeeded(data.messages);
  }

  // ---------- Enviar mensaje (usuario) ----------
  async function sendMessage(){
    const text = (input?.value || '').trim();
    const f = file?.files && file.files[0];
    if (!text && !f){
      window.showToast ? showToast('Escribe un mensaje o adjunta un archivo', 'warn') : alert('Escribe un mensaje o adjunta un archivo');
      return;
    }

    if (input) input.value = '';

    const form = new FormData();
    form.append('message', text);
    if (f) form.append('file', f);

    try{
      const controller = new AbortController();
      const timeoutId = setTimeout(()=>controller.abort(), 15000);
      const res  = await fetch(API_SUPPORT_URL, { method:'POST', body: form, signal: controller.signal });
      clearTimeout(timeoutId);
      await parseJsonResponse(res);

      await fetchAndAppendNew();
      window.showToast && showToast('Mensaje enviado', 'ok');
      if (file) file.value = '';
    }catch(err){
      console.error('Network/JSON error →', err);
      window.showToast ? showToast(err.message || 'Error de red al enviar', 'err') : alert(err.message || 'Error de red al enviar');
    }
  }

  // Listeners de envío y atajo
  sendBtn?.addEventListener('click', (e)=>{ e.preventDefault(); sendMessage(); });
  input?.addEventListener('keydown', (e)=>{
    if ((e.key === 'Enter' && (e.metaKey || e.ctrlKey))){ e.preventDefault(); sendMessage(); }
  });

});
</script>



<script>
/* Toggle de la barra lateral (desktop y móvil) */
(function(){
  const sidebar   = document.querySelector('.sidebar');
  const btnInside = document.getElementById('btn');                 // botón ☰ dentro del sidebar
  const overlay   = document.querySelector('.sidebar-overlay');     // overlay para móvil
  const btnFloat  = document.getElementById('sidebarMobileToggle'); // botón flotante en móvil
  const mq        = window.matchMedia('(max-width:1024px)');
  const KEY       = 'sidebarOpen';

  if (!sidebar) return;

  const isMobile = () => mq.matches;

  function persist(open){ try{ localStorage.setItem(KEY, open ? 'true' : 'false'); }catch(e){} }
  function open(){ sidebar.classList.add('open');  persist(true);  }
  function close(){ sidebar.classList.remove('open'); persist(false); }
  function toggle(){ sidebar.classList.contains('open') ? close() : open(); }

  // Estado inicial: en móvil siempre cerrada; en desktop respeta lo guardado
  function applyInitial() {
    let saved = null;
    try { saved = localStorage.getItem(KEY); } catch(e){}
    if (isMobile()) {
      close(); // forzar cerrada en móvil
    } else {
      (saved === 'true') ? open() : close();
    }
  }
  applyInitial();

  // Listeners
  btnInside?.addEventListener('click', toggle);
  btnInside?.addEventListener('keydown', (e)=>{ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); toggle(); }});
  btnFloat?.addEventListener('click', toggle);
  overlay?.addEventListener('click', (e)=>{ e.preventDefault(); close(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') close(); });

  // Si cambias entre móvil/desktop, re-aplica la regla
  const onBP = ()=>applyInitial();
  if (mq.addEventListener) mq.addEventListener('change', onBP);
  else mq.addListener(onBP); // fallback Safari viejo
})();
</script>


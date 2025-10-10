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
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
*{ margin:0; padding:0; box-sizing:border-box; font-family:"Poppins",sans-serif; }

/* ========== Tokens minimal premium ========== */
:root{
  --bg-sidebar:#11101D;
  --bg-surface:#0f0e19;        /* fondo principal uniforme (sin destellos) */
  --bg-surface-2:#131127;      /* leve elevación */
  --glass:rgba(255,255,255,.05);
  --text-strong:#f7f8fb;
  --text-muted:#c9ceda;
  --text-subtle:#9aa3b2;
  --border-weak:rgba(255,255,255,.10);
  --border-med:rgba(255,255,255,.16);
  --brand:#17a2bf;             /* acento */
  --brand-2:#12849c;
  --accent:#1fb58f;            /* acento secundario */
  --accent-2:#169a7b;

  /* FAB tokens */
  --fab-blue-1:#15132a;
  --fab-blue-2:#0F0D21;
  --fab-border:rgba(255,255,255,.18);
  --fab-hover:#1b1936;
  --fab-ring:rgba(33,150,243,.28);
  --fab-text:#ffffff;
}

/* ===== Base (sidebar existente) ===== */
.sidebar{
  position:fixed; left:0; top:0; height:100%; width:78px;
  background: var(--bg-sidebar); padding:6px 14px; z-index:99; transition:all .5s ease;
  box-shadow: inset -1px 0 0 var(--border-weak);
}
.sidebar.open{ width:250px; }
.sidebar .logo-details{ height:60px; display:flex; align-items:center; position:relative; }
.sidebar .logo-details img.logo-icon{ height:40px; width:40px; margin-right:8px; object-fit:contain; opacity:0; visibility:hidden; transition:all .4s ease; }
.sidebar.open .logo-details img.logo-icon{ opacity:1; visibility:visible; }
.sidebar .logo-details .logo_name{ color:#fff; font-size:20px; font-weight:600; opacity:0; transition:all .5s ease; }
.sidebar.open .logo-details .logo_name{ opacity:1; }
.sidebar .logo-details #btn{ position:absolute; top:50%; right:0; transform:translateY(-50%); font-size:23px; cursor:pointer; z-index:100; color:#fff; }

.sidebar i{ color:#fff; height:60px; min-width:50px; font-size:28px; text-align:center; line-height:60px; }
.sidebar .nav-list{ margin-top:20px; height:100%; }
.sidebar li{ position:relative; margin:8px 0; list-style:none; }

.sidebar li .tooltip{
  position:absolute; top:-20px; left:calc(100% + 15px); z-index:3; background:#0c95b8;
  box-shadow:0 5px 10px rgba(0,0,0,.3); padding:6px 12px; border-radius:4px; font-size:15px;
  opacity:0; white-space:nowrap; pointer-events:none; transition:0s; color:#fff;
}
.sidebar li:hover .tooltip{ opacity:1; pointer-events:auto; transition:all .4s ease; top:50%; transform:translateY(-50%); }
.sidebar.open li .tooltip{ display:none; }

.sidebar li a{
  display:flex; width:100%; border-radius:12px; align-items:center; text-decoration:none; transition:all .2s ease;
  background:var(--bg-sidebar); border:1px solid transparent;
}
.sidebar li a:hover{ background:#fff; }
.sidebar li a .links_name{ color:#fff; font-size:15px; white-space:nowrap; opacity:0; pointer-events:none; transition:.2s; }
.sidebar.open li a .links_name{ opacity:1; pointer-events:auto; }
.sidebar li a:hover .links_name, .sidebar li a:hover i{ color:#11101D; }
.sidebar li i{ height:50px; line-height:50px; font-size:18px; border-radius:12px; }

/* Footer perfil */
.sidebar li.profile{
  position:fixed; width:78px; left:0; bottom:0; padding:10px 14px;
  background:#1d1b31; transition:all .5s ease; overflow:hidden;
  box-shadow: inset 0 1px 0 var(--border-weak);
}
.sidebar.open li.profile{ width:250px; }

.sidebar li .profile-details{ display:flex; align-items:center; gap:10px; min-height:48px; }
.profile-avatar{ width:44px; height:44px; border-radius:10px; background:linear-gradient(135deg,#202040,#2a2850);
  display:grid; place-items:center; border:1px solid rgba(255,255,255,.12); flex:0 0 44px; }
.profile-avatar svg{ width:26px; height:26px; opacity:.95; }
.profile-avatar .avatar-img{ width:44px; height:44px; border-radius:10px; object-fit:cover; border:1px solid rgba(255,255,255,.12); display:block; }
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

/* ===== Layout responsive ===== */
.sidebar ~ .page{ margin-left:78px; transition:margin-left .4s ease; }
.sidebar.open ~ .page{ margin-left:250px; }

/* Sidebar móvil: scroll suave dentro del panel y color consistente */
@media (max-width:1024px){
  .sidebar{
    width:250px;
    transform: translateX(-100%);
    padding:10px 14px;
    overflow: auto;                      /* scroll interno */
    -webkit-overflow-scrolling: touch;   /* scroll suave en iOS */
    background: var(--bg-sidebar);
  }
  .sidebar.open{ transform: translateX(0); }
  .sidebar ~ .page, .sidebar.open ~ .page{ margin-left:0; }
  .sidebar li .tooltip{ display:none !important; }
}

/* Overlay táctil a tono */
.sidebar-overlay{
  position: fixed; inset: 0; background: rgba(17,16,29,.55);
  backdrop-filter: blur(3px); z-index: 98; opacity:0; pointer-events:none; transition: opacity .2s ease;
}
@media (max-width:1024px){ .sidebar.open + .sidebar-overlay{ opacity:1; pointer-events:auto; } }

/* Botón hamburguesa flotante para abrir/cerrar en móvil */
.sidebar-mobile-toggle{
  position: fixed; top: 12px; left: 12px;
  display: none; align-items: center; justify-content: center;
  gap: 6px; padding: 10px; border-radius: 14px;
  background: var(--bg-sidebar); color: #fff;
  border: 1px solid var(--border-med);
  box-shadow: 0 10px 24px rgba(0,0,0,.35);
  z-index: 101; cursor: pointer;
  -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
  transition: transform .25s ease, opacity .25s ease, box-shadow .2s ease;
}
/* clase opcional para ocultar al hacer scroll (si luego agregas JS) */
.sidebar-mobile-toggle.is-hidden{ transform: translateY(-140%); opacity: 0; pointer-events: none; }

.sidebar-mobile-toggle i{ font-size: 22px; line-height: 1; }
@media (max-width:1024px){
  .sidebar-mobile-toggle{ display: inline-flex; position: sticky; top: 12px; }
}

/* ===== FAB Soporte ===== */
.support-fab{
  position: fixed; right: 16px; bottom: 16px; z-index: 120;
  display: flex !important; align-items: center !important; justify-content: center !important;
  width: 56px !important; height: 56px !important; border-radius: 20px !important;
  background: linear-gradient(180deg, var(--fab-blue-1) 0%, var(--fab-blue-2) 100%) !important;
  border: 1px solid var(--fab-border) !important; color:#fff;
  box-shadow: 0 10px 28px rgba(0,0,0,.35) !important; cursor: pointer;
  padding: 0 !important; gap: 0 !important;
  transition: transform .12s ease, filter .18s ease, box-shadow .18s ease, background .18s ease;
}
.support-fab i{ font-size: 22px !important; line-height: 1; display: block; transform: translateY(0); }
.support-fab .label{ display:none; margin-left:8px; font-weight:600; letter-spacing:.2px; }
.support-fab:hover{
  background: linear-gradient(180deg, var(--fab-hover) 0%, var(--fab-blue-2) 100%) !important;
  filter: brightness(1.02); transform: translateY(-1px);
  box-shadow: 0 14px 32px rgba(0,0,0,.45) !important;
}
.support-fab:active{ transform: translateY(0); }
.support-fab:focus-visible{
  outline: none;
  box-shadow: 0 0 0 3px var(--fab-ring), 0 12px 28px rgba(0,0,0,.35) !important;
}
@media (min-width:768px){
  .support-fab{ height: 54px !important; border-radius: 999px !important; padding: 0 16px !important; gap: 8px !important; width: auto !important; }
  .support-fab .label{ display:inline !important; }
}
@media (max-width:1024px){
  .support-fab{ right: 12px; bottom: 12px; }
}

/* ===== Overlay del chat ===== */
.support-overlay{
  position: fixed; inset: 0; background: rgba(2,6,23,.45);
  backdrop-filter: blur(2px); z-index: 119;
  opacity: 0; visibility: hidden; transition: opacity .2s ease, visibility .2s ease;
}
.support-overlay.show{ opacity:1; visibility:visible; }

/* ===== Ventana del chat (sin destellos) ===== */
.support-chat{
  position: fixed; right: 12px; bottom: 84px; z-index: 121;
  width: min(92vw, 380px); max-height: min(72vh, 660px);
  display: grid; grid-template-rows: auto 1fr auto;
  background: var(--bg-surface);
  color: var(--text-strong);
  border: 1px solid var(--border-med);
  border-radius: 16px;
  box-shadow: 0 20px 48px rgba(0,0,0,.45);
  transform: translateY(8px); opacity: 0; visibility: hidden;
  transition: transform .18s ease, opacity .18s ease, visibility .18s ease;
  overflow: hidden;
}
.support-chat.open{ transform: translateY(0); opacity: 1; visibility: visible; }

/* Header — limpio, con línea de acento arriba */
.support-chat .chat-header{
  position: relative;
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  min-height: 52px; padding:10px 14px; box-sizing:border-box;
  background: var(--bg-surface-2);
  color: var(--text-strong);
  border-bottom: 1px solid var(--border-weak);
}
.support-chat .chat-header::before{
  content:""; position:absolute; inset:0 0 auto 0; height:2px;
  background: linear-gradient(90deg, var(--brand), var(--accent));
}
.support-chat .chat-title{ font-weight: 700; font-size: 15px; letter-spacing:.2px; }
.support-chat .chat-actions{ display:flex; align-items:center; gap:8px; margin:0; padding:0; }
.support-chat .icon-btn{
  width:36px; height:36px; display:flex; align-items:center; justify-content:center;
  border-radius:10px; border:1px solid var(--border-med);
  background: rgba(255,255,255,.04); color:#fff; cursor:pointer; transition: all .15s ease;
  line-height:1; margin:0; padding:0;
}
.support-chat .icon-btn:hover{ background: rgba(255,255,255,.08); transform: translateY(-1px); }
.support-chat .icon-btn i, .support-chat .icon-btn svg{ display:block; line-height:1; vertical-align:middle; font-size:18px; }

/* Body — fondo uniforme */
.support-chat .chat-body{
  padding:12px 12px 14px; overflow:auto; display:flex; flex-direction:column; gap:10px;
  background: var(--bg-surface);
}

/* Mensajes */
.support-msg{
  display:grid; gap:6px; max-width:90%;
  background: var(--glass);
  border:1px solid var(--border-med);
  color: var(--text-muted); padding:10px 12px; border-radius:14px;
}
.support-msg strong{ color: var(--text-strong); font-weight:600; }
.support-msg small{ color: var(--text-subtle); }
.support-msg a{ color:#8bcdf4; text-decoration:underline; text-underline-offset:2px; }
.support-msg.me{
  margin-left:auto;
  background: rgba(31,181,159,.12);
  border-color: rgba(31,181,159,.32);
  color:#eafff6;
}
.support-msg.me strong{ color:#eafff6; }

/* Row herramientas */
.support-chat .row-tools{
  display:flex; align-items:center; justify-content:space-between;
  padding:8px 10px; gap:8px; border-top: 1px solid var(--border-weak);
  background: #0f0e19;
}
.support-chat .attach-label{
  display:inline-flex; align-items:center; gap:8px; padding:8px 12px;
  border-radius:10px; border:1px solid var(--border-med);
  background: rgba(255,255,255,.04); color:var(--text-muted); cursor:pointer;
  transition: all .15s ease;
}
.support-chat .attach-label:hover{ background: rgba(255,255,255,.08); color:#fff; }
.support-chat .row-tools small{ color: var(--text-subtle); }
.support-chat .row-tools input[type="file"]{ display:none; }

/* Input + Enviar */
.support-chat .chat-input{
  display:grid; grid-template-columns: 1fr auto; gap:8px;
  padding:10px; border-top: 1px solid var(--border-weak);
  background: #0f0e19;
}
.support-chat textarea{
  width:100%; min-height:44px; max-height:120px; resize:vertical;
  padding:.7rem .85rem; border-radius:12px; border:1px solid var(--border-med);
  background: rgba(255,255,255,.04); color:#fff; outline:none;
  transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.support-chat textarea::placeholder{ color: var(--text-subtle); opacity:.95; }
.support-chat textarea:focus{
  border-color: rgba(23,162,191,.55);
  box-shadow: 0 0 0 3px rgba(23,162,191,.16);
  background: rgba(255,255,255,.06);
}

.support-chat .send-btn{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:0 16px; border-radius:12px; border:1px solid rgba(255,255,255,.14);
  background: linear-gradient(180deg, var(--accent) 0%, var(--accent-2) 100%);
  color:#fff; font-weight:700; height:44px; min-width:112px;
  transition: transform .05s ease, filter .2s ease;
}
.support-chat .send-btn:hover{ filter: brightness(1.05); transform: translateY(-1px); }
.support-chat .send-btn:active{ transform: translateY(0); }

/* Scrollbar discreto */
.support-chat .chat-body::-webkit-scrollbar{ width:10px; }
.support-chat .chat-body::-webkit-scrollbar-thumb{
  background: rgba(255,255,255,.14); border-radius:999px; border: 2px solid transparent; background-clip: padding-box;
}
.support-chat .chat-body::-webkit-scrollbar-track{ background: transparent; }

/* Mobile spacing */
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
  <span class="label">Ay</span>
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

  const state = {
    lastTs: null,
    pollTimer: null,
    POLL_MS: 5000,
    isOpen: false,
    ticketId: null
  };

  function replaceNodeWithClone(el){
    if (!el) return el;
    const clone = el.cloneNode(true);
    el.replaceWith(clone);
    return clone;
  }
  const sendBtn = replaceNodeWithClone(document.getElementById('supportSend'));
  const input   = replaceNodeWithClone(inputOrig);

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

  function clearMsgs(hasHistory=false){
    if (!bodyMsgs) return;
    bodyMsgs.innerHTML = hasHistory ? '' : `
      <div class="support-msg">
        <div><strong>Agente</strong></div>
        <div>Aún no hay conversación. Escribe tu primer mensaje para iniciar el ticket.</div>
      </div>`;
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

  function startPolling(){
    stopPolling();
    // Solo hacemos polling si el chat está abierto y YA existe un ticket
    if (!state.isOpen || !state.ticketId) return;
    if (document.hidden) return;
    state.pollTimer = setInterval(async ()=>{ try { await fetchAndAppendNew(); } catch (e) {} }, state.POLL_MS);
  }
  function stopPolling(){
    if (state.pollTimer){ clearInterval(state.pollTimer); state.pollTimer = null; }
  }

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

  async function loadThread(reset=false){
    const res = await fetch(API_SUPPORT_URL + '?action=thread', { method:'GET', headers:{'Accept':'application/json'} });
    const data = await parseJsonResponse(res);

    if (reset){
      state.lastTs = null;
      const hasHistory = !!(data && Array.isArray(data.messages) && data.messages.length);
      clearMsgs(hasHistory);
    }

    if (data && typeof data.ticket !== 'undefined') {
      state.ticketId = data.ticket || null; // puede ser null
    }

    if (data && Array.isArray(data.messages) && data.messages.length){
      for (const m of data.messages){
        addMsg({
          who: m.sender === 'user' ? 'me' : 'agent',
          text: m.message || '',
          att: m.file_path || '',
          ts: m.created_at || ''
        });
        if (m.created_at && (!state.lastTs || toDate(m.created_at) > toDate(state.lastTs))){
          state.lastTs = m.created_at;
        }
      }
    }

    startPolling(); // solo arranca si hay ticketId
  }

  async function fetchAndAppendNew(){
    const res = await fetch(API_SUPPORT_URL + '?action=thread', { method:'GET', headers:{'Accept':'application/json'} });
    const data = await parseJsonResponse(res);
    if (!data || !Array.isArray(data.messages)) return;

    if (typeof data.ticket !== 'undefined') state.ticketId = data.ticket || null;

    const news = [];
    for (const m of data.messages){
      if (!state.lastTs) { news.push(m); continue; }
      if (!m.created_at) continue;
      if (toDate(m.created_at) > toDate(state.lastTs)) news.push(m);
    }
    if (!news.length) return;

    news.sort((a,b)=> (toDate(a.created_at) - toDate(b.created_at)));
    for (const m of news){
      addMsg({
        who: m.sender === 'user' ? 'me' : 'agent',
        text: m.message || '',
        att: m.file_path || '',
        ts: m.created_at || ''
      });
      if (!state.lastTs || (m.created_at && toDate(m.created_at) > toDate(state.lastTs))){
        state.lastTs = m.created_at;
      }
    }

    if (!state.pollTimer && state.ticketId) startPolling();
  }

  // ✅ sendMessage SIN pintado optimista (evita duplicados)
  async function sendMessage(){
    const text = (input?.value || '').trim();
    const f = file?.files && file.files[0];
    if (!text && !f){
      window.showToast ? showToast('Escribe un mensaje o adjunta un archivo', 'warn') : alert('Escribe un mensaje o adjunta un archivo');
      return;
    }

    // Deshabilitar botón mientras envía para evitar dobles clics
    const prevSendDisabled = sendBtn?.disabled;
    if (sendBtn) sendBtn.disabled = true;

    const form = new FormData();
    form.append('message', text);
    if (f) form.append('file', f);

    try{
      const controller = new AbortController();
      const timeoutId = setTimeout(()=>controller.abort(), 15000);
      const res  = await fetch(API_SUPPORT_URL, { method:'POST', body: form, signal: controller.signal });
      clearTimeout(timeoutId);
      const data = await parseJsonResponse(res);

      // Limpiar inputs
      if (input) input.value = '';
      if (file) file.value = '';

      // Guardar ticket creado por el backend (primera vez)
      if (data && data.ticket) {
        state.ticketId = data.ticket;
      }

      // Recargar hilo completo (fuente de verdad = backend)
      await loadThread(true);
      window.showToast && showToast('Mensaje enviado', 'ok');

      if (!state.pollTimer && state.ticketId) startPolling();

    } catch(err){
      console.error('Network/JSON error →', err);
      window.showToast ? showToast(err.message || 'Error de red al enviar', 'err') : alert(err.message || 'Error de red al enviar');
    } finally {
      if (sendBtn) sendBtn.disabled = prevSendDisabled ?? false;
    }
  }

  sendBtn?.addEventListener('click', (e)=>{ e.preventDefault(); sendMessage(); });
  input?.addEventListener('keydown', (e)=>{
    if ((e.key === 'Enter' && (e.metaKey || e.ctrlKey))){
      e.preventDefault(); sendMessage();
    }
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


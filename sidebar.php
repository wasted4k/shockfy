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
  /* (opcional) ocultarlo si el panel está abierto:
     .sidebar.open ~ .sidebar-mobile-toggle{ opacity:.0; pointer-events:none; } */
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

<script>
(function(){
  const sidebar  = document.querySelector('.sidebar');
  const btnInside= document.getElementById('btn');                 // botón interno
  const overlay  = document.querySelector('.sidebar-overlay');      // overlay móvil
  const btnFloat = document.getElementById('sidebarMobileToggle');  // botón flotante
  const mq       = window.matchMedia('(max-width:1024px)');

  if (!sidebar) return;

  const isMobile = () => mq.matches;

  function persist(state){
    try { localStorage.setItem('sidebarOpen', state ? 'true' : 'false'); } catch(e){}
  }
  function isOpen(){ return sidebar.classList.contains('open'); }
  function open(){ sidebar.classList.add('open'); persist(true); }
  function close(){ sidebar.classList.remove('open'); persist(false); }
  function toggle(){ isOpen() ? close() : open(); }

  // 1) Estado inicial: en móvil SIEMPRE cerrada; en desktop respeta lo guardado
  try {
    const saved = localStorage.getItem('sidebarOpen');
    if (isMobile()){
      close();                 // fuerza cerrada y persiste 'false'
    } else {
      (saved === 'true') ? open() : close();
    }
  } catch(e){ close(); }

  // 2) Toggles normales
  btnInside?.addEventListener('click', toggle);
  btnInside?.addEventListener('keydown', (e)=>{ if (e.key==='Enter'||e.key===' '){ e.preventDefault(); toggle(); }});
  btnFloat?.addEventListener('click', toggle);
  overlay?.addEventListener('click', (e)=>{ e.preventDefault(); close(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && isOpen()) close(); });

  // 3) Cerrar automáticamente al navegar con cualquier link del sidebar en móvil
  sidebar.querySelectorAll('a[href]').forEach(a=>{
    a.addEventListener('click', ()=>{
      if (isMobile()){
        persist(false);  // para que la PRÓXIMA página cargue ya cerrada
        close();         // feedback inmediato
      }
    });
  });

  // 4) Si cambia el breakpoint en vivo, ajusta el estado
  const onBPChange = (e)=>{
    if (e.matches){  // entró a móvil
      close();       // siempre cerrada en móvil
    } else {         // volvió a desktop: respeta lo guardado
      const saved = localStorage.getItem('sidebarOpen');
      (saved === 'true') ? open() : close();
    }
  };
  if (mq.addEventListener) mq.addEventListener('change', onBPChange);
  else mq.addListener(onBPChange); // fallback Safari/iOS viejos
})();
</script>

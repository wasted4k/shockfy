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
<!-- Favicon -->
<link rel="icon" type="image/png" href="assets/img/favicon.png">

<!-- Sidebar -->
<style>
/* Google Font Link */
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@200;300;400;500;600;700&display=swap');
*{ margin:0; padding:0; box-sizing:border-box; font-family:"Poppins",sans-serif; }

.sidebar{
  position:fixed; left:0; top:0; height:100%; width:78px;
  background:#11101D; padding:6px 14px; z-index:99; transition:all .5s ease;
}
.sidebar.open{ width:250px; }

.sidebar .logo-details{
  height:60px; display:flex; align-items:center; position:relative;
}

/* Logo oculto por defecto */
.sidebar .logo-details img.logo-icon{
  height:40px; width:40px; margin-right:8px; object-fit:contain;
  opacity:0; visibility:hidden; transition:all .4s ease;
}
.sidebar.open .logo-details img.logo-icon{ opacity:1; visibility:visible; }

.sidebar .logo-details .icon{ opacity:0; transition:all .5s ease; }
.sidebar .logo-details .logo_name{
  color:#fff; font-size:20px; font-weight:600; opacity:0; transition:all .5s ease;
}
.sidebar.open .logo-details .icon, .sidebar.open .logo-details .logo_name{ opacity:1; }

.sidebar .logo-details #btn{
  position:absolute; top:50%; right:0; transform:translateY(-50%);
  font-size:23px; cursor:pointer; transition:all .5s ease;
  z-index:100; pointer-events:auto;
}

.sidebar i{ color:#fff; height:60px; min-width:50px; font-size:28px; text-align:center; line-height:60px; }
.sidebar .nav-list{ margin-top:20px; height:100%; }
.sidebar li{ position:relative; margin:8px 0; list-style:none; }

/* Tooltips (se mantienen por si luego agregas más items) */
.sidebar li .tooltip{
  position:absolute; top:-20px; left:calc(100% + 15px); z-index:3; background:#0c95b8ff;
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

/* ===== Footer perfil (rediseñado) ===== */
.sidebar li.profile{
  position:fixed; height:auto; width:78px; left:0; bottom:0; padding:10px 14px;
  background:#1d1b31; transition:all .5s ease; overflow:hidden;
}
.sidebar.open li.profile{ width:250px; }

.sidebar li .profile-details{ display:flex; align-items:center; gap:10px; min-height:48px; }
.profile-avatar{
  width:44px; height:44px; border-radius:10px; background:linear-gradient(135deg,#202040,#2a2850);
  display:grid; place-items:center; border:1px solid rgba(255,255,255,.12); flex:0 0 44px;
}
.profile-avatar svg{ width:26px; height:26px; opacity:.95; }

.profile-texts{ display:flex; flex-direction:column; gap:4px; min-width:0; }
.profile-name{ color:#fff; font-size:14px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.profile-meta{ display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.meta-chip{
  display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.08); color:#e5e7eb;
  border:1px solid rgba(255,255,255,.18); padding:2px 8px; border-radius:999px; font-size:11px; line-height:1.6;
}
.meta-chip .dot{ width:6px; height:6px; border-radius:999px; background:#60a5fa; opacity:.9; }
.meta-chip.tz .dot{ background:#34d399; }

/* Oculta textos cuando está cerrado */
.sidebar:not(.open) .profile-texts{ display:none; }

.sidebar .profile #log_out{
  position:absolute; top:50%; right:0; transform:translateY(-50%); background:#1d1b31;
  width:100%; height:60px; line-height:60px; border-radius:0; transition:all .5s ease;
}
.sidebar.open .profile #log_out{ width:50px; background:none; }

/* Botón modo oscuro (si lo usas) */
.dark-toggle-btn{
  position:fixed; top:15px; right:20px; z-index:1000; background:#3498db; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:14px;
}

/* ====== Estilos para la imagen de avatar + fallback ====== */
.profile-avatar .avatar-img{
  display:block;
  width:44px; height:44px;
  border-radius:10px;
  object-fit:cover;
  border:1px solid rgba(255,255,255,.12);
}
.profile-avatar .avatar-fallback{ display:none; }
.profile-avatar.avatar-fallback-active .avatar-img{ display:none; }
.profile-avatar.avatar-fallback-active .avatar-fallback{ display:block; }

/* ====== Overlay de trial expirado ====== */
.trial-overlay {
  position: fixed; inset: 0; z-index: 9999;
  display: grid; place-items: center;
  background: rgba(15, 23, 42, 0.65); /* backdrop oscuro */
  backdrop-filter: blur(2px);
}
.trial-modal {
  width: min(92vw, 640px);
  background: #fff; color: #0f172a;
  border-radius: 16px; border: 1px solid #e5e7eb;
  box-shadow: 0 20px 50px rgba(0,0,0,.25);
  padding: 22px;
  text-align: center;
  font-family: "Poppins", system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
}
.trial-modal h2 { margin: 0 0 6px; font-size: 22px; font-weight: 800; }
.trial-modal p  { margin: 6px 0 16px; color: #6b7280; font-size: 14px; }
.trial-actions {
  display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;
}
.trial-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 16px; border-radius: 12px; border: 1px solid transparent;
  background: #299EE6; color: #fff; font-weight: 700; text-decoration: none;
}
.trial-btn.secondary {
  background: #e5e7eb; color: #0f172a; border-color: #d1d5db;
}
/* Evitar scroll de fondo cuando overlay está activo */
body.trial-locked { overflow: hidden; }
</style>

<link href="https://unpkg.com/boxicons@2.0.7/css/boxicons.min.css" rel="stylesheet">

<div class="sidebar">
  <div class="logo-details">
    <img src="assets/img/icono_menu.png" alt="Logo" class="logo-icon">
    <div class="logo_name">ShockFy</div>
    <i class="bx bx-menu" id="btn"></i>
  </div>

  <ul class="nav-list">
    <!-- 🔥 Lupa/buscador eliminado -->
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
            <img
              src="<?= htmlspecialchars($avatarUrl) ?>"
              alt="Foto de perfil"
              class="avatar-img"
              referrerpolicy="no-referrer"
            >
            <!-- Fallback SVG, oculto por defecto; se muestra si la imagen falla -->
            <svg class="avatar-fallback" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="8" r="4.5" stroke="#9fb6ff" stroke-width="1.5"/>
              <path d="M4.5 19.2c1.8-3.2 5-5.2 7.5-5.2s5.7 2 7.5 5.2" stroke="#9fb6ff" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
          <?php else: ?>
            <!-- Sin avatar: muestra SVG genérico -->
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="8" r="4.5" stroke="#9fb6ff" stroke-width="1.5"/>
              <path d="M4.5 19.2c1.8-3.2 5-5.2 7.5-5.2s5.7 2 7.5 5.2" stroke="#9fb6ff" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
          <?php endif; ?>
        </div>
        <div class="profile-texts">
          <div class="profile-name" title="<?= htmlspecialchars($__display_name) ?>">
            <?= htmlspecialchars($__display_name) ?>
          </div>
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

<?php
// === Mostrar overlay si el trial expiró (flag proveniente de auth_check.php) ===
$trialOverlay = (defined('TRIAL_EXPIRED_OVERLAY') && TRIAL_EXPIRED_OVERLAY);
?>
<?php if ($trialOverlay): ?>
  <div class="trial-overlay" role="dialog" aria-modal="true">
    <div class="trial-modal">
      <h2>Tu prueba gratuita ha finalizado</h2>
      <p>Para seguir usando ShockFy, activa un plan. Mientras no tengas un plan activo, tu acceso a inventario y demás módulos permanecerá bloqueado.</p>
      <div class="trial-actions">
        <a class="trial-btn" href="billing.php">
          <!-- ícono relámpago -->
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z" stroke="currentColor" stroke-width="2" /></svg>
          Ir a planes y activar
        </a>
        <a class="trial-btn secondary" href="logout.php">
          <!-- ícono salir -->
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 17l5-5-5-5M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Cerrar sesión
        </a>
      </div>
    </div>
  </div>
  <script>
    // Bloquear scroll e interacción del fondo
    document.body.classList.add('trial-locked');
  </script>
<?php endif; ?>

<!-- Fallback si la imagen falla -->
<script>
(function(){
  const avatarImg = document.querySelector('.profile .profile-avatar .avatar-img');
  if (avatarImg) {
    avatarImg.addEventListener('error', function(){
      const box = avatarImg.closest('.profile-avatar');
      if (box) box.classList.add('avatar-fallback-active');
    }, { once: true });
  }
})();
</script>

<script src="js/script.js"></script>


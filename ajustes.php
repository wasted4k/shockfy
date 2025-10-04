<?php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
require 'auth.php';

// Obtener usuario
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT full_name, username, currency_pref, timezone, time_format FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Defaults
$current_currency = $user['currency_pref'] ?? 'S/.';
$current_tz       = $user['timezone']      ?? 'America/New_York';
$current_fmt      = $user['time_format']   ?? '12h';

// ===== Sincronización del avatar con la sesión =====
$avatarUrl = null;
$avatarCandidates = [
  "uploads/avatars/{$user_id}.jpg",
  "uploads/avatars/{$user_id}.jpeg",
  "uploads/avatars/{$user_id}.png",
  "uploads/avatars/{$user_id}.webp"
];
foreach ($avatarCandidates as $path) {
  if (file_exists($path)) {
    $avatarUrl = $path . '?v=' . filemtime($path);
    break;
  }
}

// Actualizar la sesión para que el sidebar muestre la imagen inmediatamente
if ($avatarUrl) {
  $_SESSION['avatar_url'] = $avatarUrl;
} else {
  if (isset($_SESSION['avatar_url'])) unset($_SESSION['avatar_url']);
}

// Mensajes flash
$success_msg = $_SESSION['ajustes_success'] ?? '';
$error_msg   = $_SESSION['ajustes_error']   ?? '';
unset($_SESSION['ajustes_success'], $_SESSION['ajustes_error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ajustes</title>
  <link rel="stylesheet" href="style.css">
  <style>
    :root{
      --sidebar-w:260px;
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#6b7280;
      --primary:#299EE6;
      --primary-600:#1c7fc0;
      --ring:rgba(41,158,230,.35);
      --danger:#dc2626;
      --success:#16a34a;
      --border:#e5e7eb;
      --shadow:0 10px 22px rgba(15,23,42,.06);
      --radius:16px;
    }
    *{ box-sizing:border-box }
    html,body{ overflow-x:hidden; }
    body{ background:var(--bg); color:var(--text); margin:0; font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }
    body.dark{ background:#1f2125; color:#e9edf5; }

    .app-shell{ display:flex; min-height:100vh; }

    /* Empuje por sidebar en escritorio; en móvil no se empuja */
    .content{ flex:1; padding:28px 24px 48px; transition: margin-left .3s ease; }
    .with-fixed-sidebar{ margin-left:var(--sidebar-w); }
    @media (max-width:1024px){
      .with-fixed-sidebar{ margin-left:0; padding:24px 16px 64px; }
    }

    .header-wrap{ max-width:980px; margin:0 auto 18px; text-align:center; }
    .page-title{ font-size:30px; font-weight:800; letter-spacing:.2px; margin:0; }
    .page-sub{ color:var(--muted); margin-top:6px; font-size:13px; }

    .grid{ max-width:980px; margin:0 auto; display:grid; gap:18px; grid-template-columns:1fr; }

    .card{
      background:var(--card); border:1px solid var(--border);
      border-radius:var(--radius); box-shadow:var(--shadow);
      padding:22px;
      transition:background .3s, color .3s, border .3s;
    }
    body.dark .card{ background:#262a33; border-color:#2f3441; box-shadow:0 12px 26px rgba(0,0,0,.35); }

    .card h3{ margin:0 0 14px; font-size:18px; font-weight:800; }
    .sub{ font-size:12px; color:var(--muted); margin:-6px 0 12px; }

    label{ display:block; margin:10px 0 8px; font-weight:600; }

    .row{ display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:720px){ .row{ grid-template-columns:1fr; } }

    input[type="text"], input[type="password"], select, input[type="file"]{
      width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:12px; outline:none; background:#fff; font-size:15px;
      transition:border .2s, box-shadow .2s, background .2s, color .2s;
    }
    /* Evita zoom iOS en mobile */
    @media (max-width:640px){
      input[type="text"], input[type="password"], select, input[type="file"]{ font-size:16px; }
    }
    input:focus, select:focus{ border-color:var(--primary); box-shadow:0 0 0 4px var(--ring); }
    body.dark input, body.dark select, body.dark input[type="file"]{ background:#323644; color:#e9edf5; border-color:#475569; }
    body.dark input:focus, body.dark select:focus{ box-shadow:0 0 0 4px rgba(41,158,230,.25); }

    .divider{ height:1px; background:#e5e7eb; margin:16px 0; }
    body.dark .divider{ background:#3b3f4a; }

    .btn{
      display:inline-flex; align-items:center; gap:8px; padding:11px 16px; border-radius:12px; border:1px solid transparent;
      background:var(--primary); color:#fff; font-weight:700; cursor:pointer; transition:filter .2s, transform .05s;
    }
    .btn:hover{ filter:brightness(.98); }
    .btn:active{ transform:translateY(1px); }
    .btn.secondary{ background:transparent; color:var(--primary); border-color:var(--primary); }
    .btn.ghost{ background:#f3f6fb; color:#0f172a; border-color:#e5e8f1; }
    body.dark .btn.ghost{ background:#2b303c; color:#e9edf5; border-color:#3b4252; }

    .actions{ display:flex; gap:12px; justify-content:flex-end; margin-top:12px; }
    @media (max-width:640px){
      .actions{ flex-direction:column; align-items:stretch; }
      .actions .btn{ width:100%; justify-content:center; }
    }

    .alert{ padding:12px 16px; border-radius:12px; font-weight:700; text-align:center; margin-bottom:14px; }
    .alert-success{ background:var(--success); color:#fff; }
    .alert-error{ background:var(--danger); color:#fff; }

    /* Avatar block */
    .profile-block{ display:flex; gap:16px; align-items:center; }
    @media (max-width:640px){ .profile-block{ flex-direction:column; align-items:flex-start; } }
    .avatar{
      width:92px; height:92px; border-radius:14px; background:linear-gradient(135deg,#202040,#2a2850);
      display:grid; place-items:center; border:1px solid rgba(0,0,0,.07); overflow:hidden;
    }
    .avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
    .avatar svg{ width:52px; height:52px; opacity:.9; }
    .hint{ font-size:12px; color:var(--muted); margin-top:6px; }
    .inline-actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; }
    .btn.linklike{ background:transparent; color:var(--primary); border:1px dashed var(--primary); }

    /* Input con ícono */
    .field{ position:relative; }
    .icon-left{
      position:absolute; left:12px; top:50%; transform:translateY(-50%); width:18px; height:18px; opacity:.65; pointer-events:none;
    }
    .with-icon{ padding-left:40px !important; }

    /* Password meter */
    .pwd-meter{ display:flex; gap:6px; margin-top:8px; }
    .pwd-meter span{ height:6px; flex:1; background:#e5e7eb; border-radius:999px; }
    .pwd-meter span.on{ background:#34d399; }

    /* Toggle mostrar/ocultar */
    .toggle-vis{
      position:absolute; right:12px; top:50%; transform:translateY(-50%); cursor:pointer; opacity:.75; width:20px; height:20px;
      display:grid; place-items:center;
    }
    .toggle-vis svg{ width:20px; height:20px; }

    /* Password card layout */
    .pw-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    @media (max-width:720px){ .pw-grid{ grid-template-columns:1fr; } }

    /* --- Espaciado de ayudas bajo inputs --- */
    .sub{ margin:8px 0 12px !important; line-height:1.4; }
    .help{ margin-top:8px !important; line-height:1.4; }
    input[type="text"], input[type="password"], input[type="file"], select { margin-bottom:4px; }
  </style>
</head>
<body>
  <div class="app-shell">
    <?php include 'sidebar.php'; ?>

    <main class="content with-fixed-sidebar">

      <div class="header-wrap">
        <h1 class="page-title">Ajustes de la cuenta</h1>
        <div class="page-sub">Personaliza tu perfil, zona horaria y seguridad</div>
      </div>

      <div class="grid">

        <?php if($success_msg): ?>
          <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php elseif($error_msg): ?>
          <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <!-- PERFIL / AVATAR / PREFERENCIAS -->
        <form class="card" action="guardar_ajustes.php" method="POST" enctype="multipart/form-data" novalidate>
          <h3>Perfil</h3>

          <div class="profile-block">
            <div class="avatar" id="avatarBox">
              <?php if ($avatarUrl): ?>
                <img id="avatarPreview" src="<?= htmlspecialchars($avatarUrl) ?>" alt="Avatar">
              <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none">
                  <circle cx="12" cy="8" r="5" stroke="#9fb6ff" stroke-width="1.6"/>
                  <path d="M3.5 20c2.2-4 6.1-6.3 8.5-6.3S18.8 16 20.5 20" stroke="#9fb6ff" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
              <?php endif; ?>
            </div>
            <div style="flex:1; width:100%;">
              <label for="avatar">Foto de perfil</label>
              <input type="file" id="avatar" name="avatar" accept="image/png,image/jpeg,image/webp">
              <div class="hint" id="avatarHint">Formatos: JPG, PNG o WEBP — Máx 2MB.</div>
              <div class="inline-actions">
                <button type="button" class="btn linklike" id="btnRemoveAvatar">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M3 6h18M9 6v12m6-12v12M5 6l1 14a2 2 0 002 2h8a2 2 0 002-2l1-14M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                  Quitar imagen
                </button>
              </div>
            </div>
          </div>

          <div class="divider"></div>

          <div class="row">
            <div>
              <label for="full_name">Nombre completo</label>
              <div class="field">
                <svg class="icon-left" viewBox="0 0 24 24" fill="none"><path d="M12 12a5 5 0 100-10 5 5 0 000 10zM3 21a9 9 0 1118 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input class="with-icon" type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
              </div>
              <div class="sub">Para saber cómo llamarte :)</div>
            </div>
            <div>
              <label for="username">Correo electrónico</label>
              <div class="field">
                <svg class="icon-left" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h8M4 18h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input class="with-icon" type="text" id="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled>
              </div>
              <div class="sub">No puedes editar tu correo.</div>
            </div>
          </div>

          <div class="divider"></div>

          <h3>Preferencias</h3>
          <div class="row">
            <div>
              <label for="currency_pref">Moneda preferida</label>
              <select name="currency_pref" id="currency_pref" required>
                <option value="S/."     <?= $current_currency == 'S/.'      ? 'selected' : '' ?>>S/. – Sol peruano (PEN)</option>
                <option value="$"       <?= $current_currency == '$'        ? 'selected' : '' ?>>$ – Dólar estadounidense (USD)</option>
                <option value="€"       <?= $current_currency == '€'        ? 'selected' : '' ?>>€ – Euro (EUR)</option>
                <option value="$ (MXN)" <?= $current_currency == '$ (MXN)'  ? 'selected' : '' ?>>$ – Peso mexicano (MXN)</option>
                <option value="$ (ARS)" <?= $current_currency == '$ (ARS)'  ? 'selected' : '' ?>>$ – Peso argentino (ARS)</option>
                <option value="$ (CLP)" <?= $current_currency == '$ (CLP)'  ? 'selected' : '' ?>>$ – Peso chileno (CLP)</option>
                <option value="COL$"    <?= $current_currency == 'COL$'     ? 'selected' : '' ?>>COL$ – Peso colombiano (COP)</option>
                <option value="VES"     <?= $current_currency == 'VES'      ? 'selected' : '' ?>>VES – Bolívar venezolano</option>
                <option value="$U"      <?= $current_currency == '$U'       ? 'selected' : '' ?>>$U – Peso uruguayo (UYU)</option>
                <option value="₲"       <?= $current_currency == '₲'        ? 'selected' : '' ?>>₲ – Guaraní paraguayo (PYG)</option>
                <option value="Bs"      <?= $current_currency == 'Bs'        ? 'selected' : '' ?>>Bs – Boliviano (BOB)</option>
                <option value="$ (EC)"  <?= $current_currency == '$ (EC)'   ? 'selected' : '' ?>>$ – Dólar ecuatoriano (EC)</option>
              </select>
              <div class="sub">Se usa en tarjetas, tablas y reportes</div>
            </div>
            <div>
              <label for="time_format">Formato de hora</label>
              <select name="time_format" id="time_format" required>
                <option value="12h" <?= $current_fmt === '12h' ? 'selected' : '' ?>>12 horas (AM/PM)</option>
                <option value="24h" <?= $current_fmt === '24h' ? 'selected' : '' ?>>24 horas</option>
              </select>
              <div class="sub">Por defecto 12h</div>
            </div>
          </div>

          <div class="divider"></div>

          <h3>Zona horaria</h3>
          <div class="row">
            <div>
              <label for="country">País</label>
              <select id="country"></select>
              <div class="sub">Latinoamérica, USA y España</div>
            </div>
            <div>
              <label for="timezone">Zona horaria</label>
              <select name="timezone" id="timezone" required></select>
              <div class="sub">Vista previa: <span class="chip" id="tzPreview">—</span></div>
            </div>
          </div>

          <div class="actions">
            <button type="button" class="btn ghost" onclick="window.history.back()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 19l-7-7 7-7M3 12h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              Volver
            </button>
            <button type="submit" class="btn">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M7 3h10l4 4v14H7z" stroke="currentColor" stroke-width="2"/><path d="M7 7h8v4H7zM13 21v-6" stroke="currentColor" stroke-width="2"/></svg>
              Guardar cambios
            </button>
          </div>
        </form>

        <!-- CAMBIO DE CONTRASEÑA -->
        <form class="card" action="cambiar_password.php" method="POST" novalidate>
          <h3>Seguridad</h3>
          <div class="sub">Actualiza tu contraseña para proteger tu cuenta</div>

          <div class="pw-grid">
            <div>
              <label for="current_password">Contraseña actual</label>
              <div class="field">
                <svg class="icon-left" viewBox="0 0 24 24" fill="none"><path d="M7 10V7a5 5 0 0110 0v3M5 10h14v10H5z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input class="with-icon" type="password" id="current_password" name="current_password" required>
                <span class="toggle-vis" data-target="current_password" title="Mostrar/Ocultar">
                  <svg viewBox="0 0 24 24" fill="none"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                </span>
              </div>
            </div>

            <div>
              <label for="new_password">Nueva contraseña</label>
              <div class="field">
                <svg class="icon-left" viewBox="0 0 24 24" fill="none"><path d="M7 10V7a5 5 0 0110 0v3M5 10h14v10H5z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input class="with-icon" type="password" id="new_password" name="new_password" minlength="8" required>
                <span class="toggle-vis" data-target="new_password" title="Mostrar/Ocultar">
                  <svg viewBox="0 0 24 24" fill="none"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                </span>
              </div>
              <div class="pwd-meter" id="pwdMeter"><span></span><span></span><span></span><span></span></div>
              <div class="sub">Mínimo 8 caracteres. Recomendado: mayúsculas, minúsculas y números.</div>
            </div>

            <div>
              <label for="confirm_password">Repetir nueva contraseña</label>
              <div class="field">
                <svg class="icon-left" viewBox="0 0 24 24" fill="none"><path d="M7 10V7a5 5 0 0110 0v3M5 10h14v10H5z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input class="with-icon" type="password" id="confirm_password" name="confirm_password" required>
                <span class="toggle-vis" data-target="confirm_password" title="Mostrar/Ocultar">
                  <svg viewBox="0 0 24 24" fill="none"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                </span>
              </div>
            </div>

            <div style="display:flex; align-items:flex-end;">
              <button type="submit" class="btn" style="width:100%">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 2l7 3v6c0 5-3.5 9-7 11-3.5-2-7-6-7-11V5l7-3z" stroke="currentColor" stroke-width="2"/></svg>
                Actualizar contraseña
              </button>
            </div>
          </div>
        </form>

      </div>
    </main>
  </div>

  <script>
    const MAX_FILE_MB = 2, MAX_BYTES = MAX_FILE_MB * 1024 * 1024, MAX_W = 2000, MAX_H = 2000;

    // Dark mode persistente
    (function(){ if(localStorage.getItem('darkMode')==='true'){ document.body.classList.add('dark'); } })();

    // ===== Timezones por país =====
    const COUNTRY_TZ = {
      "Argentina": ["America/Argentina/Buenos_Aires","America/Argentina/Cordoba","America/Argentina/Salta","America/Argentina/Mendoza","America/Argentina/Ushuaia"],
      "Bolivia": ["America/La_Paz"],
      "Brasil": ["America/Sao_Paulo","America/Manaus","America/Cuiaba","America/Fortaleza","America/Belem","America/Recife","America/Bahia","America/Porto_Velho","America/Boa_Vista"],
      "Chile": ["America/Santiago","America/Punta_Arenas","Pacific/Easter"],
      "Colombia": ["America/Bogota"],
      "Costa Rica": ["America/Costa_Rica"],
      "Cuba": ["America/Havana"],
      "República Dominicana": ["America/Santo_Domingo"],
      "Ecuador": ["America/Guayaquil","Pacific/Galapagos"],
      "El Salvador": ["America/El_Salvador"],
      "Guatemala": ["America/Guatemala"],
      "Honduras": ["America/Tegucigalpa"],
      "México": ["America/Mexico_City","America/Monterrey","America/Tijuana","America/Merida","America/Cancun","America/Mazatlan","America/Chihuahua","America/Hermosillo"],
      "Nicaragua": ["America/Managua"],
      "Panamá": ["America/Panama"],
      "Paraguay": ["America/Asuncion"],
      "Perú": ["America/Lima"],
      "Puerto Rico": ["America/Puerto_Rico"],
      "Uruguay": ["America/Montevideo"],
      "Venezuela": ["America/Caracas"],
      "Estados Unidos": ["America/New_York","America/Chicago","America/Denver","America/Los_Angeles","America/Phoenix","America/Anchorage","Pacific/Honolulu"],
      "España": ["Europe/Madrid","Atlantic/Canary"]
    };

    const currentTz  = <?= json_encode($current_tz) ?>;
    const currentFmt = <?= json_encode($current_fmt) ?>;

    const tzToCountry = (() => {
      const map = {};
      for (const [country, zones] of Object.entries(COUNTRY_TZ)) zones.forEach(z => map[z]=country);
      return map;
    })();
    const currentCountry = tzToCountry[currentTz] || "Estados Unidos";

    const countrySel = document.getElementById('country');
    const tzSel      = document.getElementById('timezone');
    const tzPreview  = document.getElementById('tzPreview');

    function fillCountries(){
      Object.keys(COUNTRY_TZ).forEach(c => {
        const opt = document.createElement('option');
        opt.value = c; opt.textContent = c;
        if (c === currentCountry) opt.selected = true;
        countrySel.appendChild(opt);
      });
    }
    function prettyTzName(tz){ const [a,b] = tz.split('/'); return a + (b ? ' — ' + b.replaceAll('_',' ') : ''); }
    function fillTimezones(country){
      tzSel.innerHTML = '';
      (COUNTRY_TZ[country] || []).forEach(z => {
        const opt = document.createElement('option');
        opt.value = z; opt.textContent = prettyTzName(z);
        if (z === currentTz) opt.selected = true;
        tzSel.appendChild(opt);
      });
      updatePreview();
    }
    function updatePreview(){
      const tz = tzSel.value;
      const fmt = document.getElementById('time_format').value;
      try{
        const now = new Date();
        const options = { hour: 'numeric', minute: '2-digit', hour12: (fmt === '12h') };
        const ex = new Intl.DateTimeFormat(undefined, options).format(now);
        tzPreview.textContent = `${prettyTzName(tz)} · ${ex}`;
      }catch(e){ tzPreview.textContent = prettyTzName(tz); }
    }
    countrySel.addEventListener('change', e => fillTimezones(e.target.value));
    tzSel.addEventListener('change', updatePreview);
    document.getElementById('time_format').addEventListener('change', updatePreview);

    fillCountries();
    fillTimezones(currentCountry);

    // ===== Avatar preview & remover =====
    const inputAvatar  = document.getElementById('avatar');
    const avatarBox    = document.getElementById('avatarBox');
    const btnRemove    = document.getElementById('btnRemoveAvatar');
    const avatarHint   = document.getElementById('avatarHint');

    if (avatarHint) {
      avatarHint.textContent = `Formatos: JPG, PNG o WEBP — Máx ${MAX_FILE_MB}MB, hasta ${MAX_W}×${MAX_H}px.`;
    }

    function resetRemoveFlagIfAny() {
      const rm = document.getElementById('remove_avatar_flag');
      if (rm) rm.remove();
    }

    inputAvatar?.addEventListener('change', () => {
      const f = inputAvatar.files?.[0];
      if (!f) return;

      const okType = /image\/(jpeg|png|webp)/i.test(f.type);
      if (!okType){
        alert('Formato inválido. Usa JPG, PNG o WEBP.');
        inputAvatar.value=''; return;
      }
      if (f.size > MAX_BYTES){
        alert(`La imagen supera ${MAX_FILE_MB}MB.`); inputAvatar.value=''; return;
      }

      const url = URL.createObjectURL(f);
      const imgProbe = new Image();
      imgProbe.onload = () => {
        const w = imgProbe.naturalWidth, h = imgProbe.naturalHeight;
        URL.revokeObjectURL(url);
        if (w > MAX_W || h > MAX_H) {
          alert(`Dimensiones máximas: ${MAX_W}×${MAX_H}px. Esta imagen es ${w}×${h}px.`);
          inputAvatar.value=''; return;
        }
        let img = document.getElementById('avatarPreview');
        if (!img){
          img = document.createElement('img'); img.id = 'avatarPreview';
          avatarBox.innerHTML = ''; avatarBox.appendChild(img);
        }
        img.src = URL.createObjectURL(f);
        resetRemoveFlagIfAny();
      };
      imgProbe.onerror = () => { URL.revokeObjectURL(url); alert('No se pudo leer la imagen seleccionada.'); inputAvatar.value=''; };
      imgProbe.src = url;
    });

    btnRemove?.addEventListener('click', () => {
      inputAvatar.value = '';
      avatarBox.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="8" r="5" stroke="#9fb6ff" stroke-width="1.6"/>
          <path d="M3.5 20c2.2-4 6.1-6.3 8.5-6.3S18.8 16 20.5 20" stroke="#9fb6ff" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
      `;
      let rm = document.getElementById('remove_avatar_flag');
      if (!rm) {
        rm = document.createElement('input');
        rm.type = 'hidden'; rm.name = 'remove_avatar'; rm.id = 'remove_avatar_flag'; rm.value = '1';
        avatarBox.closest('form').appendChild(rm);
      }
    });

    // ===== Contraseña: medidor y toggles =====
    function strengthScore(p){
      let s = 0;
      if (p.length >= 8) s++;
      if (/[A-Z]/.test(p)) s++;
      if (/[a-z]/.test(p)) s++;
      if (/[0-9]/.test(p) || /[^A-Za-z0-9]/.test(p)) s++;
      return Math.min(s,4);
    }
    const pwd   = document.getElementById('new_password');
    const meter = document.getElementById('pwdMeter')?.querySelectorAll('span');
    pwd?.addEventListener('input', () => {
      const sc = strengthScore(pwd.value);
      meter?.forEach((el, i) => el.classList.toggle('on', i < sc));
    });

    document.querySelectorAll('.toggle-vis').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const id = btn.getAttribute('data-target'); const input = document.getElementById(id);
        if (!input) return;
        input.type = (input.type === 'password') ? 'text' : 'password';
      });
    });

    const confirm = document.getElementById('confirm_password');
    confirm?.addEventListener('input', ()=>{
      if (pwd.value && confirm.value && pwd.value !== confirm.value){
        confirm.setCustomValidity('Las contraseñas no coinciden');
      } else { confirm.setCustomValidity(''); }
    });

    // Dark mode inicial
    if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark');
  </script>
</body>
</html>

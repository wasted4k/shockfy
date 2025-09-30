<?php
// welcome.php — 
session_start();
if (empty($_SESSION['logged_in'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/db.php';

// Datos de sesión
$user_id   = $_SESSION['user_id']   ?? null;
$full_name = $_SESSION['full_name'] ?? 'Usuario';
$email     = $_SESSION['email']     ?? '';
$verified  = isset($_SESSION['email_verified']) ? (bool)$_SESSION['email_verified'] : false;

// Si faltara el email en sesión, lo traemos de la BD
if ($email === '' && $user_id && isset($pdo)) {
  try {
    $st = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $st->execute([$user_id]);
    $dbEmail = $st->fetchColumn();
    if ($dbEmail) { $email = $_SESSION['email'] = $dbEmail; }
  } catch (Throwable $e) {}
}

// --- Paso actual controlado por ?step= ---
// Por defecto: Paso 1
$step = 1;
if (!empty($_GET['step'])) {
  $try = (int)$_GET['step'];
  if ($try < 1) $try = 1;
  if ($try > 4) $try = 4;
  // No permitir Step 4 si no está verificado
  if ($try === 4 && !$verified) {
    $step = 3;
  } else {
    $step = $try;
  }
}

// Helper UI
function isActive($n, $step){ return $n === $step; }

// Mensaje de error opcional (?err=)
$err = isset($_GET['err']) ? trim($_GET['err']) : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Bienvenido – ShockFy</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#ffffff; --text:#111827; --muted:#6b7280; --line:#e5e7eb;
      --control:#f9fafb; --control-border:#d1d5db;
      --primary:#2563eb; --primary-600:#1d4ed8;
      --ok:#166534;
      --radius:8px; --maxw:860px;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{
      font-family: Segoe UI, Inter, system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif;
      background: var(--bg); color: var(--text); font-size: 16px; line-height: 1.45;
    }
    a{color:inherit;text-decoration:none}

    .header{border-bottom:1px solid var(--line); background:#fff}
    .header-wrap{
      max-width: var(--maxw); margin:0 auto; padding:0 16px;
      height:68px; display:grid; grid-template-columns:1fr auto 1fr; align-items:center;
    }
    .brand{justify-self:center; display:inline-flex; align-items:center; gap:10px}
    .brand img{width:54px; height:54px; object-fit:contain}
    .brand .name{font-weight:700}
    .logout{justify-self:end}
    .link{display:inline-flex; align-items:center; height:36px; padding:0 12px; border:1px solid var(--line); border-radius: 6px; font-weight:600}

    .container{max-width: var(--maxw); margin:24px auto; padding:0 16px}
    .title{margin:0 0 6px; font-size:24px; font-weight:700}
    .subtitle{margin:0; color: var(--muted)}

    .steps-bar{display:flex; gap:12px; margin:16px 0 18px; flex-wrap:wrap}
    .pill{
      padding:6px 10px; border:1px solid var(--line); border-radius:999px; font-weight:700; font-size:13px; color:#374151; background:#f8fafc;
    }
    .pill.active{ border-color:#bfd7ff; background:#eef5ff; color:#0b3ea8 }
    .pill.done{ border-color:#c7efd1; background:#eefbf2; color:var(--ok) }

    .alert{
      background:#fef2f2; color:#991b1b; border:1px solid #fecaca;
      border-radius:8px; padding:10px 12px; font-weight:700; margin:10px 0 0;
    }

    .card{border:1px solid var(--line); border-radius: var(--radius); padding:16px}
    .card h2{margin:0 0 10px; font-size:18px}
    .hint{color: var(--muted); margin:0 0 12px}
    label{display:block; font-weight:700; margin-bottom:6px}
    .field{
      display:flex; align-items:center; gap:8px;
      height:40px; padding:0 10px; border:1px solid var(--control-border); background: var(--control);
      border-radius:8px; max-width: 460px;
    }
    .input,.select{border:0; outline:0; background:transparent; width:100%; font-size:15px; color: var(--text)}
    .select:invalid{color:#9aa3b2}

    .row{margin-top:12px}
    .btn{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      height:40px; padding:0 14px; border-radius: 6px; border:1px solid transparent;
      background: var(--primary); color:#fff; font-weight:700; cursor:pointer;
    }
    .btn:hover{ background: var(--primary-600) }
    .btn.secondary{ background:#f3f4f6; color:#111827; border-color: var(--line) }
    .btn[disabled]{ opacity:.6; cursor:not-allowed }
    .btn.loading{ opacity:.7; pointer-events:none; }

    .divider{height:1px; background:var(--line); margin:18px 0}
    .footer{display:flex; justify-content:flex-end; margin-top:16px}

    /* Toasts */
    .toast {
      position: fixed; right: 16px; bottom: 16px; z-index: 9999;
      background: #1f2937; color: #fff; padding: 12px 16px;
      border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,.15);
      opacity: 0; transform: translateY(8px); transition: all .25s ease;
      max-width: 90vw;
    }
    .toast.show { opacity: 1; transform: translateY(0); }
    .toast.success { background: #065f46; }  /* verde */
    .toast.error { background: #7f1d1d; }    /* rojo  */
    .toast.info { background: #1f2937; }     /* gris  */
    .toast .close { margin-left: 12px; cursor: pointer; opacity:.85 }
  </style>
</head>
<body>
<header class="header">
  <div class="header-wrap">
    <div></div>
    <a class="brand" href="home.php" aria-label="ShockFy">
      <img src="assets/img/icono_menu.png" alt="ShockFy"><span class="name">ShockFy</span>
    </a>
    <div class="logout"><a class="link" href="logout.php">Salir</a></div>
  </div>
</header>

<main class="container">
  <h1 class="title">Bienvenido a ShockFy, <?= htmlspecialchars($full_name) ?> 👋</h1>
  <p class="subtitle">Configura tu cuenta en <strong>3 pasos</strong>. Te tomará menos de un minuto.</p>

  <div class="steps-bar">
    <div class="pill <?= ($step>1?'done':($step===1?'active':'')) ?>">1) Moneda</div>
    <div class="pill <?= ($step>2?'done':($step===2?'active':'')) ?>">2) Zona horaria</div>
    <div class="pill <?= ($step>3?'done':($step===3?'active':'')) ?>">3) Verificar email</div>
  </div>

  <?php if ($err): ?>
    <div class="alert"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- Paso 1: Moneda -->
  <?php if (isActive(1, $step)): ?>
    <section class="card">
      <h2>1) Moneda</h2>
      <p class="hint">Se usa en precios y reportes.</p>
      <form action="onboarding_save.php?next=2" method="post">
        <input type="hidden" name="action" value="save_currency">
        <label for="currency">Moneda preferida</label>
        <div class="field">
          <select class="select" id="currency" name="currency" required>
            <option value="" selected disabled hidden>Selecciona…</option>
            <?php foreach (['S/.','USD','EUR','MXN','COP','CLP','ARS','BRL','PEN','CAD','DOP','UYU','VES'] as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row"><button class="btn" type="submit">Guardar y continuar</button></div>
      </form>
    </section>
  <?php endif; ?>

  <!-- Paso 2: Zona horaria -->
  <?php if (isActive(2, $step)): ?>
    <section class="card">
      <h2>2) Zona horaria</h2>
      <p class="hint">Fechas y horas correctas para tu país.</p>
      <form action="onboarding_save.php?next=3" method="post">
        <input type="hidden" name="action" value="save_timezone">
        <label for="timezone">Zona horaria</label>
        <div class="field">
          <select class="select" id="timezone" name="timezone" required>
            <option value="" selected disabled hidden>Selecciona…</option>
            <?php
              $tzs = [
                'UTC','America/Lima','America/Mexico_City','America/Bogota','America/Santiago','America/Buenos_Aires',
                'America/Asuncion','America/La_Paz','America/Montevideo','America/Guayaquil','America/Panama',
                'America/Guatemala','America/El_Salvador','America/Tegucigalpa','America/Costa_Rica','America/Managua',
                'America/New_York','America/Chicago','America/Denver','America/Los_Angeles',
                'Europe/Madrid','Europe/Lisbon'
              ];
              foreach ($tzs as $tz) {
                echo '<option value="'.htmlspecialchars($tz).'">'.htmlspecialchars($tz).'</option>';
              }
            ?>
          </select>
        </div>
        <div class="row"><button class="btn" type="submit">Guardar y continuar</button></div>
      </form>
    </section>
  <?php endif; ?>

  <!-- Paso 3: Verificar email -->
  <?php if (isActive(3, $step)): ?>
    <section class="card">
      <h2>3) Verificación de email</h2>
      <p class="hint">Requisito para acceder al dashboard.</p>

      <label for="email">Email</label>
      <div class="field" style="max-width:460px">
        <input class="input" id="email" type="email" value="<?= htmlspecialchars($email) ?>" readonly>
      </div>

      <?php if (!$verified): ?>
        <div class="row" style="display:flex; gap:10px; flex-wrap:wrap">
          <form action="send_verification.php" method="post" id="formSendCode">
            <input type="hidden" name="action" value="send_email_verification">
            <button class="btn" type="submit" id="btnSendCode">Enviar código</button>
          </form>
          <form action="verify.php?back=welcome.php%3Fstep%3D4" method="post" style="display:flex; gap:8px; flex-wrap:wrap">
            <input type="hidden" name="action" value="verify_code">
            <div class="field" style="flex:1; min-width:220px">
              <input class="input" type="text" name="code" inputmode="numeric" maxlength="8" placeholder="Código de verificación" required>
            </div>
            <button class="btn secondary" type="submit">Verificar</button>
          </form>
        </div>
        <p class="hint">Te enviaremos un código a tu correo. Ingresa el código y presiona “Verificar”.</p>
      <?php else: ?>
        <p class="hint" style="color:var(--ok); font-weight:700">¡Email verificado!</p>
        <div class="row"><a class="btn" href="welcome.php?step=4">Continuar</a></div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- Paso 4: Todo OK -->
  <?php if (isActive(4, $step)): ?>
    <section class="card">
      <h2>¡Listo!</h2>
      <p class="hint">Moneda y zona horaria guardadas, email verificado.</p>
    </section>
  <?php endif; ?>

  <div class="divider"></div>

  <div class="footer">
    <a class="btn <?= $verified ? '' : 'secondary' ?>" href="index.php" <?= $verified ? '' : 'onclick="return false" title="Verifica tu email primero"' ?>>
      Ir a ShockFy
    </a>
  </div>
</main>

<!-- Toast container + script -->
<div id="toast" class="toast" role="status" aria-live="polite" aria-atomic="true"></div>
<script>
  // Lee un parámetro del querystring (?sent=1, ?err=..., ?dev=1)
  function getParam(name){
    const p = new URLSearchParams(window.location.search);
    return p.get(name);
  }

  // Muestra toast simple
  function showToast(msg, type='info', ms=3500){
    const el = document.getElementById('toast');
    el.className = 'toast ' + (type || 'info');
    el.innerHTML = `<span>${msg}</span>`;
    // Reflow para aplicar animación
    void document.body.offsetHeight;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(()=> el.classList.remove('show'), ms);
  }

  // 1) Mostrar toasts según query (?sent=1, ?err=..., ?dev=1)
  (function(){
    const sent = getParam('sent');
    const err  = getParam('err');
    const dev  = getParam('dev');

    if (err){
      try { showToast(decodeURIComponent(err), 'error', 5000); }
      catch(e){ showToast(err, 'error', 5000); }
    } else if (sent === '1'){
      showToast('Código enviado ✅ Revisa tu correo.', 'success', 3500);
      if (dev === '1'){
        showToast('Modo DEV: el código también se guardó en el .txt', 'info', 4000);
      }
    }
  })();

  // 2) UX: al enviar el formulario, desactivar botón y mostrar "Enviando…"
  (function(){
    const form = document.getElementById('formSendCode');
    const btn  = document.getElementById('btnSendCode');
    if(!form || !btn) return;

    form.addEventListener('submit', function(){
      btn.classList.add('loading');
      const original = btn.textContent;
      btn.textContent = 'Enviando…';
      showToast('Enviando código…', 'info', 2000);
      // si algo falla y no hay redirección, reactivar tras 8s
      setTimeout(()=>{
        btn.classList.remove('loading');
        btn.textContent = original;
      }, 8000);
    });
  })();
</script>
</body>
</html>

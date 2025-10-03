<?php
session_start();

// Si el usuario ya está logueado, redirigirlo
if (!empty($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

require 'db.php';

$error = '';
$account_disabled = false;
$disabled_user_name = '';

// Procesar login (por CORREO)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Completa correo y contraseña';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Correo inválido';
    } else {
        // Busca por email
        $stmt = $pdo->prepare("SELECT id, full_name, email, password AS password_hash, role, status, email_verified_at
                               FROM users
                               WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            if ((int)$user['status'] === 0) {
                // Usuario desactivado
                $account_disabled = true;
                $disabled_user_name = $user['full_name'] ?: '';
            } else {
                // Usuario activo, iniciar sesión
                $_SESSION['logged_in']      = true;                           // <— CLAVE PARA EVITAR EL BUCLE
                $_SESSION['user_id']        = (int)$user['id'];
                $_SESSION['role']           = $user['role'] ?? null;
                $_SESSION['full_name']      = $user['full_name'] ?: 'Usuario';
                $_SESSION['email']          = $user['email'];
                $_SESSION['email_verified'] = !empty($user['email_verified_at']);

                // Redirigir según verificación de correo
                if (!$_SESSION['email_verified']) {
                    header('Location: welcome.php?step=3');
                } else {
                    header('Location: index.php');
                }
                exit;
            }
        } else {
            $error = 'Correo o contraseña incorrectos';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>ShockFy — Iniciar sesión</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Favicon -->
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
  <!-- Fuente -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg-1:#0b2cff0d;
      --bg-2:#eaf0ff;
      --text:#0b1220;
      --muted:#475569;
      --primary:#2344ec;
      --primary-2:#5ea4ff;
      --danger:#dc2626;
      --ok:#16a34a;
      --panel:#ffffff;
      --border:#e5e7eb;
      --shadow:0 18px 40px rgba(2,6,23,.18);
      --radius:20px;
      --max:1120px;
    }
    *{box-sizing:border-box}
    html,body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:var(--text)}
    body{
      min-height:100dvh;
      background:
        radial-gradient(900px 700px at -10% -20%, #e3e9ff 0%, transparent 60%),
        radial-gradient(900px 700px at 110% -20%, #edf3ff 0%, transparent 60%),
        linear-gradient(180deg, #f7f9ff, #eef2ff);
      display:flex;flex-direction:column;
    }
    /* NAV */
    nav{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.78);backdrop-filter:blur(8px);border-bottom:1px solid var(--border)}
    .nav-container{max-width:var(--max);margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:14px 18px}
    .logo{font-weight:800;letter-spacing:.2px;display:flex;align-items:center;gap:10px}
    .logo img{height:34px}
    .nav-links{display:flex;gap:12px;align-items:center}
    .nav-links a{padding:10px 12px;border-radius:10px;font-weight:600;color:#0f172a;text-decoration:none}
    .nav-links a:hover{background:#1874ED}
    .cta{background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff !important;padding:10px 16px;border-radius:12px;border:1px solid #cfe0ff;box-shadow:var(--shadow)}
    .login-btn{background:#fff !important;color:var(--text) !important;border:1px solid var(--border)}
    .login-btn:hover{background:#f8fafc}
    .mobile-toggle{display:none}

    /* LAYOUT */
    .wrap{max-width:var(--max);margin:0 auto;padding:26px 18px 48px;flex:1;display:grid;grid-template-columns:1.1fr .9fr;gap:32px;align-items:center}
    @media (max-width:980px){.wrap{grid-template-columns:1fr}}

    /* LADO IZQ: COPY */
    .intro h1{font-size:36px;line-height:1.08;margin:0 0 12px;font-weight:800;color:#0b2cff}
    .intro p{color:var(--muted);max-width:620px;margin:0 0 16px}
    .intro-cta{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px}
    .btn-ghost{
      padding:12px 16px;border-radius:12px;border:1px solid #cfe0ff;
      background:#fff;color:var(--primary);font-weight:800;text-decoration:none;
      box-shadow:0 6px 16px rgba(35,68,236,.10);
    }
    .btn-ghost:hover{background:#f8faff}

    .points{display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:560px;margin-top:14px}
    .point{display:flex;align-items:center;gap:8px;color:#0f172a}
    .point .ic{color:var(--ok);width:18px;height:18px}

    /* CARD LOGIN (agrandada) */
    .card{
      background:linear-gradient(180deg,#ffffff,#f7faff);
      border:1px solid #dbe4ff;border-radius:var(--radius);box-shadow:var(--shadow);
      padding:28px 24px;max-width:560px;margin:0 0 0 auto;
    }
    @media (max-width:980px){.card{margin:0 auto}}
    .card-top{
      display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:12px
    }
    .brand-circle{
      width:72px;height:72px;border-radius:50%;display:grid;place-items:center;
      background:linear-gradient(135deg,#e8efff,#f3f7ff);border:1px solid #d6e1ff;
    }
    .brand-circle img{width:40px;height:40px;object-fit:contain}
    .card h2{margin:0;font-weight:800;font-size:24px}
    .sub{color:var(--muted);font-size:13px;margin:6px 0 12px}

    .create-inline{
      padding:10px 12px;border-radius:10px;border:1px solid #cfe0ff;
      color:var(--primary);text-decoration:none;font-weight:800;background:#fff;
      box-shadow:0 6px 16px rgba(35,68,236,.10);
    }
    .create-inline:hover{background:#f8faff}

    .alert{display:none;margin:0 auto 12px;background:#fee2e2;border:1px solid #fecaca;color:#7f1d1d;padding:10px 12px;border-radius:12px;font-weight:600;text-align:center}
    .alert.show{display:block}
    .alert.ok{background:#ecfdf5;border-color:#d1fae5;color:#065f46}

    .field{margin:12px 0 14px}
    .label{font-size:13px;font-weight:700;margin-bottom:6px}
    .input-wrap{
      position:relative;border:1px solid #dbe4ff;background:#fff;border-radius:12px;display:flex;align-items:center;padding:10px 14px;
    }
    .input-wrap:focus-within{box-shadow:0 0 0 3px rgba(35,68,236,.15)}
    input[type="email"],input[type="password"]{
      border:none;outline:none;background:transparent;width:100%;font-size:16px;color:#0f172a;
    }
    .eye-btn{
      background:transparent;border:0;cursor:pointer;display:grid;place-items:center;
      width:36px;height:36px;border-radius:8px;color:#64748b;
    }
    .eye-btn:hover{background:#f1f5ff}
    .btn{
      width:100%;padding:14px;border-radius:12px;border:1px solid #cfe0ff;
      background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff;font-weight:800;cursor:pointer;
      box-shadow:0 12px 28px rgba(35,68,236,.18);transition:transform .15s ease, filter .2s ease;
      font-size:16px;
    }
    .btn:hover{filter:brightness(.95);transform:translateY(-1px)}
    .helpers{display:flex;justify-content:space-between;align-items:center;margin-top:10px}
    .forgot{color:var(--primary);font-weight:700;font-size:13px}
    .forgot:hover{text-decoration:underline}

    /* FOOTER MINI */
    .foot{padding:12px 18px;border-top:1px solid var(--border);background:#fff;color:var(--muted);font-size:13px}
    .foot-inner{max-width:var(--max);margin:0 auto;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}

/* Quitar subrayado del Crearse una cuenta */
a.cta,
a.cta:link,
a.cta:visited,
a.cta:hover,
a.cta:focus,
a.cta:active {
  text-decoration: none;
}

/* Mejor clic y alineación */
a.cta {
  display: inline-block; 
}
  </style>
</head>
<body>

  <!-- NAVEGACION (MENU) -->
  <nav>
    <div class="nav-container">
      <div class="logo">
        <img src="assets/img/icono_menu.png" alt="ShockFy">
        <h3>ShockFy</h3>
      </div>
      <div class="nav-links">
        <a href="home.php#features">Características</a>
        <a href="home.php#how">Cómo funciona</a>
        <a href="home.php#pricing">Precio</a>
        <a href="signup.php" class="cta">Pruébalo gratis</a>
        <a href="login.php" class="login-btn">Iniciar sesión</a>
      </div>
      <button class="mobile-toggle" aria-label="Abrir menú"><span></span></button>
    </div>
  </nav>

  <!-- WRAP -->
  <div class="wrap">
    <!-- COPY IZQ -->
    <div class="intro">
      <h1>Accede a tu panel y sigue vendiendo sin fricción.</h1>
      <p>Registra ventas en segundos, controla inventario y mira métricas clave del mes. Seguridad, sencillez y rendimiento en un mismo lugar.</p>
      <div class="intro-cta">
        <a class="btn-ghost" href="signup.php">Crear cuenta</a>
        <a class="cta" href="signup.php">Pruébalo gratis 15 días</a>
      </div>
      <div class="points">
        <div class="point">
          <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
          Conexión segura (HTTPS/SSL)
        </div>
        <div class="point">
          <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
          Sin complicaciones
        </div>
        <div class="point">
          <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
          Soporte rápido
        </div>
        <div class="point">
          <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
          Actualizaciones constantes
        </div>
      </div>
    </div>

    <!-- CARD LOGIN (más grande) -->
    <div class="card">
      <div class="card-top">
        <div style="display:flex;align-items:center;gap:12px">
          <div class="brand-circle">
            <img src="assets/img/logo_circular.png" alt="Logo">
          </div>
          <div>
            <h2>Iniciar sesión</h2>
            <div class="sub">Bienvenido de vuelta</div>
          </div>
        </div>
        <a class="create-inline" href="signup.php" title="Crear cuenta">Crear cuenta</a>
      </div>

      <!-- Mensajes -->
      <?php if ($error): ?>
        <div class="alert show" id="errorBox"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($account_disabled): ?>
        <div class="alert show" style="margin-top:8px">
          La cuenta de <strong><?= htmlspecialchars($disabled_user_name ?: 'este usuario') ?></strong> está desactivada.
          <br>Contáctanos para reactivarla.
        </div>
      <?php endif; ?>

      <form method="POST" id="loginForm" novalidate>
        <div class="field">
          <div class="label">Correo</div>
          <div class="input-wrap">
            <input type="email" name="email" placeholder="tucorreo@ejemplo.com" required autocomplete="email">
          </div>
        </div>

        <div class="field">
          <div class="label">Contraseña</div>
          <div class="input-wrap">
            <input type="password" name="password" id="passwordField" placeholder="••••••••" required autocomplete="current-password">
            <button class="eye-btn" type="button" id="togglePassword" aria-label="Mostrar/Ocultar contraseña" title="Mostrar/Ocultar">
              <svg id="eyeOpen" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
              <svg id="eyeClosed" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.86 21.86 0 0 1 5.06-6.94"/>
                <path d="M1 1l22 22"/>
                <path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.86 21.86 0 0 1-5.06 6.94"/>
              </svg>
            </button>
          </div>
        </div>

        <button class="btn" type="submit">Ingresar</button>

        <div class="helpers">
          <a class="forgot" href="forgot_password.php">¿Olvidaste tu contraseña?</a>
        </div>
      </form>
    </div>
  </div>

  <div class="foot">
    <div class="foot-inner">
      <div>© <?= date('Y') ?> ShockFy</div>
      <div>Privacidad · Términos</div>
    </div>
  </div>

  <script>
    // Mantener darkMode si lo usas en otras páginas
    (function(){
      if(localStorage.getItem('darkMode') === 'true'){
        document.documentElement.classList.add('dark');
      }
    })();

    // Mostrar/ocultar contraseña
    (function(){
      const btn = document.getElementById('togglePassword');
      const input = document.getElementById('passwordField');
      const eyeOpen = document.getElementById('eyeOpen');
      const eyeClosed = document.getElementById('eyeClosed');

      btn?.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        eyeOpen.style.display = show ? 'none' : 'block';
        eyeClosed.style.display = show ? 'block' : 'none';
      });
    })();

    // Auto-ocultar alert de error después de 3.5s
    (function(){
      const box = document.getElementById('errorBox');
      if(box){
        setTimeout(()=> box.classList.remove('show'), 3500);
      }
    })();
  </script>
</body>
</html>

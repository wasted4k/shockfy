<?php
// reset_password.php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$stage = 'form'; // form | success | error
$flash = '';
$error = '';

function findEmailByToken(PDO $pdo, string $token): ?string {
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $now  = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $sql = "SELECT email 
            FROM password_resets
            WHERE token_hash = :th AND used = 0 AND expires_at > :now
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':th' => $hash, ':now' => $now]);
    $email = $st->fetchColumn();
    return $email ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Validar token
    $email = findEmailByToken($pdo, $token);
    if (!$email) {
        $stage = 'error';
        $error = 'El enlace es inválido o ha expirado. Solicita uno nuevo desde "¿Olvidaste tu contraseña?".';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    // Revalidar token en POST
    $email = findEmailByToken($pdo, $token);
    if (!$email) {
        $stage = 'error';
        $error = 'El enlace es inválido o ha expirado. Solicita uno nuevo desde "¿Olvidaste tu contraseña?".';
    } else {
        // Validaciones mínimas de contraseña
        if (strlen($password) < 8) {
            $stage = 'form';
            $flash = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($password !== $password2) {
            $stage = 'form';
            $flash = 'Las contraseñas no coinciden.';
        } else {
            // Actualizar contraseña y marcar token como usado (transacción)
           try {
    $pdo->beginTransaction();

    $hashPwd = password_hash($password, PASSWORD_DEFAULT);

    // 1) Actualiza ambos campos con backticks
    $u = $pdo->prepare("UPDATE `users` SET `password` = :p, `password_hash` = :p WHERE `email` = :e LIMIT 1");
    $u->execute([':p' => $hashPwd, ':e' => $email]);

    if ($u->rowCount() < 1) {
        // No encontró el usuario por email (raro si venías del token)
        throw new RuntimeException("No se actualizó ningún usuario (email no coincide). Email={$email}");
    }

    // 2) Marca token como usado
    $tHash = hash('sha256', $token);
    $x = $pdo->prepare("UPDATE `password_resets` SET `used` = 1 WHERE `token_hash` = :th LIMIT 1");
    $x->execute([':th' => $tHash]);

    if ($x->rowCount() < 1) {
        throw new RuntimeException("No se pudo marcar el token como usado.");
    }

    $pdo->commit();
    $stage = 'success';
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $stage = 'error';
    $error = 'Ocurrió un problema al actualizar tu contraseña. Intenta nuevamente.';

    // Si DEV_MODE=1, mostramos pista del error
    if ((int)env('DEV_MODE', 0) === 1) {
        $error .= ' [DEV] ' . $ex->getMessage();
    }
}

        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>ShockFy — Restablecer contraseña</title>
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
    /* NAV (igual a login/forgot) */
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

    /* LAYOUT */
    .wrap{max-width:var(--max);margin:0 auto;padding:26px 18px 48px;display:grid;grid-template-columns:1.1fr .9fr;gap:32px;align-items:center;flex:1}
    @media (max-width:980px){.wrap{grid-template-columns:1fr}}

    /* COPY */
    .intro h1{font-size:36px;line-height:1.08;margin:0 0 12px;font-weight:800;color:#0b2cff}
    .intro p{color:var(--muted);max-width:620px;margin:0 0 16px}

    /* CARD */
    .card{
      background:linear-gradient(180deg,#ffffff,#f7faff);
      border:1px solid #dbe4ff;border-radius:var(--radius);box-shadow:var(--shadow);
      padding:28px 24px;max-width:560px;margin:0 0 0 auto;
    }
    @media (max-width:980px){.card{margin:0 auto}}
    .card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:12px}
    .brand-circle{
      width:72px;height:72px;border-radius:50%;display:grid;place-items:center;
      background:linear-gradient(135deg,#e8efff,#f3f7ff);border:1px solid #d6e1ff;
    }
    .brand-circle img{width:40px;height:40px;object-fit:contain}
    .card h2{margin:0;font-weight:800;font-size:24px}
    .sub{color:var(--muted);font-size:13px;margin:6px 0 12px}

    .alert{display:none;margin:0 auto 12px;background:#ecfdf5;border:1px solid #d1fae5;color:#065f46;padding:10px 12px;border-radius:12px;font-weight:600;text-align:center}
    .alert.show{display:block}
    .alert.err{background:#fee2e2;border-color:#fecaca;color:#7f1d1d}

    .field{margin:12px 0 14px}
    .label{font-size:13px;font-weight:700;margin-bottom:6px}
    .input-wrap{
      position:relative;border:1px solid #dbe4ff;background:#fff;border-radius:12px;display:flex;align-items:center;padding:10px 14px;
    }
    .input-wrap:focus-within{box-shadow:0 0 0 3px rgba(35,68,236,.15)}
    input[type="password"]{
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
      box-shadow:0 12px 28px rgba(35,68,236,.18);transition:transform .15s ease, filter .2s ease;font-size:16px;
    }
    .btn:hover{filter:brightness(.95);transform:translateY(-1px)}

    .helpers{display:flex;justify-content:space-between;align-items:center;margin-top:10px}
    .link{color:var(--primary);font-weight:700;font-size:13px}
    .link:hover{text-decoration:underline}

    /* FOOTER */
    .foot{padding:12px 18px;border-top:1px solid var(--border);background:#fff;color:#64748b;font-size:13px}
    .foot-inner{max-width:var(--max);margin:0 auto;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
  </style>
</head>
<body>

  <!-- NAV -->
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
    </div>
  </nav>

  <div class="wrap">
    <!-- COPY -->
    <div class="intro">
      <h1>Restablecer contraseña</h1>
      <p>Crea una nueva contraseña para tu cuenta. Por seguridad, el enlace tiene tiempo limitado.</p>
    </div>

    <!-- CARD -->
    <div class="card">
      <div class="card-top">
        <div style="display:flex;align-items:center;gap:12px">
          <div class="brand-circle">
            <img src="assets/img/logo_circular.png" alt="Logo">
          </div>
          <div>
            <h2>Define una nueva contraseña</h2>
            <div class="sub">Mínimo 8 caracteres</div>
          </div>
        </div>
        <a class="link" href="login.php" title="Volver a iniciar sesión">Volver a login</a>
      </div>

      <?php if ($flash): ?>
        <div class="alert show <?= $stage==='form' ? 'err' : '' ?>"><?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>

      <?php if ($stage === 'error'): ?>
        <div class="alert show err"><?= htmlspecialchars($error) ?></div>
      <?php elseif ($stage === 'success'): ?>
        <div class="alert show">Tu contraseña fue actualizada correctamente.</div>
        <a class="btn" href="login.php" style="margin-top:10px">Ir a iniciar sesión</a>
      <?php else: ?>
        <form method="POST" autocomplete="off" novalidate>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

          <div class="field">
            <div class="label">Nueva contraseña</div>
            <div class="input-wrap">
              <input type="password" name="password" id="pwd1" placeholder="••••••••" required minlength="8" autocomplete="new-password">
              <button class="eye-btn" type="button" data-target="pwd1" aria-label="Mostrar/Ocultar">
                👁️
              </button>
            </div>
          </div>

          <div class="field">
            <div class="label">Confirmar contraseña</div>
            <div class="input-wrap">
              <input type="password" name="password2" id="pwd2" placeholder="••••••••" required minlength="8" autocomplete="new-password">
              <button class="eye-btn" type="button" data-target="pwd2" aria-label="Mostrar/Ocultar">
                👁️
              </button>
            </div>
          </div>

          <button class="btn" type="submit">Cambiar contraseña</button>

          <div class="helpers">
            <span class="link" onclick="document.getElementById('pwd1').value='';document.getElementById('pwd2').value='';">Borrar campos</span>
            <span style="font-size:12px;color:#64748b">Evita usar contraseñas de otros sitios.</span>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="foot">
    <div class="foot-inner">
      <div>© <?= date('Y') ?> ShockFy</div>
      <div>Privacidad · Términos</div>
    </div>
  </div>

  <script>
    // Mostrar/Ocultar contraseñas
    document.querySelectorAll('.eye-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-target');
        const input = document.getElementById(id);
        if (!input) return;
        input.type = (input.type === 'password') ? 'text' : 'password';
      });
    });
  </script>
</body>
</html>

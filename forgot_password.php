<?php
// forgot_password.php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

const GENERIC_MSG = 'Si el correo existe, enviaremos un enlace para restablecer la contraseña. Revisa tu bandeja.';

function build_app_url(): string {
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    if ($appUrl !== '') return $appUrl;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

$flash = '';  // para mostrar mensaje en la tarjeta

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    // Siempre mensaje genérico (evita enumerar correos)
    $flash = GENERIC_MSG;

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // ¿Existe usuario?
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetchColumn()) {
            // Genera token + expira
            $ttlMin    = (int) env('CODE_TTL_MIN', 15);
            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = (new DateTimeImmutable("+{$ttlMin} minutes"))->format('Y-m-d H:i:s');

            // Guarda token
            $ins = $pdo->prepare("INSERT INTO password_resets (email, token_hash, expires_at) VALUES (:email, :th, :exp)");
            $ins->execute([':email' => $email, ':th' => $tokenHash, ':exp' => $expiresAt]);

            // Enlace de reseteo
            $resetLink = build_app_url() . '/reset_password.php?token=' . urlencode($token);

            // Enviar correo con Brevo (SMTP)
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = (string) env('SMTP_HOST', 'smtp-relay.brevo.com');
                $mail->SMTPAuth   = true;
                $mail->Username   = (string) env('SMTP_USERNAME');
                $mail->Password   = (string) env('SMTP_PASSWORD');
                $mail->Port       = (int) env('SMTP_PORT', 587);

                $enc = strtolower((string) env('SMTP_ENCRYPTION', 'tls'));
                if ($enc === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    if ($mail->Port === 587) $mail->Port = 465;
                } else {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }

                if ((int) env('DEBUG_SMTP', 0) === 1) {
                    $mail->SMTPDebug = 2;
                    $mail->Debugoutput = 'error_log';
                }

                $from     = (string) env('MAIL_FROM');
                $fromName = (string) env('MAIL_FROM_NAME', 'ShockFy');

                $mail->setFrom($from, $fromName);
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'ShockFy - Restablece tu clave';

                $ttlText = htmlspecialchars((string)$ttlMin, ENT_QUOTES, 'UTF-8');
                $fromNameEsc = htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8');

                $mail->Body = "
                  <div style='font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.5'>
                    <p>Recibimos una solicitud para restablecer tu clave en <strong>{$fromNameEsc}</strong>.</p>
                    <p>
                      <a href='{$resetLink}' style='display:inline-block;padding:10px 14px;border-radius:6px;background:#1a73e8;color:#fff;text-decoration:none'>
                        Restablecer clave
                      </a>
                    </p>
                    <p>Este enlace caduca en {$ttlText} minutos. Si no fuiste tu, ignora este mensaje.</p>
                  </div>
                ";
                $mail->AltBody = "Enlace para restablecer tu contraseña (caduca en {$ttlMin} min): {$resetLink}";
                $mail->send();
            } catch (Exception $e) {
                // No exponer detalles. Si quieres, registra con error_log($mail->ErrorInfo);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>ShockFy — Recuperar contraseña</title>
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
    /* NAV igual a login.php */
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

    /* COPY lado izq */
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
    input[type="email"]{
      border:none;outline:none;background:transparent;width:100%;font-size:16px;color:#0f172a;
    }
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
    .foot{padding:12px 18px;border-top:1px solid var(--border);background:#fff;color:var(--muted);font-size:13px}
    .foot-inner{max-width:var(--max);margin:0 auto;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}

/* ======= MOBILE ≤ 640px ======= */
@media (max-width:640px){
  /* Layout general */
  .wrap{
    grid-template-columns: 1fr !important;
    padding: 20px 14px 36px;
    gap: 18px;
  }
  .intro h1{ font-size: 26px; line-height: 1.15; }
  .intro p{ font-size: 14px; }

  /* Navbar: evita desbordes mostrando solo CTAs */
  .nav-links{ gap: 8px; flex-wrap: nowrap; }
  .nav-links a{ padding: 8px 10px; }
  .nav-links a:not(.cta):not(.login-btn){ display:none; }

  /* Tarjeta compacta */
  .card{ padding: 22px 16px; border-radius: 16px; }

  /* Encabezado de tarjeta apilado */
  .card-top{
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }
  .brand-circle{ width: 64px; height: 64px; }
  .brand-circle img{ width: 36px; height: 36px; object-fit: contain; }

  /* “Volver a iniciar sesión” como botón/link ancho */
  .card-top .link{
    align-self: stretch;
    text-align: center;
    padding: 10px 12px;
    border: 1px solid #dbe4ff;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 6px 16px rgba(35,68,236,.08);
  }

  /* Campos amigables en iOS */
  input[type="email"]{ font-size:16px; }

  /* Helpers apilados y legibles */
  .helpers{
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
    margin-top: 12px;
  }
  .helpers .link{ text-align:center; }
}

/* Ultra-chico (≤360px) */
@media (max-width:360px){
  .intro h1{ font-size:22px; }
  .card h2{ font-size:20px; }
}


/* Botón "Volver a iniciar sesión" con paleta del sitio (sin subrayado) */
.card-top .link{
  display:inline-flex; align-items:center; justify-content:center;
  height:38px; padding:0 14px;
  border-radius:12px; border:1px solid #cfe0ff;
  background:linear-gradient(135deg, var(--primary), var(--primary-2));
  color:#fff !important; font-weight:800; letter-spacing:.1px;
  text-decoration:none !important;
  box-shadow:0 10px 22px rgba(35,68,236,.18);
  transition:transform .12s ease, filter .12s ease, box-shadow .12s ease;
}
.card-top .link:hover{
  filter:brightness(.96);
  transform:translateY(-1px);
  text-decoration:none !important;
}
.card-top .link:focus-visible{
  outline:2px solid #cfe0ff; outline-offset:2px;
}

/* En móvil que sea ancho completo y con padding cómodo */
@media (max-width:640px){
  .card-top .link{
    align-self:stretch;
    height:auto; padding:12px 14px;
  }
}

/* "Crear cuenta"  sin subrayado */
.helpers .link,
.helpers .link:link,
.helpers .link:visited,
.helpers .link:hover,
.helpers .link:active,
.helpers .link:focus{
  text-decoration: none !important;
}

/* Desktop: botón "Volver a iniciar sesión" un poco más grande */
@media (min-width:981px){
  .card-top .link{
    height: 56px;            /* antes ~38px */
    padding: 0 20px;         /* más aire lateral */
    font-size: 15px;         /* texto apenas mayor */
    border-radius: 14px;
    box-shadow: 0 12px 26px rgba(35,68,236,.20);
    line-height: 1;          /* centra mejor el texto verticalmente */
  }
  .card-top{ gap: 14px; }    /* un pelín más de espacio con el título */
}

/* (Opcional) estilo "pill" redondeado total en desktop */
/*
@media (min-width:981px){
  .card-top .link{ border-radius: 999px; }
}
*/


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
        
        <a href="login.php" class="login-btn">Iniciar sesión</a>
      </div>
    </div>
  </nav>

  <div class="wrap">
    <!-- COPY IZQ -->
    <div class="intro">
      <h1>¿Olvidaste tu contraseña?</h1>
      <p>Ingresa tu correo y te enviaremos un enlace seguro para restablecerla. Por tu seguridad, este enlace caduca en poco tiempo.</p>
    </div>

    <!-- CARD -->
    <div class="card">
      <div class="card-top">
        <div style="display:flex;align-items:center;gap:12px">
          <div class="brand-circle">
            <img src="assets/img/logo_circular.png" alt="Logo">
          </div>
          <div>
            <h2>Recuperar acceso</h2>
            <div class="sub">Te enviaremos un enlace de recuperación</div>
          </div>
        </div>
        <a class="link" href="login.php" title="Volver a iniciar sesión">Volver a iniciar sesion</a>
      </div>

      <!-- Mensaje -->
      <?php if ($flash): ?>
        <div class="alert show"><?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off" novalidate>
        <div class="field">
          <div class="label">Correo</div>
          <div class="input-wrap">
            <input type="email" name="email" placeholder="tucorreo@ejemplo.com" required autocomplete="email">
          </div>
        </div>

        <button class="btn" type="submit">Enviar enlace</button>

        <div class="helpers">
          <a class="link" href="signup.php">Crear cuenta</a>
          <span style="font-size:12px;color:#64748b">¿No llega el email? Revisa spam o “Promociones”.</span>
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
</body>
</html>


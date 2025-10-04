<?php
// signup.php — Página de registro (logo grande, botón "Iniciar sesión" junto al menú en azul)
session_start();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="es" data-theme="light">
<head>
   <link rel="icon" href="assets/img/favicon.png" type="image/png">
    <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
  <meta charset="utf-8">
  <title>Crear cuenta – ShockFy</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#f6f7fb; --card:#fff; --text:#0f172a; --subtext:#64748b;
      --primary:#4f46e5; --primary-700:#4338ca;
      --blue:#3b82f6; --blue-700:#2563eb;
      --border:#e5e7eb; --ring:#c7d2fe;
      --shadow:0 12px 34px rgba(2,6,23,.08);
      --radius:18px; --muted:#f1f5f9;
    }
    [data-theme="dark"]{
      --bg:#0b0f16; --card:#0f1622; --text:#e2e8f0; --subtext:#94a3b8;
      --primary:#6366f1; --primary-700:#4f46e5;
      --blue:#3b82f6; --blue-700:#2563eb;
      --border:#1f2937; --ring:#312e81;
      --shadow:0 20px 48px rgba(0,0,0,.45); --muted:#0b1320;
    }
    *{box-sizing:border-box} html,body{margin:0;padding:0}
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text)}
    .page{min-height:100dvh;display:flex;flex-direction:column}

    /* ===== Header / menú ===== */
    .site-header{
      position: sticky; top:0; z-index:50; background: var(--card);
      border-bottom: 1px solid var(--border);
      backdrop-filter: saturate(180%) blur(8px);
    }
    .site-header .header-wrap{
      max-width: 1100px; margin: 0 auto; height: 72px;
      display: grid; grid-template-columns: auto 1fr auto; align-items: center;
      gap: 16px; padding: 0 16px;
    }
    .brand{display:flex; align-items:center; gap:12px; text-decoration:none; color: inherit;}
    .brand-logo{width:40px; height:40px;}
    .brand-name{font-weight:900; letter-spacing:-.01em; font-size:1.125rem;}
    .nav{display:flex; justify-content:center; gap:24px; align-items:center}
    .nav-link{font-weight:600; text-decoration:none; color: var(--text)}
    .nav-link:hover{opacity:.85}

    /* Botón de iniciar sesión pegado al menú (azul) */
    .btn-login{
      display:inline-flex; align-items:center; justify-content:center;
      height:38px; padding:0 14px; border-radius:12px; font-weight:700;
      text-decoration:none; border:1px solid transparent;
      background: var(--blue); color:#fff;
      box-shadow:0 8px 18px rgba(59,130,246,.25);
      transition: transform .12s ease, box-shadow .12s ease, background-color .12s ease;
      margin-left: 6px; /* se siente más “pegado” al menú */
    }
    .btn-login:hover{ background: var(--blue-700); transform: translateY(-1px); box-shadow:0 12px 24px rgba(59,130,246,.35); }

    .hamb{display:none;background:none;border:0;cursor:pointer;gap:4px;padding:10px;border-radius:10px}
    .hamb span{display:block;width:22px;height:2px;background:currentColor}
    @media (max-width: 860px){
      .nav{display:none}
      .hamb{display:inline-flex}
    }

    /* ===== Contenido ===== */
    .main{flex:1;display:grid;place-items:center;padding:clamp(16px,3vw,32px)}
    .card{
      width:100%;max-width:560px;background:var(--card);
      border:1px solid var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow);padding:clamp(18px,3vw,28px);position:relative;overflow:hidden
    }
    .card::after{
      content:"";position:absolute;inset:auto -30% -40% auto;width:380px;height:380px;border-radius:999px;
      background:radial-gradient(40% 40% at 50% 50%, rgba(79,70,229,.12), transparent 60%);pointer-events:none;filter:blur(10px)
    }
    .title{margin:0 0 6px;font-weight:800;letter-spacing:-.01em;font-size:clamp(1.25rem,2.2vw,1.7rem)}
    .subtitle{margin:0 0 18px;color:var(--subtext)}
    .flash{margin:8px 0 14px;padding:10px 12px;border-radius:12px;background:#eef6ff;color:#0b5cab}
    [data-theme="dark"] .flash{background:#0b2545;color:#bcd7ff}
    .error{background:#ffecec;color:#a40000}
    [data-theme="dark"] .error{background:#3b0a0a;color:#ffb4b4}

    form{margin:0}
    .split{display:flex;gap:12px}.split .col{flex:1}
    @media (max-width:560px){.split{flex-direction:column}}

    label{display:block;font-weight:650;margin:12px 0 6px}
    .field{
      display:flex;align-items:center;gap:.6rem;background:#fff;border:1px solid var(--border);
      border-radius:12px;padding:10px 12px
    }
    [data-theme="dark"] .field{background:#0c131f}
    .field:focus-within{outline:2px solid var(--ring);outline-offset:2px;border-color:transparent}
    .icon{width:18px;height:18px;opacity:.9;flex:0 0 18px}
    .input{border:0;outline:0;background:transparent;width:100%;color:var(--text);font-size:16px}
    .hint{margin-top:6px;font-size:.9rem;color:var(--subtext)}
    .row{margin-top:12px}

    /* Select de país estético */
    .country-select{
      position:relative; display:flex; align-items:center; background:#fff;
      border:1px solid var(--border); border-radius:12px; padding:2px 12px 2px 10px;
    }
    [data-theme="dark"] .country-select{background:#0c131f}
    .country-select:focus-within{outline:2px solid var(--ring); outline-offset:2px; border-color:transparent}
    .country-select .globe{width:18px;height:18px;opacity:.9;margin-right:.5rem;flex:0 0 18px}
    .country-select select{
      appearance:none;-webkit-appearance:none;-moz-appearance:none;
      border:0; outline:0; background:transparent; width:100%; font-size:16px; color:var(--text);
      padding:10px 28px 10px 0; cursor:pointer;
    }
    .country-select .caret{
      position:absolute; right:10px; pointer-events:none; opacity:.7;
      width:16px; height:16px;
    }

    /* Botón enviar */
    .actions{margin-top:16px}
    .btn-submit{
      width:100%;padding:13px 14px;border:0;border-radius:12px;background:var(--primary);color:#fff;font-weight:800;cursor:pointer;
      box-shadow:0 10px 22px rgba(79,70,229,.28);transition:transform .12s ease, box-shadow .12s ease, background-color .12s ease
    }
    .btn-submit:hover,.btn-submit:focus-visible{background:var(--primary-700);transform:translateY(-1px);box-shadow:0 14px 30px rgba(79,70,229,.36);outline:none}
    .btn-submit:active{transform:translateY(0)} .btn-submit[disabled]{opacity:.7;cursor:not-allowed}

    /* Términos alineados */
    .tos{ display:flex; align-items:center; gap:.6rem; margin-top:10px; padding:8px 0; border-radius:10px; }
    .tos input[type="checkbox"]{ width:18px; height:18px; accent-color: var(--primary); flex:0 0 18px; margin:0; }
    .tos label{ margin:0; font-weight:500; color:var(--subtext); cursor:pointer; display:flex; align-items:center; gap:.35rem; }
    .tos a{color:var(--primary); text-decoration:none}
    .tos a:hover{text-decoration:underline}

    .footer{margin-top:14px;text-align:center;color:var(--subtext);font-size:.95rem}
    a{color:var(--primary);text-decoration:none} a:hover{text-decoration:underline}

/* signup: quitar ícono hamburguesa en todas las resoluciones */
.site-header .hamb{ display:none !important; }


/* ===== Términos y condiciones (limpio y responsive) ===== */
.tos{
  display: grid;
  grid-template-columns: 18px 1fr;   /* [☑] [texto] */
  column-gap: .6rem;
  align-items: start;
  margin-top: 10px;
  padding: 6px 0;
  border-radius: 10px;
}
.tos input[type="checkbox"]{
  width: 18px; height: 18px;
  margin: 2px 0 0;                  /* alinea con 1ª línea del texto */
  accent-color: var(--primary);
}
.tos label{
  margin: 0;
  display: block;                    /* ← quita el flex del label */
  line-height: 1.35;
  font-weight: 500;
  color: var(--subtext);
  text-wrap: pretty;                 /* mejora saltos en navegadores modernos */
}
.tos a{ color: var(--primary); text-decoration: none; }
.tos a:hover{ text-decoration: underline; }

/* Opcional: tamaño un poco menor en teléfonos muy angostos */
@media (max-width: 360px){
  .tos label{ font-size: .95rem; }
}


  </style>
</head>
<body>
<script>
  // Modo oscuro desde localStorage
  (function(){try{const t=localStorage.getItem('theme'); if(t==='dark'||t==='light') document.documentElement.setAttribute('data-theme', t);}catch(e){}})();
</script>

<div class="page">
  <!-- ======= Header / Menú ======= -->
  <header class="site-header">
    <div class="header-wrap">
      <!-- Marca -->
      <a class="brand" href="home.php" aria-label="ShockFy">
        <img src="assets/img/icono_menu.png" alt="" class="brand-logo">
        <span class="brand-name">ShockFy</span>
      </a>

      <!-- Navegación (¡botón de login aquí, pegado al menú!) -->
      <nav class="nav" aria-label="Principal">
        <a href="home.php#features" class="nav-link">Características</a>
        <a href="home.php#benefits" class="nav-link">Beneficios</a>
        <a href="home.php#pricing" class="nav-link">Precio</a>
        <a href="login.php" class="btn-login">Iniciar sesión</a>
      </nav>

      <!-- No hay acciones a la derecha; dejamos solo el toggle móvil -->
      <button class="hamb" aria-label="Abrir menú" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>
  </header>

  <main class="main">
    <section class="card" role="region" aria-labelledby="signupTitle">
      <?php if($flash): ?>
        <div class="flash <?= $flash['type']==='error' ? 'error':'' ?>" role="status" aria-live="polite">
          <?= htmlspecialchars($flash['text']) ?>
        </div>
      <?php endif; ?>

      <h1 class="title" id="signupTitle">Crea tu cuenta</h1>
      <p class="subtitle">Prueba gratis 15 días — <span style="white-space:nowrap">no se requiere tarjeta</span>.</p>

      <form action="signup_process.php" method="post" novalidate id="signupForm">
        <div class="split">
          <div class="col">
            <label for="full_name">Nombre completo</label>
            <div class="field">
              <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5 0-9 2.5-9 5.5A1.5 1.5 0 0 0 4.5 21h15A1.5 1.5 0 0 0 21 19.5C21 16.5 17 14 12 14Z"/></svg>
              <input class="input" id="full_name" name="full_name" type="text" placeholder="Tu nombre y apellido" required autocomplete="name">
            </div>
          </div>

          <div class="col">
            <label for="email">Email</label>
            <div class="field">
              <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4a2 2 0 0 0-2 2v.35l10 6.25L22 6.35V6a2 2 0 0 0-2-2Zm0 6.07-8 5-8-5V18a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2Z"/></svg>
              <input class="input" id="email" name="email" type="email" placeholder="tucorreo@ejemplo.com" required autocomplete="email" inputmode="email">
            </div>
          </div>
        </div>

        <!-- País -->
        <div class="row">
          <label for="country">País</label>
          <div class="country-select" id="countryWrap">
            <svg class="globe" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2Zm6.93 6h-3.165a15.7 15.7 0 0 0-1.59-3.63A8.03 8.03 0 0 1 18.93 8ZM12 3.07c.93 1.2 1.68 2.75 2.2 4.93H9.8c.52-2.18 1.27-3.73 2.2-4.93ZM5.07 8a8.03 8.03 0 0 1 4.755-3.63A15.7 15.7 0 0 0 8.235 8H5.07Zm0 8h3.165c.37 1.37.86 2.64 1.59 3.63A8.03 8.03 0 0 1 5.07 16ZM12 20.93c-.93-1.2-1.68-2.75-2.2-4.93h4.4c-.52 2.18-1.27 3.73-2.2 4.93ZM8.235 16a18.9 18.9 0 0 1 0-8h7.53a18.9 18.9 0 0 1 0 8h-7.53Zm10.695 0a8.03 8.03 0 0 1-4.755 3.63c.73-.99 1.22-2.26 1.59-3.63h3.165Z"/></svg>
            <select id="country" name="country" required autocomplete="country" aria-required="true">
              <option value="" selected disabled>Selecciona tu país…</option>
              <option>Perú</option><option>México</option><option>Colombia</option><option>Chile</option>
              <option>Argentina</option><option>España</option><option>Estados Unidos</option><option>Canadá</option>
              <option>Brasil</option><option>Ecuador</option><option>Bolivia</option><option>Paraguay</option>
              <option>Uruguay</option><option>Venezuela</option><option>Guatemala</option><option>Honduras</option>
              <option>El Salvador</option><option>Nicaragua</option><option>Costa Rica</option><option>Panamá</option>
              <option>República Dominicana</option><option>Puerto Rico</option><option>Otro</option>
            </select>
            <svg class="caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>
          </div>
          <div class="hint">Usaremos el país para moneda y reportes.</div>
        </div>

        <!-- Teléfono local -->
        <div class="row">
          <label for="phone_local">Teléfono</label>
          <div class="field">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.9 15.9 0 0 0 6.6 6.6l2.2-2.2a1.5 1.5 0 0 1 1.6-.36 9.9 9.9 0 0 0 3.1.5 1.5 1.5 0 0 1 1.5 1.5V20a1.5 1.5 0 0 1-1.5 1.5A17.5 17.5 0 0 1 3 7.5 1.5 1.5 0 0 1 4.5 6H7a1.5 1.5 0 0 1 1.5 1.5 9.9 9.9 0 0 0 .5 3.1 1.5 1.5 0 0 1-.36 1.6Z"/></svg>
            <input class="input" id="phone_local" name="phone" type="tel" placeholder="Tu número local" required autocomplete="tel"
                   inputmode="tel" pattern="^[0-9()\s\-\.]{6,20}$" aria-describedby="phoneHelp">
          </div>
          <div class="hint" id="phoneHelp">Ingresa tu numero de telefono. Puedes incluir el codigo del pais.</div>
        </div>

        <!-- Contraseña -->
        <div class="row">
          <label for="password">Contraseña</label>
          <div class="field">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 9V7a5 5 0 0 0-10 0v2H5v12h14V9Zm-8 0V7a3 3 0 0 1 6 0v2Z"/></svg>
            <input class="input" id="password" name="password" type="password" placeholder="Mínimo 6 caracteres" minlength="6" required autocomplete="new-password" aria-describedby="pwHelp">
            <span class="pw-toggle" id="pwToggle" role="button" tabindex="0" aria-controls="password" aria-pressed="false">Mostrar</span>
          </div>
          <div class="hint" id="pwHelp">Al menos 6 caracteres.</div>
        </div>

        <!-- Términos -->
        <div class="tos">
          <input id="tos" type="checkbox" required>
          <label for="tos">Acepto los <a href="terminos.php"; >Términos</a> y la <a href="#" onclick="return false;">Política de Privacidad</a>.</label>
        </div>

        <div class="actions">
          <button type="submit" class="btn-submit" id="submitBtn">Crear cuenta y empezar prueba</button>
        </div>

        <div class="footer">¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></div>
      </form>
    </section>
  </main>
</div>

<!-- JS del header (móvil) + UX del formulario -->
<script>
(function(){
  // Menú móvil
  const hamb = document.querySelector('.site-header .hamb');
  const nav  = document.querySelector('.site-header .nav');
  if(hamb && nav){
    hamb.addEventListener('click', ()=>{
      const open = hamb.getAttribute('aria-expanded') === 'true';
      hamb.setAttribute('aria-expanded', String(!open));
      if (getComputedStyle(nav).display === 'none') {
        nav.style.display = 'flex';
      } else if (getComputedStyle(nav).position === 'absolute') {
        nav.style.display = open ? 'flex' : 'none';
      }
    });
    nav.addEventListener('click', e=>{
      if(e.target.matches('a')){ hamb.setAttribute('aria-expanded','false'); if (getComputedStyle(nav).position==='absolute') nav.style.display='none'; }
    });
  }

  // UX formulario
  const form = document.getElementById('signupForm');
  const btn  = document.getElementById('submitBtn');
  const pw   = document.getElementById('password');
  const tg   = document.getElementById('pwToggle');

  function updateBtn(){ btn.disabled = !form.checkValidity(); }
  updateBtn(); form.addEventListener('input', updateBtn); form.addEventListener('change', updateBtn);

  function togglePw(){
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    tg.textContent = show ? 'Ocultar' : 'Mostrar';
    tg.setAttribute('aria-pressed', String(show));
  }
  tg.addEventListener('click', togglePw);
  tg.addEventListener('keydown', e => { if(e.key==='Enter'||e.key===' '){ e.preventDefault(); togglePw(); } });

  form.addEventListener('submit', function(e){
    if(!form.checkValidity()){
      e.preventDefault();
      alert('Revisa nombre, email válido, país, teléfono y contraseña (6+), y acepta los Términos.');
    }
  });
})();
</script>
</body>
</html>

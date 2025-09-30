<?php
// trial_expired.php (premium, sin modal ni botón "Ver límites Free")
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$next = $_GET['next'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Tu prueba ha finalizado — ShockFy</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">
  <style>
    /* ======== Design Tokens (solo light) ======== */
    :root{
      --sidebar-w:260px; /* mantener en sync con sidebar.php */

      --bg: #f6f7fb;
      --bg-accent: #eef1f8;
      --card: #ffffff;
      --text: #0f172a;
      --muted: #6b7280;
      --border: #e7e8ee;
      --radius: 18px;
      --shadow: 0 20px 40px rgba(2, 6, 23, .06);

      --primary: #2383d6;
      --primary-strong: #176bb5;
      --primary-soft: rgba(35,131,214,.12);

      --accent: #FFD166;
      --accent-soft: rgba(255,209,102,.25);
    }

    *{ box-sizing: border-box; }
    html, body{ height:100%; }
    body{
      margin:0; color:var(--text);
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
      background:
        radial-gradient(1200px 600px at 80% -10%, rgba(35,131,214,.08), transparent 60%),
        linear-gradient(180deg, var(--bg) 0%, var(--bg-accent) 100%);
      -webkit-font-smoothing: antialiased;  -moz-osx-font-smoothing: grayscale;
    }

    /* grid sutil tipo “pattern” */
    body::before{
      content:"";
      position:fixed; inset:0; pointer-events:none;
      background-image:
        linear-gradient(to right, rgba(15,23,42,.06) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(15,23,42,.04) 1px, transparent 1px);
      background-size: 28px 28px, 28px 28px;
      opacity:.35;
    }

    .app-shell{ display:flex; min-height:100vh; }
    .content{ flex:1; padding:32px 24px 56px; }
    .with-fixed-sidebar{ margin-left:var(--sidebar-w); }

    .container{ max-width:880px; margin:0 auto; }
    .wrap{
      min-height: calc(100vh - 64px);
      display:grid; place-items:center;
      padding: min(6vw, 48px);
      position:relative;
    }

    /* ======== Blobs decorativos ======== */
    .blob{
      position:absolute; filter: blur(30px); opacity:.35; z-index:0;
      border-radius:50%;
      will-change: transform;
      animation: float 12s ease-in-out infinite;
    }
    .blob.blue{ width:340px; height:340px; background: var(--primary-soft); top:-60px; right:6%; animation-delay: 0.2s; }
    .blob.yellow{ width:300px; height:300px; background: var(--accent-soft); bottom:-40px; left:8%; animation-delay: 0.8s; }

    @keyframes float{
      0%,100%{ transform: translateY(0) translateX(0) scale(1); }
      50%{ transform: translateY(18px) translateX(-8px) scale(1.03); }
    }

    /* ======== Card ======== */
    .card{
      position:relative;
      width:100%;
      background: color-mix(in oklab, var(--card) 86%, white);
      border:1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: clamp(24px, 3.2vw, 40px);
      overflow:hidden;
      isolation:isolate;
      z-index:1;
      backdrop-filter: saturate(1.05);
    }
    .card::after{
      content:"";
      position:absolute; inset:-2px;
      background: radial-gradient(600px 200px at 10% -10%, var(--primary-soft), transparent 60%);
      z-index:0; pointer-events:none;
    }

    .header{
      display:flex; flex-direction:column; align-items:center; text-align:center; gap:12px; position:relative; z-index:1;
    }
    .badge{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 12px; font-weight:800; font-size:12px; letter-spacing:.02em;
      color: var(--primary-strong); background: var(--primary-soft);
      border:1px solid color-mix(in oklab, var(--primary-strong) 24%, transparent);
      border-radius: 999px;
    }
    .badge svg{ flex:0 0 auto; }

    h1{
      margin:6px 0 0; font-size: clamp(22px, 2.6vw, 28px);
      line-height:1.15; font-weight:800; letter-spacing:-0.015em;
    }
    .lead{
      margin: 8px 0 0; color: var(--muted); font-size: 15px;
      max-width: 56ch;
    }

    /* ======== Actions ======== */
    .actions{
      margin-top: 22px;
      display:flex; gap:12px; justify-content:center; flex-wrap:wrap;
    }
    .btn{
      --ring: transparent;
      display:inline-flex; align-items:center; gap:10px;
      padding: 12px 18px; border-radius: 14px;
      text-decoration:none; font-weight:800; letter-spacing:.01em;
      border:1px solid transparent; position:relative; z-index:1;
      transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease, border-color .18s ease;
      outline: none; cursor:pointer;
    }
    .btn:focus-visible{ box-shadow: 0 0 0 6px var(--ring); }
    .btn svg{ flex:0 0 auto; }

    .btn.primary{
      background: linear-gradient(180deg, color-mix(in oklab, var(--primary) 96%, white), var(--primary));
      color:#fff;
      border-color: color-mix(in oklab, var(--primary) 60%, black 10%);
      --ring: color-mix(in oklab, var(--primary) 28%, transparent);
    }
    .btn.primary:hover{
      transform: translateY(-1px);
      background: linear-gradient(180deg, var(--primary), var(--primary-strong));
      box-shadow: 0 10px 22px rgba(2,6,23,.10);
    }

    .btn.secondary{
      background: transparent;
      color: var(--text);
      border-color: var(--border);
      --ring: color-mix(in oklab, var(--text) 12%, transparent);
    }
    .btn.secondary:hover{
      transform: translateY(-1px);
      background: color-mix(in oklab, var(--card) 70%, var(--border));
      box-shadow: 0 8px 18px rgba(2,6,23,.06);
    }

    .sub{
      margin-top: 12px; font-size: 12px; color: var(--muted); text-align:center;
    }

    /* ======== Pills/Highlights ======== */
    .highlights{
      margin-top: 18px; display:flex; gap:8px; flex-wrap:wrap; justify-content:center;
    }
    .pill{
      display:inline-flex; align-items:center; gap:8px; padding:8px 12px;
      font-size:12px; color:#111827; background: #fff; border:1px solid var(--border); border-radius:999px;
      box-shadow: 0 6px 12px rgba(2,6,23,.04);
    }
    .pill .dot{ width:8px; height:8px; border-radius:50%; background: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }

    /* ======== Accessibility ======== */
    @media (prefers-reduced-motion: reduce){
      .btn, .btn:hover{ transition: none; transform:none; }
      .blob{ animation: none; }
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="content with-fixed-sidebar" role="main">
      <div class="container">
        <div class="wrap">
          <!-- blobs -->
          <span class="blob blue" aria-hidden="true"></span>
          <span class="blob yellow" aria-hidden="true"></span>

          <section class="card" aria-labelledby="trial-expired-title">
            <header class="header">
              <span class="badge" aria-label="Prueba finalizada">
                <!-- Ícono candado -->
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M7 10V7a 5 5 0 1 1 10 0v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  <rect x="5" y="10" width="14" height="10" rx="2.5" stroke="currentColor" stroke-width="2"/>
                </svg>
                Prueba finalizada
              </span>
              <h1 id="trial-expired-title">Tu prueba gratuita ha finalizado</h1>
              <p class="lead">
                Activa un plan para seguir usando ShockFy. Mientras no tengas un plan activo, el acceso a inventario y otros módulos permanece temporalmente bloqueado.
              </p>
            </header>

            <div class="actions" role="group" aria-label="Acciones disponibles">
              <a id="goBilling" class="btn primary" href="billing.php" aria-label="Ir a planes y activar">
                <!-- Ícono relámpago -->
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                </svg>
                Ir a planes y activar
              </a>

              <a class="btn secondary" href="logout.php" aria-label="Cerrar sesión">
                <!-- Ícono salir -->
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M10 17l5-5-5-5M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  <path d="M21 4v16a2 2 0 0 1-2 2H12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                Cerrar sesión
              </a>
            </div>

            <p class="sub">Al activar <strong>Plan Pro</strong>, volverás a tener acceso a todas las funciones y tu inventario.</p>

            <div class="highlights" aria-hidden="true">
              <span class="pill"><span class="dot"></span> Inventario simple y potente</span>
              <span class="pill"><span class="dot"></span> Reportes claros</span>
              <span class="pill"><span class="dot"></span> Soporte humano</span>
            </div>

            <!-- Retorno automático tras activar (opcional) -->
            <form action="billing_activate.php" method="POST" style="display:none;">
              <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            </form>
          </section>
        </div>
      </div>
    </main>
  </div>

  <script>
    // ======== Confetti sutil al ir a billing + atajo "B" ========
    (function(){
      const billingBtn = document.getElementById('goBilling');

      function confettiBurst(el){
        const rect = el.getBoundingClientRect();
        const cx = rect.left + rect.width/2;
        const cy = rect.top + rect.height/2 + window.scrollY;

        const colors = ['#2383d6','#51a9ff','#FFD166','#7dd3fc'];
        for(let i=0;i<18;i++){
          const p = document.createElement('span');
          const size = 6 + Math.random()*6;
          p.style.position = 'absolute';
          p.style.width = p.style.height = size + 'px';
          p.style.borderRadius = '50%';
          p.style.background = colors[i % colors.length];
          p.style.left = (cx - size/2) + 'px';
          p.style.top = (cy - size/2) + 'px';
          p.style.pointerEvents = 'none';
          p.style.zIndex = 9999;
          document.body.appendChild(p);

          const angle = Math.random()*2*Math.PI;
          const dist = 40 + Math.random()*80;
          const tx = Math.cos(angle)*dist;
          const ty = Math.sin(angle)*dist - 20;

          p.animate([
            { transform: 'translate(0,0)', opacity: 1 },
            { transform: `translate(${tx}px, ${ty}px)`, opacity: 0 }
          ], { duration: 650 + Math.random()*250, easing: 'cubic-bezier(.2,.8,.2,1)' })
          .onfinish = () => p.remove();
        }
      }

      billingBtn?.addEventListener('click', function(){
        confettiBurst(this); // corre antes de abandonar la página
      });

      // Atajo B para abrir billing
      document.addEventListener('keydown', (ev)=>{
        if (ev.key.toLowerCase() === 'b' && !ev.metaKey && !ev.ctrlKey && !ev.altKey){
          const href = billingBtn?.getAttribute('href') || 'billing.php';
          window.location.href = href;
        }
      });
    })();
  </script>
</body>
</html>

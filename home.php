<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// ¿Hay usuario logueado?
$isLogged = !empty($_SESSION['user_id']);

// De dónde sacar el nombre para mostrar
$displayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['email'] ?? 'Mi cuenta';

/* 
// (Opcional) Si tu login no guarda fullname/username/email en la sesión, 
// puedes cargarlo desde la BD descomentando esto:
// require_once __DIR__ . '/db.php';
// if ($isLogged) {
//   $stmt = $pdo->prepare("SELECT full_name, username, email FROM users WHERE id = :id LIMIT 1");
//   $stmt->execute(['id' => $_SESSION['user_id']]);
//   if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
//     $displayName = $u['full_name'] ?: ($u['username'] ?: ($u['email'] ?: 'Mi cuenta'));
//   }
// }
*/
?>


<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ShockFy — Control de Ventas e Inventario</title>
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8ff;
      --panel:#ffffff;
      --text:#0b1220;
      --muted:#475569;
      --primary:#2344ec;
      --primary-2:#5ea4ff;
      --ok:#16a34a;
      --warn:#f59e0b;
      --danger:#ef4444;
      --border:#e5e7eb;
      --shadow:0 18px 40px rgba(2,6,23,.10);
      --radius:22px;
      --max:1180px;
    }
    *{box-sizing:border-box}
    html,body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:var(--text);background:var(--bg)}

    /* ===== NAV ===== */
    nav{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.86);backdrop-filter:blur(10px);border-bottom:1px solid var(--border)}
    .nav-wrap{max-width:var(--max);margin:0 auto;padding:14px 18px;display:flex;align-items:center;justify-content:space-between}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;letter-spacing:.2px}
    .brand img{height:34px;width:auto;display:block} /* TU LOGO ORIGINAL */
    .nav-links{display:flex;gap:10px;align-items:center}
    .nav-links a{padding:10px 12px;border-radius:10px;text-decoration:none;color:#0f172a;font-weight:600}
    .nav-links a:hover{background:#1874ED}
    .cta{background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff !important;border:1px solid #cfe0ff;box-shadow:var(--shadow);text-decoration:none;}
    .cta:hover{filter:brightness(.95)}
    .login{background:#fff;border:1px solid var(--border);text-decoration:none;}

    /* ===== HERO ===== */
    .hero{position:relative;overflow:hidden}
    .hero-inner{max-width:var(--max);margin:0 auto;padding:70px 18px 52px;display:grid;grid-template-columns:1.1fr .9fr;gap:28px;align-items:center}
    @media (max-width:980px){.hero-inner{grid-template-columns:1fr;padding-top:42px}}
    .title{font-size:44px;line-height:1.06;margin:0 0 12px;font-weight:800}
    .title strong{background:linear-gradient(135deg,var(--primary),#7aa8ff);-webkit-background-clip:text;background-clip:text;color:transparent}
    .subtitle{color:var(--muted);font-size:16px;max-width:620px}
    .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
    .btn{padding:12px 16px;border-radius:12px;border:1px solid #cfe0ff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;gap:8px}
    .btn.primary{background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff;box-shadow:0 12px 28px rgba(35,68,236,.18)}
    .btn.primary:hover{filter:brightness(.95)}
    .btn.ghost{background:#fff;color:var(--primary)}
    .bullets{display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:560px;margin-top:14px}
    .bullet{display:flex;align-items:center;gap:10px}
    .tick{width:20px;height:20px;color:var(--ok)}
    .blobs{position:absolute;inset:-10% -10% auto -10%;height:420px;z-index:-1;filter:blur(30px);opacity:.45}
    .blob{position:absolute;border-radius:50%}
    .blob.a{width:480px;height:480px;background:#cfe4ff;left:-5%;top:-8%}
    .blob.b{width:380px;height:380px;background:#e7e8ff;right:-6%;top:-6%}
    .blob.c{width:320px;height:320px;background:#d9fff5;left:30%;top:-10%}

    /* ===== MOCK DASHBOARD ===== */
    .mock{
      background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow);padding:18px;position:relative;overflow:hidden
    }
    .mock-bar{display:flex;gap:8px;margin-bottom:12px}
    .dot{width:10px;height:10px;border-radius:50%}
    .dot.r{background:#ff8b8b}.dot.y{background:#ffd37b}.dot.g{background:#8bffb3}
    .mock-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px}
    .mcard{border:1px solid var(--border);border-radius:14px;padding:14px;background:linear-gradient(180deg,#ffffff,#f7faff)}
    .mcard h5{margin:0 0 6px;font-size:13px;color:#334155}
    .mcard .val{font-size:20px;font-weight:800}
    .mcard .val.ok{color:var(--ok)} .mcard .val.warn{color:var(--warn)}

    /* SVG Chart container */
    .chart{
      border:1px dashed #d7def7;border-radius:14px;padding:10px;background:#ffffff;
    }
    @keyframes drawLine { from { stroke-dashoffset: 600; } to { stroke-dashoffset: 0; } }
    @keyframes dot { to { opacity:1; } }
    .line-path { stroke-dasharray: 600; stroke-dashoffset: 600; animation: drawLine 1.8s ease forwards; }
    .dot-appear { opacity:0; animation: dot .9s ease forwards; }

    /* ===== SECCIONES BASE ===== */
    .section{padding:42px 18px}
    .features{max-width:var(--max);margin:0 auto}
    .sec-title{font-size:28px;margin:0 0 8px;font-weight:800;text-align:center}
    .sec-sub{color:var(--muted);text-align:center;max-width:820px;margin:0 auto 22px}

    /* ===== TRUST STRIP ===== */
    .trust{max-width:var(--max);margin:0 auto 10px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
    @media (max-width:980px){.trust{grid-template-columns:1fr 1fr}}
    @media (max-width:560px){.trust{grid-template-columns:1fr}}
    .trust-item{background:#ffffff;border:1px solid var(--border);border-radius:14px;padding:14px;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow)}
    .trust-item .ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#eef4ff;border:1px solid #dce6ff;color:var(--primary)}
    .trust-item span{font-weight:700}

    /* ===== BENEFICIOS 3 COL ===== */
    .grid-3{max-width:var(--max);margin:0 auto;display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
    @media (max-width:980px){.grid-3{grid-template-columns:1fr}}
    .benefit{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:18px;box-shadow:var(--shadow)}
    .benefit h4{margin:0 0 6px}
    .benefit p{margin:6px 0 0;color:var(--muted)}

    /* ===== TESTIMONIOS ===== */
    .testimonials{max-width:var(--max);margin:0 auto;display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
    @media (max-width:980px){.testimonials{grid-template-columns:1fr}}
    .tcard{background:#fff;border:1px solid var(--border);border-radius:16px;padding:18px;box-shadow:var(--shadow)}
    .t-top{display:flex;align-items:center;gap:10px;margin-bottom:8px}
    .avatar{width:36px;height:36px;border-radius:50%;background:#e7eeff;color:#2344ec;display:grid;place-items:center;font-weight:800}
    .t-name{font-weight:800}
    .t-rol{font-size:12px;color:var(--muted)}

    /* ===== PRICING ===== */
    .pricing{max-width:var(--max);margin:0 auto;display:grid;grid-template-columns:1fr;gap:12px}
    .plan{background:linear-gradient(135deg,#fff,#f7faff);border:1px solid #dbe4ff;border-radius:18px;box-shadow:var(--shadow);padding:22px;text-align:center}
    .price{font-size:40px;font-weight:800;margin:6px 0}
    .price small{font-size:14px;color:var(--muted)}
    .plist{display:grid;gap:8px;max-width:520px;margin:10px auto 16px;text-align:left}
    .plist .row{display:flex;gap:8px}
    .plist .row svg{color:var(--ok)}

    /* ===== FAQ ===== */
    .faq{max-width:var(--max);margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width:980px){.faq{grid-template-columns:1fr}}
    .qa{background:#fff;border:1px solid var(--border);border-radius:14px;padding:14px;box-shadow:var(--shadow)}
    .qa h4{margin:0;font-size:16px;display:flex;justify-content:space-between;align-items:center;cursor:pointer}
    .qa p{margin:8px 0 0;color:var(--muted);display:none}
    .qa.open p{display:block}
    .qa .caret{transition:transform .2s ease}
    .qa.open .caret{transform:rotate(180deg)}

    /* ===== CTA FINAL ===== */
    .cta-final{max-width:var(--max);margin:26px auto 0;background:linear-gradient(135deg,#2344ec,#5ea4ff);border-radius:22px;color:#fff;padding:26px;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center;box-shadow:var(--shadow)}
    @media (max-width:980px){.cta-final{grid-template-columns:1fr}}
    .cta-final a{background:#fff;color:#2344ec;text-decoration:none;padding:12px 16px;border-radius:12px;font-weight:800;border:1px solid #cfe0ff}
    .cta-final a:hover{filter:brightness(.95)}

    /* ===== STATS ===== */
    .stats{max-width:var(--max);margin:0 auto;display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
    @media (max-width:980px){.stats{grid-template-columns:1fr 1fr}}
    @media (max-width:560px){.stats{grid-template-columns:1fr}}
    .stat{background:linear-gradient(135deg,#ffffff,#f7faff);border:1px solid #dbe4ff;border-radius:18px;padding:18px;text-align:center;box-shadow:var(--shadow)}
    .stat .n{font-size:26px;font-weight:800;margin-bottom:4px}
    .stat .l{color:var(--muted);font-size:13px}

    /* ===== FOOTER ===== */
    footer{margin-top:40px;border-top:1px solid var(--border);background:#fff}
    .foot{max-width:var(--max);margin:0 auto;padding:18px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;color:var(--muted);font-size:13px}
    .links{display:flex;gap:12px}
    .links a{text-decoration:none;color:var(--muted)}
    .links a:hover{text-decoration:underline}

     .nav-user{
  padding:10px 12px;
  border-radius:10px;
  background:#fff;
  border:1px solid var(--border);
  font-weight:700;
}


    /* === Responsive nav enhancements (non-intrusive) === */
    .nav-toggle{ display:none; }
    .nav-burger{ display:none; }

    @media (max-width:980px){
      .nav-wrap{ position:relative; }
      .nav-burger{
        display:inline-flex;
        flex-direction:column;
        gap:6px;
        padding:8px;
        border:1px solid var(--border);
        border-radius:12px;
        cursor:pointer;
        user-select:none;
      }
      .nav-burger span{
        display:block;
        width:22px;
        height:2px;
        background: var(--text);
        transition: transform .2s ease, opacity .2s ease;
      }
      /* Mobile menu panel */
      .nav-links{
        position:absolute;
        top:100%;
        left:0;
        right:0;
        background:rgba(255,255,255,.9);
        backdrop-filter:blur(10px);
        border:1px solid var(--border);
        border-radius:14px;
        margin-top:8px;
        padding:12px 16px;
        display:grid;
        gap:10px;
        max-height:0;
        overflow:hidden;
        transition:max-height .25s ease;
      }
      #nav-toggle:checked ~ .nav-links{ max-height:420px; }
      /* Animate the burger into an X */
      #nav-toggle:checked + .nav-burger span:nth-child(1){ transform: translateY(8px) rotate(45deg); }
      #nav-toggle:checked + .nav-burger span:nth-child(2){ opacity:0; }
      #nav-toggle:checked + .nav-burger span:nth-child(3){ transform: translateY(-8px) rotate(-45deg); }

      .nav-links a{ display:block; padding:10px 12px; }
      .nav-links .cta, .nav-links .login{ text-align:center; }
    }

    /* Ultra-small phones */
    @media (max-width:400px){
      .title{ font-size:24px; }
    }

    /* Fluid media (keeps images and videos inside containers) */
    img, video{ max-width:100%; height:auto; }

    /* Optional: wrap tables in .table-wrap to prevent overflow */
    .table-wrap{ overflow-x:auto; }
    table{ width:100%; border-collapse:collapse; }


/* === FIX: ocultar por completo el panel del menú cuando está colapsado === */
@media (max-width: 980px){
  /* Estado colapsado (checkbox no marcado) */
  #nav-toggle ~ #nav-menu{
    max-height: 0;
    padding: 0;              /* quita espacio interno */
    margin-top: 0;           /* quita separación bajo el header */
    border-width: 0;         /* evita que el borde se vea como una línea/pastilla */
    background: transparent; /* evita “parche” blanco */
    box-shadow: none;        /* por si había sombra */
    opacity: 0;              /* por si hay efectos de fondo translúcido */
    overflow: hidden;
  }

  /* Estado expandido (checkbox marcado) */
  #nav-toggle:checked ~ #nav-menu{
    max-height: 420px;
    padding: 12px 16px;
    margin-top: 8px;
    border-width: 1px;
    background: rgba(255,255,255,.92);
    box-shadow: var(--shadow, 0 10px 24px rgba(0,0,0,.06));
    opacity: 1;
  }
}




  </style>
</head>
<body>

  <!-- NAV -->
<nav>
  <div class="nav-wrap">
    <div class="brand">
      <img src="assets/img/icono_menu.png" alt="ShockFy Logo">
      ShockFy
    </div>
    <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-label="Abrir menú" />
    <label for="nav-toggle" class="nav-burger" aria-label="Abrir menú" aria-controls="nav-menu">
      <span></span><span></span><span></span>
    </label>
    <div class="nav-links" id="nav-menu">
      <a href="#features">Características</a>
      <a href="#beneficios">Beneficios</a>
      <a href="#precios">Precio</a>

      <?php if ($isLogged): ?>
        <span class="nav-user">👋 <?= htmlspecialchars($displayName) ?></span>
        <a class="cta" href="index.php">Mi cuenta</a>
      <?php else: ?>
        <a class="cta" href="signup.php">Pruébalo gratis</a>
        <a class="login" href="login.php">Iniciar sesión</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

  <!-- HERO -->
  <section class="hero">
    <div class="blobs">
      <span class="blob a"></span>
      <span class="blob b"></span>
      <span class="blob c"></span>
    </div>

    <div class="hero-inner">
      <div>
        <h1 class="title">Controla tus <strong>ventas</strong> e <strong>inventario</strong> con confianza.</h1>
        <p class="subtitle">Registra ventas en segundos, visualiza métricas en tiempo real y mantén tu stock bajo control. Simple, rápido y listo para crecer contigo.</p>
        <div class="actions">
          <a class="btn primary cta" href="signup.php">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M5 13l4 4L19 7l-4-4z"/><path d="M15 7l-1.5 1.5"/><path d="M7 15l-1.5 1.5"/><path d="M5 19l2-2"/>
            </svg>
            Empieza gratis 15 días
          </a>
          <a class="btn ghost" href="login.php">Ver demo</a>
        </div>
        <div class="bullets">
          <div class="bullet">
            <svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
            Sin tarjetas, cancela cuando quieras
          </div>
          <div class="bullet">
            <svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
            Soporte humano y rápido
          </div>
          <div class="bullet">
            <svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
            Multi-moneda
          </div>
          <div class="bullet">
            <svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
            Enfocado en velocidad
          </div>
        </div>
      </div>

      <!-- Mock dashboard con GRÁFICO SVG ANIMADO -->
      <div class="mock" aria-hidden="true">
        <div class="mock-bar">
          <span class="dot r"></span><span class="dot y"></span><span class="dot g"></span>
        </div>

        <div class="mock-cards">
          <div class="mcard">
            <h5>Ventas del mes</h5>
            <div class="val ok">$ 12,450</div>
          </div>
          <div class="mcard">
            <h5>Ganancia</h5>
            <div class="val">$ 3,920</div>
          </div>
          <div class="mcard">
            <h5>Inventario</h5>
            <div class="val warn">1,284</div>
          </div>
        </div>

        <div class="chart">
          <svg viewBox="0 0 360 200" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" aria-label="Gráfico de ejemplo">
            <defs>
              <pattern id="grid" width="36" height="36" patternUnits="userSpaceOnUse">
                <path d="M 36 0 L 0 0 0 36" fill="none" stroke="#eef2ff" stroke-width="1"/>
              </pattern>
            </defs>
            <rect x="0" y="0" width="360" height="200" fill="url(#grid)"/>
            <line x1="30" y1="10" x2="30" y2="180" stroke="#c7d2fe" stroke-width="1.5"/>
            <line x1="30" y1="180" x2="340" y2="180" stroke="#c7d2fe" stroke-width="1.5"/>

            <g fill="#cfe4ff" stroke="#a7c7ff" stroke-width="1">
              <rect x="55"  y="110" width="16" height="70">
                <animate attributeName="height" from="0" to="70" dur="0.7s" fill="freeze"/>
                <animate attributeName="y" from="180" to="110" dur="0.7s" fill="freeze"/>
              </rect>
              <rect x="95"  y="85" width="16" height="95">
                <animate attributeName="height" from="0" to="95" dur="0.8s" fill="freeze" begin="0.05s"/>
                <animate attributeName="y" from="180" to="85" dur="0.8s" fill="freeze" begin="0.05s"/>
              </rect>
              <rect x="135" y="125" width="16" height="55">
                <animate attributeName="height" from="0" to="55" dur="0.7s" fill="freeze" begin="0.1s"/>
                <animate attributeName="y" from="180" to="125" dur="0.7s" fill="freeze" begin="0.1s"/>
              </rect>
              <rect x="175" y="60" width="16" height="120">
                <animate attributeName="height" from="0" to="120" dur="0.85s" fill="freeze" begin="0.15s"/>
                <animate attributeName="y" from="180" to="60" dur="0.85s" fill="freeze" begin="0.15s"/>
              </rect>
              <rect x="215" y="90" width="16" height="90">
                <animate attributeName="height" from="0" to="90" dur="0.8s" fill="freeze" begin="0.2s"/>
                <animate attributeName="y" from="180" to="90" dur="0.8s" fill="freeze" begin="0.2s"/>
              </rect>
              <rect x="255" y="115" width="16" height="65">
                <animate attributeName="height" from="0" to="65" dur="0.75s" fill="freeze" begin="0.25s"/>
                <animate attributeName="y" from="180" to="115" dur="0.75s" fill="freeze" begin="0.25s"/>
              </rect>
              <rect x="295" y="80" width="16" height="100">
                <animate attributeName="height" from="0" to="100" dur="0.85s" fill="freeze" begin="0.3s"/>
                <animate attributeName="y" from="180" to="80" dur="0.85s" fill="freeze" begin="0.3s"/>
              </rect>
            </g>

            <polyline class="line-path" fill="none" stroke="#4f7cff" stroke-width="2.5"
                      points="40,150 80,120 120,135 160,100 200,115 240,125 280,95 320,105" />

            <g fill="#2344ec" stroke="#fff" stroke-width="1.5">
              <circle class="dot-appear" style="animation-delay:.2s" cx="80" cy="120" r="3.8"/>
              <circle class="dot-appear" style="animation-delay:.35s" cx="120" cy="135" r="3.8"/>
              <circle class="dot-appear" style="animation-delay:.5s" cx="160" cy="100" r="3.8"/>
              <circle class="dot-appear" style="animation-delay:.65s" cx="200" cy="115" r="3.8"/>
              <circle class="dot-appear" style="animation-delay:.8s" cx="240" cy="125" r="3.8"/>
              <circle class="dot-appear" style="animation-delay:.95s" cx="280" cy="95" r="3.8"/>
              <circle class="dot-appear" style="animation-delay:1.1s" cx="320" cy="105" r="3.8"/>
            </g>

            <text x="34" y="190" fill="#94a3b8" font-size="10">0</text>
            <text x="6" y="145" fill="#94a3b8" font-size="10">25</text>
            <text x="6" y="110" fill="#94a3b8" font-size="10">50</text>
            <text x="6" y="75"  fill="#94a3b8" font-size="10">75</text>
            <text x="0" y="40"  fill="#94a3b8" font-size="10">100</text>
          </svg>
        </div>
      </div>
    </div>
  </section>

  <!-- TRUST -->
  <section class="section" style="padding-top:8px">
    <div class="features">
      <div class="trust">
        <div class="trust-item">
          <div class="ic">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2344ec" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="m9 12 2 2 4-4"/><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0"/>
            </svg>
          </div><span>SSL y backups diarios</span>
        </div>
        <div class="trust-item">
          <div class="ic">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2344ec" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 3h18v14H3z"/><path d="M3 17l6-6 4 4 2-2 6 6"/>
            </svg>
          </div><span>Uptime 99.9%</span>
        </div>
        <div class="trust-item">
          <div class="ic">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2344ec" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 2v20"/><path d="M2 12h20"/>
            </svg>
          </div><span>Multi-moneda</span>
        </div>
        <div class="trust-item">
          <div class="ic">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2344ec" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 21v-7a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v7"/><path d="M7 8V6a5 5 0 0 1 10 0v2"/>
            </svg>
          </div><span>Privacidad ante todo</span>
        </div>
      </div>
    </div>
  </section>

  <!-- BENEFICIOS AMPLIADOS -->
  <section class="section" id="beneficios">
    <h2 class="sec-title">Beneficios clave</h2>
    <p class="sec-sub">La herramienta justa: ni más ni menos. Enfocada en que vendas mejor, sin burocracia de software.</p>
    <div class="grid-3">
      <div class="benefit">
        <h4>Sistema simple</h4>
        <p>Nuestro sistema es simple y completo. No tengas problemas de configuración</p>
      </div>
      <div class="benefit">
        <h4>Interfaz ultra rápida</h4>
        <p>Todo se siente inmediato: búsqueda, filtros y ventas. Ahorra tiempo cada día.</p>
      </div>
      <div class="benefit">
        <h4>Soporte humano</h4>
        <p>Estamos disponibles para ayudarte a migrar datos y resolver dudas sin rodeos.</p>
      </div>
    </div>
  </section>

  <!-- MÉTRICAS -->
  <section class="section" id="about" style="padding-top:10px">
    <div class="features">
      <h2 class="sec-title">Elegido por cientos de emprendedores</h2>
      <p class="sec-sub">Nuestra prioridad es que vendas. La tecnología no debe estorbarte, sino empujarte.</p>
    </div>
    <div class="stats">
      <div class="stat"><div class="n">746</div><div class="l">Usuarios activos al mes</div></div>
      <div class="stat"><div class="n">$116k+</div><div class="l">Ventas procesadas</div></div>
      <div class="stat"><div class="n">100%</div><div class="l">Satisfacción reportada</div></div>
      <div class="stat"><div class="n">87,236</div><div class="l">Artículos en inventarios</div></div>
    </div>
  </section>

  <!-- TESTIMONIOS -->
  <section class="section">
    <div class="features">
      <h2 class="sec-title">Lo que dicen nuestros clientes</h2>
      <p class="sec-sub">Casos reales de comercios que ahora tienen control total.</p>
      <div class="testimonials">
        <div class="tcard">
          <div class="t-top">
            <div class="avatar">AG</div>
            <div>
              <div class="t-name">Ariana Gómez</div>
              <div class="t-rol">Tienda de ropa</div>
            </div>
          </div>
          <p>“Pasé de anotar todo en cuadernos a solamente vender con un click. Shockfy me ahorró mucho tiempo.”</p>
        </div>
        <div class="tcard">
          <div class="t-top">
            <div class="avatar">JP</div>
            <div>
              <div class="t-name">José Pérez</div>
              <div class="t-rol">Distribuidora</div>
            </div>
          </div>
          <p>“Mis empleados tienen mucha mas facilidad para saber si tenemos productos en stock y los que estan por agotarse.”</p>
        </div>
        <div class="tcard">
          <div class="t-top">
            <div class="avatar">LM</div>
            <div>
              <div class="t-name">Laura Molina</div>
              <div class="t-rol">Accesorios</div>
            </div>
          </div>
          <p>“El soporte es realmente atento y amable. Me encantó eso de ShockFy.”</p>
        </div>
      </div>
    </div>
  </section>

  <!-- PRICING -->
  <section class="section" id="precios">
    <div class="features">
      <h2 class="sec-title">Un solo plan. Todo incluido.</h2>
      <p class="sec-sub">Empieza gratis por 15 días. Luego puedes continuar activar nuestro plan premium económico.</p>
      <div class="pricing">
        <div class="plan">
          <div style="font-weight:800;letter-spacing:.2px">Plan Estándar</div>
          <div class="price">$4.99 <small>/ mes</small></div>
          <div class="plist">
            <div class="row">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
              Inventario + ventas ilimitadas
            </div>
            <div class="row">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
              Multi-moneda y reportes mensuales
            </div>
            <div class="row">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
              Soporte humano prioritario
            </div>
             <div class="row">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
              Reporte de ventas y ganancias
            </div>
            <div class="row">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
              Alertas de Stock Bajo
            </div>
          </div>
          <a class="cta" href="signup.php" style="display:inline-block;padding:12px 16px;border-radius:12px;">Comenzar prueba gratis</a>
        </div>
      </div>
    </div>
  </section>

  <!-- FAQ -->
  <section class="section">
    <div class="features">
      <h2 class="sec-title">Preguntas frecuentes</h2>
      <p class="sec-sub">Respuestas rápidas para que arranques hoy.</p>
      <div class="faq">
        <div class="qa">
          <h4 onclick="toggleQA(this)">¿Necesito tarjeta para la prueba?
            <svg class="caret" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b1220" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
          </h4>
          <p>No. Empiezas sin tarjeta. Al terminar los 15 días puedes suscribirte si te convence.</p>
        </div>
        <div class="qa">
          <h4 onclick="toggleQA(this)">¿Puedo exportar mis datos?
            <svg class="caret" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b1220" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
          </h4>
          <p>Claro. Exporta inventario y ventas a CSV/Excel cuando lo necesites.</p>
        </div>
        <div class="qa">
          <h4 onclick="toggleQA(this)">¿No hay cargos sorpresas despues de pagar los $4.99?
            <svg class="caret" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b1220" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
          </h4>
          <p>No, $4,99 es el único monto que pagarás mensualmente.</p>
        </div>
        <div class="qa">
          <h4 onclick="toggleQA(this)">¿Qué pasa si necesito ayuda?
            <svg class="caret" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b1220" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
          </h4>
          <p>Te guiamos si tienes alguna duda mediante nuestro canal de soporte.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA FINAL -->
  <section class="section" style="padding-top:8px">
    <div class="cta-final">
      <div style="font-size:20px;font-weight:800;line-height:1.2">Prueba ShockFy gratis por 15 días</div>
      <div>
        <a href="signup.php">Crear mi cuenta</a>
      </div>
    </div>
  </section>

  <!-- FOOTER -->
  <footer id="contact">
    <div class="foot">
      <div>© <?php echo date('Y'); ?> ShockFy — Hecho para vender más</div>
      <div class="links">
        <a href="#features">Características</a>
        <a href="#beneficios">Beneficios</a>
        <a href="login.php">Ingresar</a>
      </div>
    </div>
  </footer>

  <script>
    function toggleQA(el){
      const box = el.parentElement;
      box.classList.toggle('open');
    }
  </script>
</body>
</html>
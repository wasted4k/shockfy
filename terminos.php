<?php
// terminos.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$year = date('Y');
// SUGERENCIA: guarda la fecha real cuando publiques esta página
$lastUpdated = '01/10/2025';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Términos y Condiciones — ShockFy</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f8ff;
      --panel:#ffffff;
      --text:#0b1220;
      --muted:#475569;
      --primary:#2344ec;
      --primary-2:#5ea4ff;
      --border:#e5e7eb;
      --shadow:0 18px 40px rgba(2,6,23,.10);
      --radius:18px;
      --max:960px;
    }
    *{box-sizing:border-box}
    html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
    a{color:var(--primary);text-decoration:none}
    a:hover{text-decoration:underline}

    /* NAV MINIMAL */
    nav{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.9);backdrop-filter:blur(10px);border-bottom:1px solid var(--border)}
    .nav-wrap{max-width:1100px;margin:0 auto;padding:14px 18px;display:flex;align-items:center;justify-content:space-between}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;letter-spacing:.2px;white-space:nowrap}
    .brand img{height:30px;width:auto;display:block}
    .nav-right a{padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font-weight:700}
    .nav-right a:hover{background:#eef4ff}

    /* PAGE */
    .wrap{max-width:var(--max);margin:24px auto;padding:0 16px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px}
    h1{margin:0 0 6px;font-size:28px;font-weight:800;letter-spacing:.2px}
    .meta{color:var(--muted);font-size:13px;margin-bottom:16px}
    h2{font-size:20px;margin:22px 0 8px}
    h3{font-size:16px;margin:16px 0 6px}
    p{line-height:1.6;margin:10px 0}
    ul{margin:8px 0 8px 18px}
    .note{background:#f8fbff;border:1px solid #dbe4ff;border-radius:12px;padding:12px;margin:14px 0;color:#0b1220}
    .footer{margin:20px auto 28px;max-width:var(--max);padding:0 16px;color:var(--muted);font-size:13px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}
    .btn-row{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);font-weight:800;background:#fff}
    .btn.primary{background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff;border-color:#cfe0ff;box-shadow:0 12px 28px rgba(35,68,236,.18)}
    .btn.primary:hover{filter:brightness(.97)}
  </style>
</head>
<body>

  <!-- NAV -->
  <nav>
    <div class="nav-wrap">
      <div class="brand">
        <img src="assets/img/icono_menu.png" alt="ShockFy">
        ShockFy
      </div>
      <div class="nav-right">
        <a href="home.php">Volver</a>
      </div>
    </div>
  </nav>

  <main class="wrap">
    <div class="card">
      <h1>Términos y Condiciones</h1>
      <div class="meta">Última actualización: <?= htmlspecialchars($lastUpdated) ?></div>

      <div class="note">
        <strong>Aviso legal:</strong> Este documento es un modelo informativo y no constituye asesoría legal. Debe ser revisado y adaptado por un profesional a tu jurisdicción y operación real.
      </div>

      <p><strong>Titular del servicio:</strong> ShockFy

      <strong>Contacto:</strong> info.shockfy@gmail.com

      <p>Al acceder o utilizar el sitio web, aplicaciones y servicios asociados de <strong>ShockFy</strong> (en conjunto, el “<strong>Servicio</strong>”), aceptas estos <strong>Términos y Condiciones</strong> (“<strong>Términos</strong>”). Si no estás de acuerdo, no uses el Servicio.</p>

      <h2>1. Definiciones</h2>
      <ul>
        <li><strong>Usuario</strong>: toda persona que accede o utiliza el Servicio.</li>
        <li><strong>Cuenta</strong>: perfil habilitado tras registro e inicio de sesión.</li>
        <li><strong>Plan</strong>: modalidad de acceso (p. ej., Free o Premium/Starter) y sus precios/condiciones.</li>
        <li><strong>Pago manual</strong>: pago realizado fuera de pasarela automatizada (p. ej., transferencia, Binance Pay, PayPal manual).</li>
        <li><strong>Confirmacion Pendiente</strong>: estado temporal de la Cuenta cuando el pago fue reportado y está en verificación.</li>
      </ul>

      <h2>2. Registro y Cuenta</h2>
      <p>2.1. Para usar funciones avanzadas debes crear una Cuenta, proporcionando datos veraces, completos y actualizados.</p>
      <p>2.2. Eres responsable de mantener la confidencialidad de tus credenciales y de toda actividad realizada bajo tu Cuenta.</p>
      <p>2.3. Podemos suspender o cerrar Cuentas que incumplan estos Términos, la ley o usos prohibidos (ver Sección 8).</p>

      <h2>3. Planes, Prueba y Suscripciones</h2>
      <p>3.1. Ofrecemos planes de acceso. Los detalles (precio, alcances, limitaciones) se muestran en la página de precios o durante el flujo de alta.</p>
      <p>3.2. La <strong>prueba gratuita</strong> (si aplica) dura el tiempo indicado. Al finalizar, podrás suscribirte a un Plan de pago o continuar con un plan gratuito limitado (si existe).</p>
      <p>3.3. Podemos actualizar funcionalidades, precios y condiciones de los Planes, notificándolo con antelación razonable cuando sea exigido por ley o contrato.</p>

      <h2>4. Pagos, Comprobantes y “Pending Confirmation”</h2>
      <p>4.1. <strong>Pasarelas y terceros.</strong> Algunos pagos pueden procesarse por servicios de terceros (p. ej., PayPal, Binance). Esos servicios tienen sus propios términos y políticas; no controlamos ni garantizamos su disponibilidad o desempeño.</p>
      <p>4.2. <strong>Pagos manuales.</strong> Si usas transferencia bancaria u otros métodos manuales, debes <strong>subir un comprobante</strong> válido a través del Servicio.</p>
      <p>4.3. <strong>Verificación.</strong> Tras subir el comprobante, tu Cuenta puede pasar a  <li><strong>Confirmacion Pendiente</strong> hasta que validemos el pago. Durante este estado:</p>
      <ul>
        <li>El acceso puede estar parcial o totalmente limitado (p. ej., solo ver página de espera).</li>
        <li>Si el pago no se valida (p. ej., monto incorrecto, datos inconsistentes, comprobante ilegible o fraudulento), el estado puede revertirse y/o la Cuenta ser limitada o suspendida.</li>
      </ul>
      <p>4.4. <strong>Facturación y moneda.</strong> Los precios se expresan en la moneda indicada en la UI. En pagos manuales, el equivalente en moneda local se calcula según el tipo de cambio aplicable que definamos o la pasarela externa.</p>
      <p>4.5. <strong>Impuestos.</strong> Eres responsable de impuestos, retenciones y cargas aplicables conforme a tu jurisdicción.</p>
      <p>4.6. <strong>Cargos rechazados / contracargos.</strong> Si una entidad financiera revierte o desconoce un pago, podremos suspender la Cuenta y solicitar regularización.</p>
      <p>4.7. <strong>Reembolsos.</strong> Salvo que nuestra política de reembolsos indique lo contrario o lo exija la ley, las cuotas pagadas <strong>no son reembolsables</strong>. Los reembolsos, si aplican, pueden estar sujetos a verificación y costos de procesamiento.</p>

      <h2>5. Renovaciones y Cancelación</h2>
      <p>5.1. Las suscripciones pueden ser de <strong>renovación automática</strong> si así se indica en el flujo de pago. Puedes cancelarla en cualquier momento antes de la renovación para evitar cargos futuros.</p>
      <p>5.2. La cancelación surte efecto al final del ciclo contratado; conservarás el acceso hasta esa fecha salvo suspensión por incumplimiento.</p>

      <h2>6. Disponibilidad del Servicio</h2>
      <p>6.1. Procuramos mantener alta disponibilidad, pero <strong>no garantizamos</strong> que el Servicio esté libre de interrupciones, errores o pérdidas de datos.</p>
      <p>6.2. Podemos realizar mantenimientos programados o de emergencia. Intentaremos notificar con antelación razonable cuando sea posible.</p>
      <p>6.3. <strong>Backups.</strong> Aunque podamos realizar copias de seguridad, no asumimos responsabilidad por pérdida de datos. Te recomendamos exportar/respaldar tu información con regularidad.</p>

      <h2>7. Contenido del Usuario y Licencia Limitada</h2>
      <p>7.1. Los datos que subes (p. ej., productos, ventas, comprobantes) siguen siendo tuyos.</p>
      <p>7.2. Nos concedes una <strong>licencia limitada, no exclusiva y revocable</strong> para procesar ese contenido con el único fin de operar el Servicio (p. ej., almacenamiento, copias temporales, análisis operativos, verificación de pagos).</p>
      <p>7.3. Debes contar con los derechos y permisos necesarios sobre el contenido que subes.</p>

      <h2>8. Usos Prohibidos</h2>
      <ul>
        <li>Usar el Servicio para actividades ilícitas, fraude, lavado de dinero o violaciones a sanciones.</li>
        <li>Vulnerar seguridad, intentar acceder a cuentas ajenas o interferir con el funcionamiento del Servicio.</li>
        <li>Subir malware, spam o contenido ilegal/ofensivo, o violar derechos de terceros.</li>
        <li>Realizar <em>scraping</em> o ingeniería inversa, salvo lo permitido por la ley aplicable.</li>
      </ul>
      <p>El incumplimiento puede derivar en suspensión o cierre de la Cuenta y en las acciones legales correspondientes.</p>

      <h2>9. Propiedad Intelectual</h2>
      <p>9.1. El Servicio, su código, marcas y diseños son de nuestra titularidad o licencia. No se te transfiere ningún derecho de propiedad.</p>
      <p>9.2. Se te concede una <strong>licencia limitada, no exclusiva y revocable</strong> para usar el Servicio conforme a estos Términos.</p>

      <h2>10. Privacidad y Datos Personales</h2>
      <p>10.1. Tratamos tus datos conforme a nuestra <a href="[enlace a política]" target="_blank" rel="noopener">Política de Privacidad</a>.</p>
      <p>10.2. Eres responsable de la exactitud de los datos que nos entregas y de mantenerlos actualizados.</p>
      <p>10.3. Podemos usar datos agregados/anónimos para métricas y mejora del Servicio.</p>

      <h2>11. Terceros y Enlaces</h2>
      <p>El Servicio puede integrar o enlazar a servicios de terceros (p. ej., pasarelas de pago). No controlamos su contenido ni política; su uso es bajo tu propio riesgo.</p>

      <h2>12. Descargos y Limitación de Responsabilidad</h2>
      <p>12.1. <strong>“Tal cual.”</strong> El Servicio se ofrece “AS IS / TAL CUAL”, sin garantías de disponibilidad, exactitud, idoneidad para un propósito particular o ausencia de errores.</p>
      <p>12.2. <strong>No asesoría.</strong> El Servicio no constituye asesoría contable, legal, fiscal o profesional.</p>
      <p>12.3. <strong>Límite.</strong> En la máxima medida permitida por la ley, nuestra responsabilidad total por cualquier reclamo relacionado con el Servicio se limita al monto pagado por ti en los últimos 12 meses previos al hecho, o a USD 100, lo que sea menor.</p>
      <p>12.4. <strong>Exclusiones.</strong> No seremos responsables por pérdida de beneficios, datos, reputación, interrupciones, ni daños indirectos, incidentales, especiales, punitivos o consecuentes.</p>

      <h2>13. Indemnidad</h2>
      <p>Te comprometes a indemnizar y mantener indemne a ShockFy frente a reclamaciones de terceros derivadas de tu uso del Servicio, contenido subido, o incumplimiento de estos Términos o de la ley.</p>

      <h2>14. Cambios en el Servicio o en los Términos</h2>
      <p>14.1. Podemos modificar o discontinuar funciones, planes o precios.</p>
      <p>14.2. Podemos actualizar estos Términos. Si el cambio es material, intentaremos notificarlo por medios razonables. El uso continuado tras la actualización supone aceptación.</p>

      <h2>15. Suspensión y Terminación</h2>
      <p>Podemos suspender o cerrar tu Cuenta si incumples estos Términos, si hay sospechas de fraude o a solicitud de autoridades.</p>

      <h2>16. Ley Aplicable y Disputas</h2>
      <p>Estos Términos se rigen por las leyes de <strong>[País/Estado]</strong>. Las controversias se someterán a los tribunales competentes de <strong>[Ciudad/País]</strong>, salvo disposición imperativa distinta. (Opcional) Antes de judicializar, las partes intentarán una solución amistosa dentro de 30 días.</p>

      <h2>17. Cesión</h2>
      <p>No puedes ceder estos Términos sin nuestro consentimiento previo por escrito. Podemos cederlos en relación con fusiones, adquisiciones o reestructuraciones.</p>

      <h2>18. Fuerza Mayor</h2>
      <p>No seremos responsables por incumplimientos causados por eventos fuera de nuestro control razonable.</p>

      <h2>19. Comunicaciones</h2>
      <p>Podemos comunicarnos contigo por correo electrónico, dentro del producto o por otros medios que nos hayas facilitado.</p>

      <h2>20. Disposiciones Finales</h2>
      <p>20.1. Si alguna cláusula se declara inválida, el resto seguirá vigente.</p>
      <p>20.2. La falta de ejercicio de un derecho no implica renuncia.</p>
      <p>20.3. Estos Términos constituyen el acuerdo completo entre tú y ShockFy respecto al Servicio.</p>

      <h2>Contacto</h2>
      <p><strong>Correo:</strong> info.shockfy@gmail.com<br>
      <strong>Soporte:</strong> [soporte]<br>
      

      <div class="btn-row">
        <a class="btn" href="home.php">Volver</a>
        <a class="btn primary" href="signup.php">Crear mi cuenta</a>
      </div>
    </div>
  </main>

  <footer class="footer">
    <div>© <?= $year ?> ShockFy</div>
    <div><a href="politica_privacidad.php">Política de Privacidad</a></div>
  </footer>
</body>
</html>

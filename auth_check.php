<?php
// auth_check.php — Gate de acceso con prioridad a pending_confirmation
// Requisitos: session_start() aquí, y $pdo disponible si usas fallback a DB.

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// ---------- Config ----------
$WHITELIST_FILES = [
  'waiting_confirmation.php', // la página de espera debe poder cargarse
  'pago_binance.php',         // endpoint JSON
  'login.php',
  'logout.php',
  // 'orden_compra.php',      // opcional: déjala fuera si quieres que también redirija
];

$PENDING_STATES  = ['pending_confirmation', 'pending_payment']; // admite ambos por compat
$WAITING_PAGE    = '/waiting_confirmation.php';
$DB_FALLBACK     = true; // intenta leer account_state desde DB si la sesión viene vacía

// ---------- Utilidades ----------
/** Redirige de forma segura; si headers ya fueron enviados, usa JS como fallback. */
function gate_redirect($url) {
  if (!headers_sent()) {
    header('Location: ' . $url, true, 302);
  } else {
    // Último recurso si algún include emitió salida accidentalmente
    echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
  }
  exit;
}

// ---------- Ruta actual ----------
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$base    = strtolower(basename($reqPath));

// ---------- Whitelist ----------
if (in_array($base, $WHITELIST_FILES, true)) {
  // Esta ruta no debe ser gateada
  return;
}

// ---------- Estado desde sesión ----------
$state = $_SESSION['account_state'] ?? null;

// ---------- Fallback a DB (si sesión vacía) ----------
if ($DB_FALLBACK && ($state === null || $state === '') && isset($_SESSION['user_id'])) {
  try {
    // Usa $pdo si ya está definido por db.php
    if (isset($pdo) && $pdo instanceof PDO) {
      $st = $pdo->prepare('SELECT account_state FROM users WHERE id = ? LIMIT 1');
      $st->execute([$_SESSION['user_id']]);
      $stateDb = $st->fetchColumn();
      if (is_string($stateDb) && $stateDb !== '') {
        $_SESSION['account_state'] = $stateDb; // cachear en sesión
        $state = $stateDb;
        // No hacemos session_write_close() aquí para no interferir con el flujo de la página
      }
    }
  } catch (Throwable $e) {
    // Silencioso: no bloquea el request si el fallback falla
    // error_log('[AUTH_CHECK][DB_FALLBACK] ' . $e->getMessage());
  }
}

// ---------- Prioridad: pending_* -> waiting_confirmation ----------
if (in_array($state, $PENDING_STATES, true)) {
  // Evitar loop si ya estamos en waiting
  if ($base !== strtolower(basename($WAITING_PAGE))) {
    // Diagnóstico opcional si ya se enviaron headers
    if (headers_sent($file, $line)) {
      error_log("GATE headers already sent en $file:$line; base=$base; state=$state");
    }
    gate_redirect($WAITING_PAGE);
  }
  // Si ya estamos en waiting, continuar (render normal)
  return;
}

// ---------- Aquí abajo va tu lógica normal de plan/trial/roles ----------
// Ejemplo:
// if (!($_SESSION['user_id'] ?? null)) { gate_redirect('/login.php'); }
// if ($necesitaPlan && $planActual === 'free') { gate_redirect('/billing.php'); }
// ...

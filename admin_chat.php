<?php
// admin_chat.php — Vista simple SOLO LECTURA para administradores
declare(strict_types=1);

// ----------- Cabeceras y errores (sin HTML de errores en producción) -----------
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');   // no mostrar errores en HTML
ini_set('html_errors','0');
ini_set('log_errors','1');       // log a error_log

// ----------- Session + DB -----------
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';   // Debe definir $pdo (PDO conectado a tu DB)

// ----------- Helpers -----------
function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hasColumn(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

function is_admin(): bool {
  // ADAPTA ESTO a tu app real. Ejemplos:
  // return !empty($_SESSION['is_admin']);               // 1 / true
  // return ($_SESSION['role'] ?? null) === 'admin';
  // Para demo: acepta si cualquiera de los dos está presente.
  if (!empty($_SESSION['is_admin'])) return true;
  if (($_SESSION['role'] ?? null) === 'admin') return true;
  return false;
}

// ----------- Guard de seguridad -----------
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo '<h1>No autenticado</h1>';
  exit;
}
if (!is_admin()) {
  http_response_code(403);
  echo '<h1>Acceso restringido</h1><p>Esta página es solo para administradores.</p>';
  exit;
}

// ----------- Descubrimos columnas opcionales para adaptarnos al esquema -----------
$hasPublicId   = hasColumn($pdo, 'support_tickets', 'public_id');
$hasUnreadAdm  = hasColumn($pdo, 'support_tickets', 'unread_admin');
$hasUnreadUser = hasColumn($pdo, 'support_tickets', 'unread_user');

// ----------- Filtros / Parámetros GET -----------
$allowedStatuses = ['all','open','pending','closed'];
$status = $_GET['status'] ?? 'open';
if (!in_array($status, $allowedStatuses, true)) $status = 'open';

$q = trim((string)($_GET['q'] ?? ''));      // búsqueda por id / public_id / user_id
$ticketId = (int)($_GET['ticket'] ?? 0);
$limit = max(1, min((int)($_GET['limit'] ?? 100), 500));  // evita excesos

// ----------- Consulta de tickets -----------
$fields = "id, user_id, status, last_message_at, updated_at";
if ($hasPublicId)  $fields .= ", public_id";
if ($hasUnreadAdm) $fields .= ", unread_admin";
if ($hasUnreadUser)$fields .= ", unread_user";

$sql = "SELECT $fields FROM support_tickets WHERE 1";
$args = [];

if ($status !== 'all') {
  $sql .= " AND status = ?";
  $args[] = $status;
}

if ($q !== '') {
  // Si es solo dígitos: buscamos por user_id exacto, id exacto o public_id 'like'
  if (ctype_digit($q)) {
    $cond = [];
    $cond[] = "id = ?";
    $args[] = (int)$q;

    $cond[] = "user_id = ?";
    $args[] = (int)$q;

    if ($hasPublicId) {
      $cond[] = "public_id LIKE ?";
      $args[] = '%'.$q.'%';
    }
    $sql .= " AND (".implode(' OR ', $cond).")";
  } else {
    // Texto: buscamos por public_id LIKE o por id si llega con prefijo # (ej. #123)
    $added = false;
    $sql .= " AND (";
    if ($hasPublicId) {
      $sql .= " public_id LIKE ?";
      $args[] = '%'.$q.'%';
      $added = true;
    }
    if (!$added && preg_match('/^#?(\d+)$/', $q, $m)) {
      if ($added) $sql .= " OR ";
      $sql .= " id = ?";
      $args[] = (int)$m[1];
      $added = true;
    }
    if (!$added) {
      // fallback: nada; evita filtrar por algo inexistente
      $sql .= " 1=0 ";
    }
    $sql .= ")";
  }
}

$sql .= " ORDER BY last_message_at DESC, id DESC LIMIT $limit";

$st = $pdo->prepare($sql);
$st->execute($args);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

// Si no viene ticket por GET y hay al menos uno, tomamos el primero
if ($ticketId <= 0 && !empty($tickets)) {
  $ticketId = (int)$tickets[0]['id'];
}

// ----------- Mensajes del ticket seleccionado -----------
$messages = [];
$ticketSel = null;
if ($ticketId > 0) {
  // Busca datos del ticket seleccionado (para encabezado)
  $st2 = $pdo->prepare("SELECT $fields FROM support_tickets WHERE id = ? LIMIT 1");
  $st2->execute([$ticketId]);
  $ticketSel = $st2->fetch(PDO::FETCH_ASSOC);

  if ($ticketSel) {
    $m = $pdo->prepare("
      SELECT sender, message, file_path, created_at
      FROM support_messages
      WHERE ticket_id = ?
      ORDER BY id ASC
    ");
    $m->execute([$ticketId]);
    $messages = $m->fetchAll(PDO::FETCH_ASSOC);
  }
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Soporte · Admin (solo lectura)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#0f172a;        /* fondo oscuro elegante */
      --panel:#111827;     /* panel */
      --muted:#94a3b8;     /* gris texto */
      --text:#e5e7eb;      /* texto principal */
      --accent:#22c55e;    /* verde suave */
      --danger:#ef4444;
      --link:#38bdf8;
      --chip:#1f2937;
    }
    *{ box-sizing:border-box; }
    body{
      margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;
      background:var(--bg); color:var(--text);
    }
    header{
      padding:14px 18px; border-bottom:1px solid #1f2937; background:#0b1220; position:sticky; top:0; z-index:10;
      display:flex; gap:14px; align-items:center; justify-content:space-between;
    }
    .brand{ font-weight:700; letter-spacing:.3px; }
    .wrap{ display:flex; gap:0; min-height:calc(100vh - 58px); }
    .col-left{
      width:380px; max-width:100%; border-right:1px solid #1f2937; background:var(--panel);
    }
    .col-right{ flex:1; min-width:0; }
    .tools{ padding:12px; border-bottom:1px solid #1f2937; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    input[type="text"], select{
      background:#0b1220; border:1px solid #1f2937; color:var(--text); padding:8px 10px; border-radius:8px;
    }
    button{
      border:0; padding:8px 12px; border-radius:8px; cursor:pointer; background:var(--accent); color:#0b1220; font-weight:600;
    }
    a{ color:var(--link); text-decoration:none; }
    a:hover{ text-decoration:underline; }
    .list{
      max-height:calc(100vh - 58px - 56px); overflow:auto; padding:6px;
    }
    .ticket{
      padding:10px; border-radius:10px; margin:6px; background:#0b1220; border:1px solid #1f2937;
      display:block; color:var(--text);
    }
    .ticket.active{ outline:2px solid var(--accent); }
    .muted{ color:var(--muted); font-size:12px; }
    .row{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .chip{ display:inline-block; padding:2px 8px; border-radius:999px; background:var(--chip); font-size:12px; }
    .chip.red{ background:#3b0f15; color:#fca5a5; }
    .chip.green{ background:#0b2b1a; color:#86efac; }
    .chip.yellow{ background:#2b230b; color:#fde68a; }
    .messages{
      padding:14px; max-height:calc(100vh - 58px - 56px); overflow:auto;
    }
    .msg{ margin-bottom:12px; background:#0b1220; border:1px solid #1f2937; padding:10px; border-radius:12px; }
    .msg .head{ font-weight:700; margin-bottom:6px; }
    .msg .body{ white-space:pre-wrap; word-wrap:break-word; }
    .empty{ padding:24px; color:var(--muted); }
    .toolbar{ padding:12px; border-bottom:1px solid #1f2937; display:flex; gap:10px; align-items:center; }
  </style>
</head>
<body>
<header>
  <div class="brand">Soporte · Admin (solo lectura)</div>
  <div class="muted">Estás logueado como ADMIN</div>
</header>

<div class="wrap">
  <!-- Columna izquierda: filtros + lista de tickets -->
  <aside class="col-left">
    <form class="tools" method="get" action="">
      <input type="text" name="q" placeholder="Buscar #id / public_id / user_id" value="<?=h($q)?>" />
      <select name="status">
        <option value="open" <?= $status==='open'?'selected':'' ?>>abiertos</option>
        <option value="pending" <?= $status==='pending'?'selected':'' ?>>pendientes</option>
        <option value="closed" <?= $status==='closed'?'selected':'' ?>>cerrados</option>
        <option value="all" <?= $status==='all'?'selected':'' ?>>todos</option>
      </select>
      <input type="hidden" name="limit" value="<?= (int)$limit ?>">
      <button type="submit">Filtrar</button>
    </form>

    <div class="list">
      <?php if (empty($tickets)): ?>
        <div class="empty">No hay tickets con ese filtro.</div>
      <?php else: ?>
        <?php foreach ($tickets as $t):
          $tid = (int)$t['id'];
          $isActive = ($tid === $ticketId);
          $statusChip = $t['status'];
          $chipClass = ($statusChip==='open'?'green':($statusChip==='pending'?'yellow':'red'));
          $title = $hasPublicId && !empty($t['public_id']) ? $t['public_id'] : ('#'.$tid);
          $unadm = $hasUnreadAdm ? (int)$t['unread_admin'] : 0;
        ?>
          <a class="ticket <?= $isActive?'active':'' ?>" href="?ticket=<?= $tid ?>&status=<?= h($status) ?>&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>">
            <div class="row">
              <div><strong><?= h($title) ?></strong></div>
              <div class="chip <?= $chipClass ?>"><?= h($t['status']) ?></div>
            </div>
            <div class="muted">user_id: <?= (int)$t['user_id'] ?></div>
            <?php if ($hasUnreadAdm): ?>
              <div class="muted">unread_admin: <?= $unadm ? 'sí' : 'no' ?></div>
            <?php endif; ?>
            <div class="muted">últ. msg: <?= h((string)$t['last_message_at']) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <!-- Columna derecha: mensajes del ticket seleccionado -->
  <main class="col-right">
    <div class="toolbar">
      <?php if ($ticketSel): ?>
        <div><strong>Ticket:</strong> <?= $hasPublicId && !empty($ticketSel['public_id']) ? h((string)$ticketSel['public_id']) : '#'.(int)$ticketSel['id'] ?></div>
        <div class="muted">status: <?= h((string)$ticketSel['status']) ?> · user_id: <?= (int)$ticketSel['user_id'] ?></div>
      <?php else: ?>
        <div class="muted">Selecciona un ticket para ver los mensajes</div>
      <?php endif; ?>
      <div style="margin-left:auto">
        <?php if ($ticketSel): ?>
          <a href="?ticket=<?= (int)$ticketSel['id'] ?>&status=<?= h($status) ?>&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>" class="muted">Actualizar</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="messages">
      <?php if (!$ticketSel): ?>
        <div class="empty">No hay ticket seleccionado.</div>
      <?php else: ?>
        <?php if (empty($messages)): ?>
          <div class="empty">Este ticket no tiene mensajes.</div>
        <?php else: ?>
          <?php foreach ($messages as $m):
            $who = ($m['sender'] === 'user') ? 'Usuario' : 'Agente';
            $att = trim((string)($m['file_path'] ?? ''));
          ?>
            <div class="msg">
              <div class="head"><?= h($who) ?> <span class="muted">· <?= h((string)$m['created_at']) ?></span></div>
              <?php if ((string)($m['message'] ?? '') !== ''): ?>
                <div class="body"><?= nl2br(h((string)$m['message'])) ?></div>
              <?php else: ?>
                <div class="body muted">(sin texto)</div>
              <?php endif; ?>
              <?php if ($att !== ''): ?>
                <div style="margin-top:8px">
                  <a href="<?= h($att) ?>" target="_blank" rel="noopener">Ver adjunto</a>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>

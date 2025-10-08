<?php
// admin_chat.php — Vista simple SOLO LECTURA para administradores (con full_name + auto-refresh)
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';   // Debe definir $pdo (PDO conectado)

function is_admin(): bool {
  // Mantén esta lógica flexible como la venías usando
  if (!empty($_SESSION['is_admin'])) return true;
  if (($_SESSION['role'] ?? null) === 'admin') return true;
  return false;
}

// ---------------- Helpers ----------------
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

// Descubrir columnas
$hasPublicId       = hasColumn($pdo, 'support_tickets', 'public_id');
$hasUnreadAdm      = hasColumn($pdo, 'support_tickets', 'unread_admin');
$hasUnreadUser     = hasColumn($pdo, 'support_tickets', 'unread_user');
$usersHasFullName  = hasColumn($pdo, 'users', 'full_name');
$msgHasFullName    = hasColumn($pdo, 'support_messages', 'full_name'); // opcional

// ===== AJAX: devolver mensajes del ticket en JSON (solo admin) =====
if (isset($_GET['ajax']) && $_GET['ajax'] === 'messages') {
  header('Content-Type: application/json; charset=utf-8');
  if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
  }
  $ticketAjax = (int)($_GET['ticket'] ?? 0);
  if ($ticketAjax <= 0) {
    echo json_encode(['ok'=>false,'error'=>'ticket invalid']);
    exit;
  }

  // Traer info básica del ticket + nombre del dueño (si existe)
  $fieldsAjax = "t.id, t.user_id";
  if ($usersHasFullName) $fieldsAjax .= ", u.full_name AS user_full_name";
  $stA = $pdo->prepare("
    SELECT $fieldsAjax
    FROM support_tickets t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.id = ?
    LIMIT 1
  ");
  $stA->execute([$ticketAjax]);
  $tk = $stA->fetch(PDO::FETCH_ASSOC);
  if (!$tk) {
    echo json_encode(['ok'=>false,'error'=>'ticket not found']);
    exit;
  }
  $ticketUserNameAjax = $usersHasFullName ? (string)($tk['user_full_name'] ?? '') : '';

  // Campos de mensajes
  $msgFields = "m.sender, m.message, m.file_path, m.created_at";
  if ($msgHasFullName) $msgFields .= ", m.full_name AS msg_full_name";

  $mA = $pdo->prepare("
    SELECT $msgFields
    FROM support_messages m
    WHERE m.ticket_id = ?
    ORDER BY m.id ASC
  ");
  $mA->execute([$ticketAjax]);
  $rows = $mA->fetchAll(PDO::FETCH_ASSOC);

  // Normalizar a payload simple para el front
  $out = [];
  foreach ($rows as $r) {
    $whoDefault = ($r['sender']==='user') ? ($ticketUserNameAjax ?: 'Usuario') : 'Agente';
    $who = $whoDefault;
    if ($msgHasFullName && isset($r['msg_full_name']) && trim((string)$r['msg_full_name'])!=='') {
      $who = (string)$r['msg_full_name'];
    }
    $out[] = [
      'who'       => $who,
      'sender'    => (string)$r['sender'],
      'message'   => (string)($r['message'] ?? ''),
      'file_path' => (string)($r['file_path'] ?? ''),
      'created_at'=> (string)($r['created_at'] ?? '')
    ];
  }

  echo json_encode(['ok'=>true,'messages'=>$out], JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------------- Parámetros ----------------
$allowedStatuses = ['all','open','pending','closed'];
$status = $_GET['status'] ?? 'open';
if (!in_array($status, $allowedStatuses, true)) $status = 'open';

$q = trim((string)($_GET['q'] ?? ''));
$ticketId = (int)($_GET['ticket'] ?? 0);
$limit = max(1, min((int)($_GET['limit'] ?? 100), 500));

// ---------------- Tickets (con JOIN a users para full_name) ----------------
$fields = "t.id, t.user_id, t.status, t.last_message_at, t.updated_at";
if ($hasPublicId)  $fields .= ", t.public_id";
if ($hasUnreadAdm) $fields .= ", t.unread_admin";
if ($hasUnreadUser)$fields .= ", t.unread_user";
if ($usersHasFullName) $fields .= ", u.full_name AS user_full_name";

$sql = "SELECT $fields
        FROM support_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE 1";
$args = [];

if ($status !== 'all') {
  $sql .= " AND t.status = ?";
  $args[] = $status;
}
if ($q !== '') {
  if (ctype_digit($q)) {
    $cond = [];
    $cond[] = "t.id = ?";
    $args[] = (int)$q;
    $cond[] = "t.user_id = ?";
    $args[] = (int)$q;
    if ($hasPublicId) {
      $cond[] = "t.public_id LIKE ?";
      $args[] = '%'.$q.'%';
    }
    if ($usersHasFullName && !ctype_digit($q)) {
      $cond[] = "u.full_name LIKE ?";
      $args[] = '%'.$q.'%';
    }
    $sql .= " AND (".implode(' OR ', $cond).")";
  } else {
    $sql .= " AND (";
    $pieces = [];
    if ($hasPublicId) { $pieces[] = "t.public_id LIKE ?"; $args[] = '%'.$q.'%'; }
    if ($usersHasFullName) { $pieces[] = "u.full_name LIKE ?"; $args[] = '%'.$q.'%'; }
    if (preg_match('/^#?(\d+)$/', $q, $m)) { $pieces[] = "t.id = ?"; $args[] = (int)$m[1]; }
    if (!$pieces) { $pieces[] = "1=0"; }
    $sql .= implode(' OR ', $pieces) . ")";
  }
}
$sql .= " ORDER BY t.last_message_at DESC, t.id DESC LIMIT $limit";

$st = $pdo->prepare($sql);
$st->execute($args);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

if ($ticketId <= 0 && !empty($tickets)) {
  $ticketId = (int)$tickets[0]['id'];
}

// ---------------- Ticket seleccionado + mensajes ----------------
$messages = [];
$ticketSel = null;
$ticketUserName = null;

if ($ticketId > 0) {
  $fieldsSel = $fields; // mismo set
  $st2 = $pdo->prepare("SELECT $fieldsSel
                        FROM support_tickets t
                        LEFT JOIN users u ON u.id = t.user_id
                        WHERE t.id = ?
                        LIMIT 1");
  $st2->execute([$ticketId]);
  $ticketSel = $st2->fetch(PDO::FETCH_ASSOC);
  if ($ticketSel && $usersHasFullName) {
    $ticketUserName = (string)($ticketSel['user_full_name'] ?? '');
  }

  if ($ticketSel) {
    $msgFields = "m.sender, m.message, m.file_path, m.created_at";
    if ($msgHasFullName) $msgFields .= ", m.full_name AS msg_full_name";

    $m = $pdo->prepare("
      SELECT $msgFields
      FROM support_messages m
      WHERE m.ticket_id = ?
      ORDER BY m.id ASC
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
      --bg:#0f172a; --panel:#111827; --muted:#94a3b8; --text:#e5e7eb;
      --accent:#22c55e; --danger:#ef4444; --link:#38bdf8; --chip:#1f2937;
    }
    *{ box-sizing:border-box; }
    body{ margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif; background:var(--bg); color:var(--text); }
    header{ padding:14px 18px; border-bottom:1px solid #1f2937; background:#0b1220; position:sticky; top:0; z-index:10; display:flex; gap:14px; align-items:center; justify-content:space-between; }
    .brand{ font-weight:700; letter-spacing:.3px; }
    .wrap{ display:flex; gap:0; min-height:calc(100vh - 58px); }
    .col-left{ width:420px; max-width:100%; border-right:1px solid #1f2937; background:var(--panel); }
    .col-right{ flex:1; min-width:0; }
    .tools{ padding:12px; border-bottom:1px solid #1f2937; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    input[type="text"], select{ background:#0b1220; border:1px solid #1f2937; color:var(--text); padding:8px 10px; border-radius:8px; }
    button{ border:0; padding:8px 12px; border-radius:8px; cursor:pointer; background:var(--accent); color:#0b1220; font-weight:600; }
    a{ color:var(--link); text-decoration:none; } a:hover{ text-decoration:underline; }
    .list{ max-height:calc(100vh - 58px - 56px); overflow:auto; padding:6px; }
    .ticket{ padding:10px; border-radius:10px; margin:6px; background:#0b1220; border:1px solid #1f2937; display:block; color:var(--text); }
    .ticket.active{ outline:2px solid var(--accent); }
    .muted{ color:var(--muted); font-size:12px; }
    .row{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .chip{ display:inline-block; padding:2px 8px; border-radius:999px; background:var(--chip); font-size:12px; }
    .chip.red{ background:#3b0f15; color:#fca5a5; } .chip.green{ background:#0b2b1a; color:#86efac; } .chip.yellow{ background:#2b230b; color:#fde68a; }
    .messages{ padding:14px; max-height:calc(100vh - 58px - 56px); overflow:auto; }
    .msg{ margin-bottom:12px; background:#0b1220; border:1px solid #1f2937; padding:10px; border-radius:12px; }
    .msg .head{ font-weight:700; margin-bottom:6px; }
    .msg .body{ white-space:pre-wrap; word-wrap:break-word; }
    .empty{ padding:24px; color:var(--muted); }
    .toolbar{ padding:12px; border-bottom:1px solid #1f2937; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .u-line{ color:var(--muted); }
  </style>
</head>
<body>
<header>
  <div class="brand">Soporte · Admin (solo lectura)</div>
  <div class="muted">Estás logueado como ADMIN</div>
</header>

<div class="wrap">
  <!-- Columna izquierda -->
  <aside class="col-left">
    <form class="tools" method="get" action="">
      <input type="text" name="q" placeholder="Buscar #id / public_id / nombre / user_id" value="<?=h($q)?>" />
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
          $name = $usersHasFullName ? trim((string)($t['user_full_name'] ?? '')) : '';
        ?>
          <a class="ticket <?= $isActive?'active':'' ?>" href="?ticket=<?= $tid ?>&status=<?= h($status) ?>&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>">
            <div class="row">
              <div><strong><?= h($title) ?></strong></div>
              <div class="chip <?= $chipClass ?>"><?= h($t['status']) ?></div>
            </div>
            <div class="muted">user_id: <?= (int)$t['user_id'] ?><?= $name!=='' ? " · ".h($name) : "" ?></div>
            <?php if ($hasUnreadAdm): ?>
              <div class="muted">unread_admin: <?= $unadm ? 'sí' : 'no' ?></div>
            <?php endif; ?>
            <div class="muted">últ. msg: <?= h((string)$t['last_message_at']) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <!-- Columna derecha -->
  <main class="col-right">
    <div class="toolbar">
      <?php if ($ticketSel): ?>
        <div>
          <strong>Ticket:</strong>
          <?= $hasPublicId && !empty($ticketSel['public_id']) ? h((string)$ticketSel['public_id']) : '#'.(int)$ticketSel['id'] ?>
          <?php if ($ticketUserName): ?>
            <span class="u-line">· Usuario: <?= h($ticketUserName) ?> (ID <?= (int)$ticketSel['user_id'] ?>)</span>
          <?php else: ?>
            <span class="u-line">· user_id: <?= (int)$ticketSel['user_id'] ?></span>
          <?php endif; ?>
          <span class="u-line">· status: <?= h((string)$ticketSel['status']) ?></span>
        </div>
      <?php else: ?>
        <div class="muted">Selecciona un ticket para ver los mensajes</div>
      <?php endif; ?>
      <div style="margin-left:auto">
        <?php if ($ticketSel): ?>
          <a href="?ticket=<?= (int)$ticketSel['id'] ?>&status=<?= h($status) ?>&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>" class="muted">Actualizar</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- IMPORTANTE: id="msgs" + data-ticket para el auto-refresh -->
    <div class="messages" id="msgs" <?php if($ticketSel): ?> data-ticket="<?= (int)$ticketSel['id'] ?>"<?php endif; ?>>
      <?php if (!$ticketSel): ?>
        <div class="empty">No hay ticket seleccionado.</div>
      <?php else: ?>
        <?php if (empty($messages)): ?>
          <div class="empty">Este ticket no tiene mensajes.</div>
        <?php else: ?>
          <?php foreach ($messages as $m):
            $whoDefault = ($m['sender'] === 'user') ? ($ticketUserName ?: 'Usuario') : 'Agente';
            if ($msgHasFullName && trim((string)($m['msg_full_name'] ?? '')) !== '') {
              $who = (string)$m['msg_full_name'];
            } else {
              $who = $whoDefault;
            }
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

<script>
(function(){
  const box = document.getElementById('msgs');
  if (!box) return;

  const ticketId = parseInt(box.getAttribute('data-ticket') || '0', 10);
  if (!ticketId) return;

  function esc(s){
    return (s||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  }

  function render(messages){
    if (!Array.isArray(messages) || !messages.length){
      box.innerHTML = '<div class="empty">Este ticket no tiene mensajes.</div>';
      return;
    }
    let html = '';
    for (const m of messages){
      const who = esc(m.who || ((m.sender==='user')?'Usuario':'Agente'));
      const ts  = esc(m.created_at || '');
      const body= (m.message && m.message !== '') ? '<div class="body">'+esc(m.message).replace(/\n/g,'<br>')+'</div>' : '<div class="body muted">(sin texto)</div>';
      const att = (m.file_path && m.file_path!=='') ? `<div style="margin-top:8px"><a href="${esc(m.file_path)}" target="_blank" rel="noopener">Ver adjunto</a></div>` : '';
      html += `
        <div class="msg">
          <div class="head">${who} <span class="muted">· ${ts}</span></div>
          ${body}
          ${att}
        </div>`;
    }
    box.innerHTML = html;
    box.scrollTop = box.scrollHeight + 120;
  }

  let pollTimer = null;
  const POLL_MS = 7000; // 7 s

  async function fetchMessages(){
    try {
      const u = new URL(window.location.href);
      u.searchParams.set('ajax','messages');
      u.searchParams.set('ticket', String(ticketId));
      const res = await fetch(u.toString(), { headers: { 'Accept':'application/json' }});
      const data = await res.json();
      if (!data || !data.ok || !Array.isArray(data.messages)) return;
      render(data.messages);
    } catch (e) {
      // Silencio para no romper UI si falla puntualmente
    }
  }

  function start(){
    stop();
    if (document.hidden) return;
    pollTimer = setInterval(fetchMessages, POLL_MS);
  }
  function stop(){
    if (pollTimer){ clearInterval(pollTimer); pollTimer = null; }
  }

  // Primera sincronización + polling
  fetchMessages().then(start);

  // Pausar/reanudar según visibilidad
  document.addEventListener('visibilitychange', ()=>{
    if (document.hidden) stop(); else start();
  });
})();
</script>

</body>
</html>

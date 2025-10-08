<?php
// admin_chat.php — Vista SOLO LECTURA para administradores
// Funciones: full_name, auto-refresh mensajes (derecha) + auto-refresh lista (izquierda) con dot no leído
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';

function is_admin(): bool {
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

// ----------- Guard -----------
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo '<h1>No autenticado</h1>';
  exit;
}
if (!is_admin()) {
  http_response_code(403);
  echo '<h1>Acceso restringido</h1><p>Solo administradores.</p>';
  exit;
}

// Descubrir columnas
$hasPublicId       = hasColumn($pdo, 'support_tickets', 'public_id');
$hasUnreadAdm      = hasColumn($pdo, 'support_tickets', 'unread_admin');
$hasUnreadUser     = hasColumn($pdo, 'support_tickets', 'unread_user');
$usersHasFullName  = hasColumn($pdo, 'users', 'full_name');
$msgHasFullName    = hasColumn($pdo, 'support_messages', 'full_name'); // opcional

// ---------------- Parámetros comunes (usados en HTML y AJAX) ----------------
$allowedStatuses = ['all','open','pending','closed'];
$status = $_GET['status'] ?? 'open';
if (!in_array($status, $allowedStatuses, true)) $status = 'open';

$q = trim((string)($_GET['q'] ?? ''));
$ticketId = (int)($_GET['ticket'] ?? 0);
$limit = max(1, min((int)($_GET['limit'] ?? 100), 500));

// ---------------- ENDPOINT AJAX: MENSAJES DEL TICKET ----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'messages') {
  header('Content-Type: application/json; charset=utf-8');
  if (!is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

  $ticketAjax = (int)($_GET['ticket'] ?? 0);
  if ($ticketAjax <= 0) { echo json_encode(['ok'=>false,'error'=>'ticket invalid']); exit; }

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
  if (!$tk) { echo json_encode(['ok'=>false,'error'=>'ticket not found']); exit; }

  // marcar leído si corresponde
  if ($hasUnreadAdm && (isset($_GET['markread']) && $_GET['markread'] === '1')) {
    $upd = $pdo->prepare("UPDATE support_tickets SET unread_admin=0, updated_at=NOW() WHERE id=?");
    try { $upd->execute([$ticketAjax]); } catch (\Throwable $e) { /* ignore */ }
  }

  $ticketUserNameAjax = $usersHasFullName ? (string)($tk['user_full_name'] ?? '') : '';
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

  $out = [];
  foreach ($rows as $r) {
    $whoDefault = ($r['sender']==='user') ? ($ticketUserNameAjax ?: 'Usuario') : 'Agente';
    $who = ($msgHasFullName && isset($r['msg_full_name']) && trim((string)$r['msg_full_name'])!=='')
           ? (string)$r['msg_full_name']
           : $whoDefault;
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

// ---------------- ENDPOINT AJAX: LISTA DE TICKETS (para dots vivos) ----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tickets') {
  header('Content-Type: application/json; charset=utf-8');
  if (!is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

  $statusAjax = $_GET['status'] ?? 'open';
  $qAjax = trim((string)($_GET['q'] ?? ''));
  $limitAjax = max(1, min((int)($_GET['limit'] ?? 100), 500));

  $fields = "t.id, t.user_id, t.status, t.last_message_at";
  if ($hasPublicId)  $fields .= ", t.public_id";
  if ($hasUnreadAdm) $fields .= ", t.unread_admin";
  if ($usersHasFullName) $fields .= ", u.full_name AS user_full_name";

  $sql = "SELECT $fields
          FROM support_tickets t
          LEFT JOIN users u ON u.id = t.user_id
          WHERE 1";
  $args = [];

  if ($statusAjax !== 'all') { $sql .= " AND t.status = ?"; $args[] = $statusAjax; }

  if ($qAjax !== '') {
    if (ctype_digit($qAjax)) {
      $cond=[]; $cond[]="t.id=?"; $args[]=(int)$qAjax;
      $cond[]="t.user_id=?"; $args[]=(int)$qAjax;
      if ($hasPublicId){ $cond[]="t.public_id LIKE ?"; $args[]='%'.$qAjax.'%'; }
      if ($usersHasFullName && !ctype_digit($qAjax)){ $cond[]="u.full_name LIKE ?"; $args[]='%'.$qAjax.'%'; }
      $sql .= " AND (".implode(' OR ', $cond).")";
    } else {
      $pieces=[];
      if ($hasPublicId){ $pieces[]="t.public_id LIKE ?"; $args[]='%'.$qAjax.'%'; }
      if ($usersHasFullName){ $pieces[]="u.full_name LIKE ?"; $args[]='%'.$qAjax.'%'; }
      if (preg_match('/^#?(\d+)$/', $qAjax, $m)) { $pieces[]="t.id=?"; $args[]=(int)$m[1]; }
      if (!$pieces) { $pieces[]="1=0"; }
      $sql .= " AND (".implode(' OR ', $pieces).")";
    }
  }

  $sql .= " ORDER BY t.last_message_at DESC, t.id DESC LIMIT $limitAjax";
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // Reducimos a lo que necesita el front
  $out = [];
  foreach ($rows as $t) {
    $out[] = [
      'id' => (int)$t['id'],
      'user_id' => (int)$t['user_id'],
      'status' => (string)$t['status'],
      'last_message_at' => (string)($t['last_message_at'] ?? ''),
      'public_id' => $hasPublicId ? (string)($t['public_id'] ?? '') : '',
      'unread_admin' => $hasUnreadAdm ? (int)($t['unread_admin'] ?? 0) : 0,
      'user_full_name' => $usersHasFullName ? (string)($t['user_full_name'] ?? '') : '',
    ];
  }

  echo json_encode(['ok'=>true,'tickets'=>$out], JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------------- QUERY para render inicial (HTML) ----------------
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

if ($status !== 'all') { $sql .= " AND t.status = ?"; $args[] = $status; }

if ($q !== '') {
  if (ctype_digit($q)) {
    $cond=[]; $cond[]="t.id=?"; $args[]=(int)$q;
    $cond[]="t.user_id=?"; $args[]=(int)$q;
    if ($hasPublicId){ $cond[]="t.public_id LIKE ?"; $args[]='%'.$q.'%'; }
    if ($usersHasFullName && !ctype_digit($q)){ $cond[]="u.full_name LIKE ?"; $args[]='%'.$q.'%'; }
    $sql .= " AND (".implode(' OR ', $cond).")";
  } else {
    $pieces=[];
    if ($hasPublicId){ $pieces[]="t.public_id LIKE ?"; $args[]='%'.$q.'%'; }
    if ($usersHasFullName){ $pieces[]="u.full_name LIKE ?"; $args[]='%'.$q.'%'; }
    if (preg_match('/^#?(\d+)$/', $q, $m)) { $pieces[]="t.id=?"; $args[]=(int)$m[1]; }
    if (!$pieces) { $pieces[]="1=0"; }
    $sql .= " AND (".implode(' OR ', $pieces).")";
  }
}
$sql .= " ORDER BY t.last_message_at DESC, t.id DESC LIMIT $limit";

$st = $pdo->prepare($sql);
$st->execute($args);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

if ($ticketId <= 0 && !empty($tickets)) {
  $ticketId = (int)$tickets[0]['id'];
}

// Ticket seleccionado + mensajes
$messages = [];
$ticketSel = null;
$ticketUserName = null;

if ($ticketId > 0) {
  $fieldsSel = $fields;
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

  // Marcar leído al abrir
  if ($ticketSel && $hasUnreadAdm && !empty($ticketSel['unread_admin'])) {
    $upd = $pdo->prepare("UPDATE support_tickets SET unread_admin=0, updated_at=NOW() WHERE id=?");
    try { $upd->execute([$ticketId]); $ticketSel['unread_admin'] = 0; } catch (\Throwable $e) { /* ignore */ }
  }

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
    .brand{ font-weight:700; }
    .wrap{ display:flex; gap:0; min-height:calc(100vh - 58px); }
    .col-left{ width:420px; max-width:100%; border-right:1px solid #1f2937; background:var(--panel); }
    .col-right{ flex:1; min-width:0; }
    .tools{ padding:12px; border-bottom:1px solid #1f2937; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    input[type="text"], select{ background:#0b1220; border:1px solid #1f2937; color:#e5e7eb; padding:8px 10px; border-radius:8px; }
    button{ border:0; padding:8px 12px; border-radius:8px; cursor:pointer; background:var(--accent); color:#0b1220; font-weight:600; }
    a{ color:var(--link); text-decoration:none; } a:hover{ text-decoration:underline; }
    .list{ max-height:calc(100vh - 58px - 56px); overflow:auto; padding:6px; }
    .ticket{ padding:10px; border-radius:10px; margin:6px; background:#0b1220; border:1px solid #1f2937; display:block; color:var(--text); position:relative; }
    .ticket.active{ outline:2px solid var(--accent); }
    .muted{ color:var(--muted); font-size:12px; }
    .row{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .chip{ display:inline-block; padding:2px 8px; border-radius:999px; background:var(--chip); font-size:12px; }
    .chip.red{ background:#3b0f15; color:#fca5a5; } .chip.green{ background:#0b2b1a; color:#86efac; } .chip.yellow{ background:#2b230b; color:#fde68a; }

    .dot{
      width:10px; height:10px; border-radius:999px; background:#f43f5e;
      box-shadow:0 0 0 0 rgba(244,63,94,0.7);
      animation: pulse 1.5s infinite;
      display:inline-block; vertical-align:middle; margin-left:8px;
    }
    @keyframes pulse{
      0%{ box-shadow:0 0 0 0 rgba(244,63,94,0.7); transform:scale(1); }
      70%{ box-shadow:0 0 0 10px rgba(244,63,94,0); transform:scale(1.08); }
      100%{ box-shadow:0 0 0 0 rgba(244,63,94,0); transform:scale(1); }
    }

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

    <div class="list" id="ticketList" data-status="<?=h($status)?>" data-q="<?=h($q)?>" data-limit="<?= (int)$limit ?>">
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
          <a class="ticket <?= $isActive?'active':'' ?>" data-id="<?= $tid ?>"
             href="?ticket=<?= $tid ?>&status=<?= h($status) ?>&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>">
            <div class="row">
              <div>
                <strong><?= h($title) ?></strong>
                <?php if ($hasUnreadAdm && $unadm): ?><span class="dot" title="Mensajes sin leer"></span><?php endif; ?>
              </div>
              <div class="chip <?= $chipClass ?>"><?= h($t['status']) ?></div>
            </div>
            <div class="muted">user_id: <?= (int)$t['user_id'] ?><?= $name!=='' ? " · ".h($name) : "" ?></div>
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

    <div class="messages" id="msgs" <?php if($ticketSel): ?> data-ticket="<?= (int)$ticketSel['id'] ?>"<?php endif; ?>>
      <?php if (!$ticketSel): ?>
        <div class="empty">No hay ticket seleccionado.</div>
      <?php else: ?>
        <?php if (empty($messages)): ?>
          <div class="empty">Este ticket no tiene mensajes.</div>
        <?php else: ?>
          <?php foreach ($messages as $m):
            $whoDefault = ($m['sender'] === 'user') ? ($ticketUserName ?: 'Usuario') : 'Agente';
            $who = ($msgHasFullName && trim((string)($m['msg_full_name'] ?? '')) !== '') ? (string)$m['msg_full_name'] : $whoDefault;
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
  // ---------- Auto-refresh MENSAJES (panel derecho) ----------
  const box = document.getElementById('msgs');
  if (box) {
    const ticketId = parseInt(box.getAttribute('data-ticket') || '0', 10);
    if (ticketId) {
      function esc(s){ return (s||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
      function renderMsgs(messages){
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
      const POLL_MS = 7000;

      async function fetchMessages(){
        try {
          const u = new URL(window.location.href);
          u.searchParams.set('ajax','messages');
          u.searchParams.set('ticket', String(ticketId));
          u.searchParams.set('markread','1'); // marcar leído al estar abierto
          const res = await fetch(u.toString(), { headers: { 'Accept':'application/json' }});
          const data = await res.json();
          if (!data || !data.ok || !Array.isArray(data.messages)) return;
          renderMsgs(data.messages);
        } catch (e) {}
      }

      function startMsgs(){ stopMsgs(); if (!document.hidden) pollTimer = setInterval(fetchMessages, POLL_MS); }
      function stopMsgs(){ if (pollTimer){ clearInterval(pollTimer); pollTimer = null; } }

      fetchMessages().then(startMsgs);
      document.addEventListener('visibilitychange', ()=>{ if (document.hidden) stopMsgs(); else startMsgs(); });
    }
  }

  // ---------- Auto-refresh LISTA (columna izquierda) ----------
  const listBox = document.getElementById('ticketList');
  if (!listBox) return;

  const listStatus = listBox.getAttribute('data-status') || 'open';
  const listQ = listBox.getAttribute('data-q') || '';
  const listLimit = parseInt(listBox.getAttribute('data-limit') || '100', 10);
  const activeLink = document.querySelector('.ticket.active');
  const activeId = activeLink ? parseInt(activeLink.getAttribute('data-id')||'0', 10) : 0;

  function esc(s){ return (s||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function statusChipClass(st){ return st==='open' ? 'green' : (st==='pending' ? 'yellow' : 'red'); }

  function renderList(rows){
    if (!Array.isArray(rows) || !rows.length){
      listBox.innerHTML = '<div class="empty">No hay tickets con ese filtro.</div>';
      return;
    }
    let html = '';
    for (const t of rows){
      const tid = Number(t.id);
      const title = (t.public_id && t.public_id !== '') ? t.public_id : ('#'+tid);
      const chipClass = statusChipClass(t.status);
      const name = t.user_full_name && t.user_full_name!=='' ? ' · '+esc(t.user_full_name) : '';
      const dot = (Number(t.unread_admin||0) ? '<span class="dot" title="Mensajes sin leer"></span>' : '');
      const isActive = (tid === activeId);
      const href = `?ticket=${tid}&status=${encodeURIComponent(listStatus)}&q=${encodeURIComponent(listQ)}&limit=${encodeURIComponent(String(listLimit))}`;
      html += `
        <a class="ticket ${isActive?'active':''}" data-id="${tid}" href="${href}">
          <div class="row">
            <div><strong>${esc(title)}</strong>${dot}</div>
            <div class="chip ${chipClass}">${esc(t.status)}</div>
          </div>
            <div class="muted">user_id: ${Number(t.user_id)}${name}</div>
            <div class="muted">últ. msg: ${esc(t.last_message_at || '')}</div>
        </a>`;
    }
    listBox.innerHTML = html;
  }

  let pollListTimer = null;
  const POLL_LIST_MS = 7000;

  async function fetchList(){
    try {
      const u = new URL(window.location.href);
      u.searchParams.set('ajax','tickets');
      u.searchParams.set('status', listStatus);
      u.searchParams.set('q', listQ);
      u.searchParams.set('limit', String(listLimit));
      const res = await fetch(u.toString(), { headers: { 'Accept':'application/json' }});
      const data = await res.json();
      if (!data || !data.ok || !Array.isArray(data.tickets)) return;
      renderList(data.tickets);
    } catch (e) {}
  }

  function startList(){ stopList(); if (!document.hidden) pollListTimer = setInterval(fetchList, POLL_LIST_MS); }
  function stopList(){ if (pollListTimer){ clearInterval(pollListTimer); pollListTimer = null; } }

  fetchList().then(startList);
  document.addEventListener('visibilitychange', ()=>{ if (document.hidden) stopList(); else startList(); });

})();
</script>

</body>
</html>

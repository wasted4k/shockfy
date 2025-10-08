<?php
// admin_chat.php — Admin: ver, responder, auto-refresh, lista con dot, cerrar (resolved) + rating request
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
function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function hasColumn(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}

// -------- Guard solo admin --------
if (!is_admin()) {
  http_response_code(403);
  echo '<h1>Acceso restringido</h1><p>Solo administradores.</p>';
  exit;
}

// ---- Descubrir columnas (para adaptar UI/queries) ----
$hasPublicId      = hasColumn($pdo, 'support_tickets', 'public_id');
$hasUnreadAdm     = hasColumn($pdo, 'support_tickets', 'unread_admin');
$hasUnreadUser    = hasColumn($pdo, 'support_tickets', 'unread_user');
$usersHasFullName = hasColumn($pdo, 'users', 'full_name');
$msgHasFullName   = hasColumn($pdo, 'support_messages', 'full_name'); // opcional

// ---- Parámetros comunes ----
$allowedStatuses = ['all','open','pending','resolved','closed'];
$status   = $_GET['status'] ?? 'open';
if (!in_array($status, $allowedStatuses, true)) $status = 'open';
$q        = trim((string)($_GET['q'] ?? ''));
$ticketId = (int)($_GET['ticket'] ?? 0);
$limit    = max(1, min((int)($_GET['limit'] ?? 100), 500));

/* =============================
   AJAX: MENSAJES DEL TICKET
============================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'messages') {
  header('Content-Type: application/json; charset=utf-8');
  $tid = (int)($_GET['ticket'] ?? 0);
  if ($tid <= 0) { echo json_encode(['ok'=>false,'error'=>'ticket invalid']); exit; }

  $fields = "t.id,t.user_id,t.status";
  if ($usersHasFullName) $fields .= ",u.full_name AS user_full_name";
  $st = $pdo->prepare("SELECT $fields FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=? LIMIT 1");
  $st->execute([$tid]);
  $tk = $st->fetch(PDO::FETCH_ASSOC);
  if (!$tk) { echo json_encode(['ok'=>false,'error'=>'ticket not found']); exit; }

  // marcar leído para admin si procede
  if ($hasUnreadAdm && (isset($_GET['markread']) && $_GET['markread'] === '1')) {
    try { $pdo->prepare("UPDATE support_tickets SET unread_admin=0, updated_at=NOW() WHERE id=?")->execute([$tid]); } catch (\Throwable $e){}
  }

  $msgFields = "m.sender,m.message,m.file_path,m.created_at";
  if ($msgHasFullName) $msgFields .= ",m.full_name AS msg_full_name";
  $m = $pdo->prepare("SELECT $msgFields FROM support_messages m WHERE m.ticket_id=? ORDER BY m.id ASC");
  $m->execute([$tid]);
  $rows = $m->fetchAll(PDO::FETCH_ASSOC);

  $userName = $usersHasFullName ? (string)($tk['user_full_name'] ?? '') : '';
  $out = [];
  foreach ($rows as $r) {
    $whoDefault = ($r['sender']==='user') ? ($userName ?: 'Usuario') : 'Agente';
    $who = ($msgHasFullName && !empty($r['msg_full_name'])) ? (string)$r['msg_full_name'] : $whoDefault;
    $out[] = [
      'who'=>$who,'sender'=>(string)$r['sender'],
      'message'=>(string)($r['message'] ?? ''),'file_path'=>(string)($r['file_path'] ?? ''),
      'created_at'=>(string)($r['created_at'] ?? '')
    ];
  }
  echo json_encode(['ok'=>true,'messages'=>$out,'status'=>(string)($tk['status'] ?? '')], JSON_UNESCAPED_UNICODE);
  exit;
}

/* =============================
   AJAX: LISTA DE TICKETS
============================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tickets') {
  header('Content-Type: application/json; charset=utf-8');
  $statusAjax = $_GET['status'] ?? 'open';
  $qAjax      = trim((string)($_GET['q'] ?? ''));
  $limitAjax  = max(1, min((int)($_GET['limit'] ?? 100), 500));

  $fields = "t.id,t.user_id,t.status,t.last_message_at";
  if ($hasPublicId)      $fields .= ",t.public_id";
  if ($hasUnreadAdm)     $fields .= ",t.unread_admin";
  if ($usersHasFullName) $fields .= ",u.full_name AS user_full_name";

  $sql = "SELECT $fields FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id WHERE 1";
  $args = [];
  if ($statusAjax !== 'all') { $sql .= " AND t.status=?"; $args[] = $statusAjax; }

  if ($qAjax !== '') {
    if (ctype_digit($qAjax)) {
      $cond=[]; $cond[]="t.id=?"; $args[]=(int)$qAjax;
      $cond[]="t.user_id=?"; $args[]=(int)$qAjax;
      if ($hasPublicId){ $cond[]="t.public_id LIKE ?"; $args[]='%'.$qAjax.'%'; }
      if ($usersHasFullName && !ctype_digit($qAjax)){ $cond[]="u.full_name LIKE ?"; $args[]='%'.$qAjax.'%'; }
      $sql .= " AND (".implode(' OR ',$cond).")";
    } else {
      $pieces=[];
      if ($hasPublicId){ $pieces[]="t.public_id LIKE ?"; $args[]='%'.$qAjax.'%'; }
      if ($usersHasFullName){ $pieces[]="u.full_name LIKE ?"; $args[]='%'.$qAjax.'%'; }
      if (preg_match('/^#?(\d+)$/',$qAjax,$m0)){ $pieces[]="t.id=?"; $args[]=(int)$m0[1]; }
      if (!$pieces) { $pieces[]="1=0"; }
      $sql .= " AND (".implode(' OR ',$pieces).")";
    }
  }
  $sql .= " ORDER BY t.last_message_at DESC, t.id DESC LIMIT $limitAjax";
  $st = $pdo->prepare($sql); $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $out=[];
  foreach ($rows as $t) {
    $out[] = [
      'id'=>(int)$t['id'],
      'user_id'=>(int)$t['user_id'],
      'status'=>(string)$t['status'],
      'last_message_at'=>(string)($t['last_message_at'] ?? ''),
      'public_id'=>$hasPublicId ? (string)($t['public_id'] ?? '') : '',
      'unread_admin'=>$hasUnreadAdm ? (int)($t['unread_admin'] ?? 0) : 0,
      'user_full_name'=>$usersHasFullName ? (string)($t['user_full_name'] ?? '') : '',
    ];
  }
  echo json_encode(['ok'=>true,'tickets'=>$out], JSON_UNESCAPED_UNICODE);
  exit;
}

/* =============================
   AJAX: CERRAR (RESOLVER) TICKET
   (solo cambia status a 'resolved')
============================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'close') {
  header('Content-Type: application/json; charset=utf-8');
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
  }
  $tid = (int)($_POST['ticket_id'] ?? 0);
  if ($tid <= 0) { echo json_encode(['ok'=>false,'error'=>'ticket invalid']); exit; }

  try {
    $st = $pdo->prepare("UPDATE support_tickets SET status='resolved', updated_at=NOW() WHERE id=?");
    $st->execute([$tid]);
    echo json_encode(['ok'=>true]);
  } catch (\Throwable $e) {
    error_log('close ticket fail: '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal']);
  }
  exit;
}

/* =============================
   RENDER INICIAL
============================= */
$fields = "t.id,t.user_id,t.status,t.last_message_at,t.updated_at";
if ($hasPublicId)      $fields .= ",t.public_id";
if ($hasUnreadAdm)     $fields .= ",t.unread_admin";
if ($hasUnreadUser)    $fields .= ",t.unread_user";
if ($usersHasFullName) $fields .= ",u.full_name AS user_full_name";

$sql = "SELECT $fields FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id WHERE 1";
$args = [];
if ($status !== 'all'){ $sql .= " AND t.status=?"; $args[]=$status; }

if ($q !== '') {
  if (ctype_digit($q)) {
    $cond=[]; $cond[]="t.id=?"; $args[]=(int)$q;
    $cond[]="t.user_id=?"; $args[]=(int)$q;
    if ($hasPublicId){ $cond[]="t.public_id LIKE ?"; $args[]='%'.$q.'%'; }
    if ($usersHasFullName && !ctype_digit($q)){ $cond[]="u.full_name LIKE ?"; $args[]='%'.$q.'%'; }
    $sql .= " AND (".implode(' OR ',$cond).")";
  } else {
    $pieces=[];
    if ($hasPublicId){ $pieces[]="t.public_id LIKE ?"; $args[]='%'.$q.'%'; }
    if ($usersHasFullName){ $pieces[]="u.full_name LIKE ?"; $args[]='%'.$q.'%'; }
    if (preg_match('/^#?(\d+)$/',$q,$m1)){ $pieces[]="t.id=?"; $args[]=(int)$m1[1]; }
    if (!$pieces) { $pieces[]="1=0"; }
    $sql .= " AND (".implode(' OR ',$pieces).")";
  }
}
$sql .= " ORDER BY t.last_message_at DESC, t.id DESC LIMIT $limit";
$st = $pdo->prepare($sql); $st->execute($args);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);
if ($ticketId <= 0 && !empty($tickets)) $ticketId = (int)$tickets[0]['id'];

// Ticket seleccionado y mensajes
$messages=[]; $ticketSel=null; $ticketUserName=null;
if ($ticketId > 0) {
  $st2 = $pdo->prepare("SELECT $fields FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=? LIMIT 1");
  $st2->execute([$ticketId]); $ticketSel = $st2->fetch(PDO::FETCH_ASSOC);
  if ($ticketSel && $usersHasFullName) $ticketUserName = (string)($ticketSel['user_full_name'] ?? '');

  if ($ticketSel && $hasUnreadAdm && !empty($ticketSel['unread_admin'])) {
    try { $pdo->prepare("UPDATE support_tickets SET unread_admin=0, updated_at=NOW() WHERE id=?")->execute([$ticketId]); $ticketSel['unread_admin']=0; } catch (\Throwable $e){}
  }

  $msgFields = "m.sender,m.message,m.file_path,m.created_at";
  if ($msgHasFullName) $msgFields .= ",m.full_name AS msg_full_name";
  $m = $pdo->prepare("SELECT $msgFields FROM support_messages m WHERE m.ticket_id=? ORDER BY m.id ASC");
  $m->execute([$ticketId]); $messages = $m->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Soporte · Admin</title>
<style>
  :root{ --bg:#0f172a; --panel:#111827; --muted:#94a3b8; --text:#e5e7eb; --accent:#22c55e; --link:#38bdf8; --chip:#1f2937; --bluebg:#0b1f2b; --bluetx:#93c5fd; }
  *{ box-sizing:border-box } body{ margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif; background:var(--bg); color:var(--text); }
  header{ padding:14px 18px; border-bottom:1px solid #1f2937; background:#0b1220; display:flex; justify-content:space-between; align-items:center }
  .wrap{ display:flex; min-height:calc(100vh - 58px) } .col-left{ width:420px; border-right:1px solid #1f2937; background:var(--panel) } .col-right{ flex:1; display:flex; flex-direction:column }
  .tools{ padding:12px; border-bottom:1px solid #1f2937; display:flex; gap:8px; flex-wrap:wrap } input[type=text],select{ background:#0b1220; border:1px solid #1f2937; color:#e5e7eb; padding:8px 10px; border-radius:8px }
  button{ border:0; padding:8px 12px; border-radius:8px; cursor:pointer; background:var(--accent); color:#0b1220; font-weight:600 }
  a{ color:var(--link); text-decoration:none } a:hover{text-decoration:underline} .list{ max-height:calc(100vh - 58px - 56px); overflow:auto; padding:6px }
  .ticket{ padding:10px; border-radius:10px; margin:6px; background:#0b1220; border:1px solid #1f2937; display:block; color:var(--text) } .ticket.active{ outline:2px solid var(--accent) }
  .muted{ color:var(--muted); font-size:12px } .row{ display:flex; justify-content:space-between; gap:10px; align-items:center }
  .chip{ padding:2px 8px; border-radius:999px; background:var(--chip); font-size:12px } .chip.green{background:#0b2b1a;color:#86efac}.chip.yellow{background:#2b230b;color:#fde68a}.chip.blue{background:var(--bluebg);color:var(--bluetx)}.chip.red{background:#3b0f15;color:#fca5a5}
  .dot{ width:10px; height:10px; border-radius:999px; background:#f43f5e; box-shadow:0 0 0 0 rgba(244,63,94,0.7); animation:pulse 1.5s infinite; display:inline-block; margin-left:8px }
  @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(244,63,94,.7);transform:scale(1)}70%{box-shadow:0 0 0 10px rgba(244,63,94,0);transform:scale(1.08)}100%{box-shadow:0 0 0 0 rgba(244,63,94,0);transform:scale(1)}}
  .toolbar{ padding:12px; border-bottom:1px solid #1f2937; display:flex; gap:10px; align-items:center; flex-wrap:wrap }
  .messages{ padding:14px; max-height:calc(100vh - 58px - 56px - 92px); overflow:auto } .msg{ margin-bottom:12px; background:#0b1220; border:1px solid #1f2937; padding:10px; border-radius:12px }
  .msg .head{ font-weight:700; margin-bottom:6px } .msg .body{ white-space:pre-wrap; word-wrap:break-word } .empty{ padding:24px; color:var(--muted) }
  .replybar{ border-top:1px solid #1f2937; padding:10px; display:flex; gap:8px; align-items:center; background:#0b1220 }
  .replybar textarea{ flex:1; min-height:46px; max-height:160px; resize:vertical; background:#0a0f1c; color:#e5e7eb; border:1px solid #1f2937; border-radius:8px; padding:8px 10px }
</style>
</head>
<body>
<header>
  <div class="brand">Soporte · Admin</div>
  <div class="muted">Estás logueado como ADMIN</div>
</header>

<div class="wrap">
  <!-- Lista -->
  <aside class="col-left">
    <form class="tools" method="get" action="">
      <input type="text" name="q" placeholder="Buscar #id / public_id / nombre / user_id" value="<?=h($q)?>">
      <select name="status">
        <option value="open"     <?= $status==='open'?'selected':'' ?>>abiertos</option>
        <option value="pending"  <?= $status==='pending'?'selected':'' ?>>pendientes</option>
        <option value="resolved" <?= $status==='resolved'?'selected':'' ?>>resueltos</option>
        <option value="closed"   <?= $status==='closed'?'selected':'' ?>>cerrados</option>
        <option value="all"      <?= $status==='all'?'selected':'' ?>>todos</option>
      </select>
      <input type="hidden" name="limit" value="<?= (int)$limit ?>">
      <button type="submit">Filtrar</button>
    </form>

    <div class="list" id="ticketList" data-status="<?=h($status)?>" data-q="<?=h($q)?>" data-limit="<?= (int)$limit ?>">
      <?php if (empty($tickets)): ?>
        <div class="empty">No hay tickets con ese filtro.</div>
      <?php else: foreach ($tickets as $t):
        $tid = (int)$t['id']; $isActive = ($tid === $ticketId);
        $statusChip = $t['status']; $chipClass = ($statusChip==='open'?'green':($statusChip==='pending'?'yellow':($statusChip==='resolved'?'blue':'red')));
        $title = $hasPublicId && !empty($t['public_id']) ? $t['public_id'] : ('#'.$tid);
        $unadm = $hasUnreadAdm ? (int)$t['unread_admin'] : 0;
        $name  = $usersHasFullName ? trim((string)($t['user_full_name'] ?? '')) : '';
      ?>
        <a class="ticket <?= $isActive?'active':'' ?>" data-id="<?= $tid ?>"
           href="?ticket=<?= $tid ?>&status=<?= h($status) ?>&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>">
          <div class="row">
            <div><strong><?= h($title) ?></strong><?php if ($hasUnreadAdm && $unadm): ?><span class="dot" title="Mensajes sin leer"></span><?php endif; ?></div>
            <div class="chip <?= $chipClass ?>"><?= h($t['status']) ?></div>
          </div>
          <div class="muted">user_id: <?= (int)$t['user_id'] ?><?= $name!=='' ? " · ".h($name) : "" ?></div>
          <div class="muted">últ. msg: <?= h((string)$t['last_message_at']) ?></div>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </aside>

  <!-- Panel -->
  <main class="col-right">
    <div class="toolbar">
      <?php if ($ticketSel): ?>
        <div>
          <strong>Ticket:</strong>
          <?= $hasPublicId && !empty($ticketSel['public_id']) ? h((string)$ticketSel['public_id']) : '#'.(int)$ticketSel['id'] ?>
          <?php if ($ticketUserName): ?><span class="muted">· Usuario: <?= h($ticketUserName) ?> (ID <?= (int)$ticketSel['user_id'] ?>)</span>
          <?php else: ?><span class="muted">· user_id: <?= (int)$ticketSel['user_id'] ?></span><?php endif; ?>
          <span class="muted">· status:
            <span class="chip <?= ($ticketSel['status']==='open'?'green':($ticketSel['status']==='pending'?'yellow':($ticketSel['status']==='resolved'?'blue':'red'))) ?>">
              <?= h((string)$ticketSel['status']) ?>
            </span>
          </span>
        </div>
        <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
          <?php if (($ticketSel['status'] ?? '') !== 'resolved'): ?>
            <button class="btn-close" id="btnCloseTicket" style="background:#ef4444;color:white;border:0;padding:8px 12px;border-radius:10px;font-weight:700;cursor:pointer;">Marcar como resuelto</button>
          <?php endif; ?>
          <a href="?ticket=<?= (int)$ticketSel['id'] ?>&status=<?= h($status) ?>&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>" class="muted">Actualizar</a>
        </div>
      <?php else: ?>
        <div class="muted">Selecciona un ticket para ver los mensajes</div>
      <?php endif; ?>
    </div>

    <div class="messages" id="msgs" <?php if($ticketSel): ?> data-ticket="<?= (int)$ticketSel['id'] ?>"<?php endif; ?>>
      <?php if (!$ticketSel): ?>
        <div class="empty">No hay ticket seleccionado.</div>
      <?php else: if (empty($messages)): ?>
        <div class="empty">Este ticket no tiene mensajes.</div>
      <?php else: foreach ($messages as $m):
        $whoDefault = ($m['sender'] === 'user') ? ($ticketUserName ?: 'Usuario') : 'Agente';
        $who = ($msgHasFullName && !empty($m['msg_full_name'])) ? (string)$m['msg_full_name'] : $whoDefault;
        $att = trim((string)($m['file_path'] ?? ''));
      ?>
        <div class="msg">
          <div class="head"><?= h($who) ?> <span class="muted">· <?= h((string)$m['created_at']) ?></span></div>
          <?php if ((string)($m['message'] ?? '') !== ''): ?>
            <div class="body"><?= nl2br(h((string)$m['message'])) ?></div>
          <?php else: ?><div class="body muted">(sin texto)</div><?php endif; ?>
          <?php if ($att !== ''): ?>
            <div style="margin-top:8px"><a href="<?= h($att) ?>" target="_blank" rel="noopener">Ver adjunto</a></div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; endif; ?>
    </div>

    <?php if ($ticketSel && ($ticketSel['status'] ?? '') !== 'resolved'): ?>
    <div class="replybar">
      <textarea id="replyText" placeholder="Escribe una respuesta al usuario..." maxlength="4000"></textarea>
      <input type="file" id="replyFile" accept=".png,.jpg,.jpeg,.pdf">
      <button class="btn-send" id="replySend">Enviar</button>
    </div>
    <?php endif; ?>
  </main>
</div>

<script>
(function(){
  function esc(s){ return (s||'').replace(/[&<>]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function statusChipClass(st){ return st==='open'?'green':(st==='pending'?'yellow':(st==='resolved'?'blue':'red')); }

  // -------- Panel mensajes (auto-refresh) --------
  const box = document.getElementById('msgs');
  let fetchMessagesTimer=null; const POLL_MS=7000;

  async function fetchMessages(){
    if (!box) return;
    const ticketId = parseInt(box.getAttribute('data-ticket')||'0',10);
    if (!ticketId) return;
    try{
      const u = new URL(window.location.href);
      u.searchParams.set('ajax','messages'); u.searchParams.set('ticket', String(ticketId)); u.searchParams.set('markread','1');
      const res = await fetch(u.toString(), { headers:{'Accept':'application/json'}, credentials:'same-origin' });
      const data = await res.json();
      if (!data || !data.ok || !Array.isArray(data.messages)) return;

      let html='';
      if (!data.messages.length){
        html = '<div class="empty">Este ticket no tiene mensajes.</div>';
      } else {
        for (const m of data.messages){
          const who = esc(m.who || ((m.sender==='user')?'Usuario':'Agente'));
          const ts  = esc(m.created_at || '');
          const body= (m.message && m.message!=='') ? '<div class="body">'+esc(m.message).replace(/\n/g,'<br>')+'</div>' : '<div class="body muted">(sin texto)</div>';
          const att = (m.file_path && m.file_path!=='') ? `<div style="margin-top:8px"><a href="${esc(m.file_path)}" target="_blank" rel="noopener">Ver adjunto</a></div>` : '';
          html += `<div class="msg"><div class="head">${who} <span class="muted">· ${ts}</span></div>${body}${att}</div>`;
        }
      }
      box.innerHTML = html;
      box.scrollTop = box.scrollHeight + 120;

      // si quedó resuelto, oculta barra de respuesta y chip
      if (data.status === 'resolved') {
        const reply = document.querySelector('.replybar'); if (reply) reply.style.display='none';
        const chip = document.querySelector('.toolbar .chip');
        if (chip){ chip.className = 'chip '+statusChipClass('resolved'); chip.textContent = 'resolved'; }
      }
    }catch(e){}
  }
  function startMessages(){ stopMessages(); if (!document.hidden){ fetchMessagesTimer=setInterval(fetchMessages,POLL_MS); } }
  function stopMessages(){ if (fetchMessagesTimer){ clearInterval(fetchMessagesTimer); fetchMessagesTimer=null; } }
  window.fetchMessages = fetchMessages; // export para usar después
  fetchMessages().then(startMessages);
  document.addEventListener('visibilitychange', ()=>{ if (document.hidden) stopMessages(); else startMessages(); });

  // -------- Enviar respuesta admin --------
  const btn = document.getElementById('replySend');
  const txt = document.getElementById('replyText');
  const fil = document.getElementById('replyFile');
  async function sendReply(){
    const ticketId = parseInt(box?.getAttribute('data-ticket')||'0',10);
    if (!ticketId) return;
    const msg = (txt?.value||'').trim();
    const f = (fil && fil.files && fil.files[0]) ? fil.files[0] : null;
    if (!msg && !f){ alert('Escribe un mensaje o adjunta un archivo'); return; }
    const form = new FormData();
    form.append('ticket_id', String(ticketId)); form.append('message', msg); if (f) form.append('file', f);
    try{
      if (btn) btn.disabled=true;
      const res = await fetch('api/admin_support.php?debug=1', { method:'POST', body: form, credentials:'same-origin' });
      const data = await res.json();
      if (!data || !data.ok){ alert(data && data.error ? data.error : 'No se pudo enviar'); }
      else { if (txt) txt.value=''; if (fil) fil.value=''; fetchMessages(); if (typeof fetchList==='function') fetchList(); }
    }catch(e){ alert('Error de red al enviar'); }
    finally{ if (btn) btn.disabled=false; }
  }
  if (btn){ btn.addEventListener('click', e=>{ e.preventDefault(); sendReply(); }); }
  if (txt){ txt.addEventListener('keydown', e=>{ if (e.key==='Enter' && (e.ctrlKey||e.metaKey)){ e.preventDefault(); sendReply(); } }); }

  // -------- Lista (auto-refresh) --------
  const listBox = document.getElementById('ticketList');
  let listTimer=null; const POLL_LIST_MS=7000;
  function statusChipClass(st){ return st==='open'?'green':(st==='pending'?'yellow':(st==='resolved'?'blue':'red')); }
  function renderList(rows){
    const activeLink = document.querySelector('.ticket.active');
    const activeId = activeLink ? parseInt(activeLink.getAttribute('data-id')||'0',10) : 0;
    if (!Array.isArray(rows) || !rows.length){ listBox.innerHTML='<div class="empty">No hay tickets con ese filtro.</div>'; return; }
    let html='';
    for (const t of rows){
      const tid = Number(t.id);
      const title = (t.public_id && t.public_id!=='') ? t.public_id : ('#'+tid);
      const chipClass = statusChipClass(t.status);
      const name = t.user_full_name && t.user_full_name!=='' ? ' · '+esc(t.user_full_name) : '';
      const dot = (Number(t.unread_admin||0) ? '<span class="dot" title="Mensajes sin leer"></span>' : '');
      const isActive = (tid===activeId);
      const href = `?ticket=${tid}&status=${encodeURIComponent(listBox.getAttribute('data-status')||'open')}&q=${encodeURIComponent(listBox.getAttribute('data-q')||'')}&limit=${encodeURIComponent(listBox.getAttribute('data-limit')||'100')}`;
      html += `<a class="ticket ${isActive?'active':''}" data-id="${tid}" href="${href}">
        <div class="row"><div><strong>${esc(title)}</strong>${dot}</div><div class="chip ${chipClass}">${esc(t.status)}</div></div>
        <div class="muted">user_id: ${Number(t.user_id)}${name}</div>
        <div class="muted">últ. msg: ${esc(t.last_message_at||'')}</div></a>`;
    }
    listBox.innerHTML = html;
  }
  async function fetchList(){
    try{
      const u = new URL(window.location.href);
      u.searchParams.set('ajax','tickets');
      u.searchParams.set('status', listBox.getAttribute('data-status')||'open');
      u.searchParams.set('q', listBox.getAttribute('data-q')||'');
      u.searchParams.set('limit', listBox.getAttribute('data-limit')||'100');
      const res = await fetch(u.toString(), { headers:{'Accept':'application/json'}, credentials:'same-origin' });
      const data = await res.json(); if (!data || !data.ok || !Array.isArray(data.tickets)) return;
      renderList(data.tickets);
    }catch(e){}
  }
  function startList(){ stopList(); if (!document.hidden){ listTimer=setInterval(fetchList,POLL_LIST_MS); } }
  function stopList(){ if (listTimer){ clearInterval(listTimer); listTimer=null; } }
  window.fetchList = fetchList;
  fetchList().then(startList);
  document.addEventListener('visibilitychange', ()=>{ if (document.hidden) stopList(); else startList(); });

  // -------- Cerrar (resolved) + ENVIAR NOTIFICACIÓN AL USUARIO --------
  const btnClose = document.getElementById('btnCloseTicket');
  if (btnClose && box){
    const ticketId = parseInt(box.getAttribute('data-ticket')||'0',10);
    btnClose.addEventListener('click', async (e)=>{
      e.preventDefault(); if (!ticketId) return;
      if (!confirm('¿Marcar este ticket como RESUELTO?')) return;
      btnClose.disabled = true;
      try{
        // 1) Cambiar estado a 'resolved'
        const u = new URL(window.location.href);
        u.searchParams.set('ajax','close');
        const form = new FormData(); form.append('ticket_id', String(ticketId));
        const res = await fetch(u.toString(), { method:'POST', body: form, credentials:'same-origin' });
        const data = await res.json();
        if (!data || !data.ok){ alert(data && data.error ? data.error : 'No se pudo cerrar el ticket'); return; }

        // 2) Enviar SIEMPRE la notificación simple al usuario
        const notify = new FormData();
        notify.append('ticket_id', String(ticketId));
        notify.append('message', 'Tu ticket fue marcado como RESUELTO. ¡Gracias por contactarnos!');
        try {
          const postReq = await fetch('api/admin_support.php?debug=1', { method:'POST', body: notify, credentials:'same-origin' });
          const postData = await postReq.json();
          if (!postData || !postData.ok) { console.warn('No se pudo insertar la notificación de cierre'); }
        } catch(e){ console.warn('Error de red al insertar notificación de cierre'); }

        // 3) Actualizar UI
        const reply = document.querySelector('.replybar'); if (reply) reply.style.display='none';
        const chip = document.querySelector('.toolbar .chip'); if (chip){ chip.className='chip '+statusChipClass('resolved'); chip.textContent='resolved'; }
        if (typeof fetchList==='function') fetchList();
        if (typeof fetchMessages==='function') fetchMessages();
      }catch(e){ alert('Error de red al cerrar ticket'); }
      finally{ btnClose.disabled = false; }
    });
  }
})();
</script>

</body>
</html>

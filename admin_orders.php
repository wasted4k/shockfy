<?php
// admin_orders.php — Panel admin para revisar solicitudes de pago manual + Soporte.
//
// Requisitos:
// - db.php (PDO $pdo)
// - auth_check.php (pobla $currentUser y sesión)
// - APP_SLUG: si tu app vive en subcarpeta (p. ej. /shockfy)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

// Verificar rol admin (ajusta si usas otro campo/valor)
if (empty($currentUser['role']) || strtolower($currentUser['role']) !== 'admin') {
  http_response_code(403);
  echo 'Acceso denegado';
  exit;
}

// ----- Config base del proyecto (usado por el panel de soporte) -----
if (!defined('APP_SLUG')) {
  // Si tu app vive en /shockfy deja así. Si la movieras a raíz, pon ''.
  define('APP_SLUG', '/shockfy');
}

const PAGE_SIZE = 15;
const PREMIUM_PLAN_CODE = 'starter';

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

// Filtros
$status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'pending';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * PAGE_SIZE;

$validStatuses = ['pending','approved','rejected','all'];
if (!in_array($status, $validStatuses, true)) { $status = 'pending'; }

// POST acciones
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $pr_id  = (int)($_POST['id'] ?? 0);
  $token  = $_POST['csrf'] ?? '';

  if (!hash_equals($CSRF, $token)) {
    $flash = ['type'=>'error','msg'=>'CSRF inválido. Refresca la página e inténtalo de nuevo.'];
  } elseif ($pr_id <= 0) {
    $flash = ['type'=>'error','msg'=>'ID inválido.'];
  } else {
    // Leer PR + Usuario
    $stmt = $pdo->prepare("
      SELECT pr.id, pr.user_id, pr.status, pr.method, pr.amount_usd, pr.currency, pr.receipt_path,
             u.plan, u.account_state, u.email, u.full_name
      FROM payment_requests pr
      JOIN users u ON u.id = pr.user_id
      WHERE pr.id = :id
      LIMIT 1
    ");
    $stmt->execute(['id' => $pr_id]);
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
      $flash = ['type'=>'error','msg'=>'Solicitud no encontrada.'];
    } else {
      try {
        $pdo->beginTransaction();

        if ($action === 'approve') {
          // Aprobar solicitud
          $pdo->prepare("UPDATE payment_requests SET status='approved', updated_at=UTC_TIMESTAMP() WHERE id=:id")
              ->execute(['id' => $pr_id]);

          // Activar cuenta, asignar plan y fijar 30 días de vigencia
          $pdo->prepare("
            UPDATE users
            SET account_state = 'active',
                plan = :plan,
                premium_started_at = UTC_TIMESTAMP(),
                premium_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY)
            WHERE id = :uid
          ")->execute([
            'uid'  => $pr['user_id'],
            'plan' => PREMIUM_PLAN_CODE, // 'starter' por defecto
          ]);

          $pdo->commit();
          $flash = ['type'=>'success','msg'=>"Solicitud #{$pr_id} aprobada. Plan ".PREMIUM_PLAN_CODE." activo por 30 días."];

        } elseif ($action === 'reject') {
          // Rechazar solicitud
          $pdo->prepare("UPDATE payment_requests SET status='rejected', updated_at=UTC_TIMESTAMP() WHERE id=:id")
              ->execute(['id' => $pr_id]);
          // Salir de pending -> active (plan no se toca)
          $pdo->prepare("UPDATE users SET account_state='active' WHERE id=:uid")
              ->execute(['uid' => $pr['user_id']]);

          $pdo->commit();
          $flash = ['type'=>'success','msg'=>"Solicitud #{$pr_id} rechazada. El usuario salió de 'pendiente'."];
        } else {
          $pdo->rollBack();
          $flash = ['type'=>'error','msg'=>'Acción no reconocida.'];
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $flash = ['type'=>'error','msg'=>'Error de servidor: '.$e->getMessage()];
      }
    }
  }

  // PRG pattern
  $qs = http_build_query([
    'status' => $status,
    'q'      => $search,
    'page'   => $page,
    'flash'  => $flash ? base64_encode(json_encode($flash)) : null,
  ]);
  header("Location: admin_orders.php?$qs");
  exit;
}

// Leer flash si viene por GET
if (!empty($_GET['flash'])) {
  $tmp = json_decode(base64_decode($_GET['flash']), true);
  if (is_array($tmp)) { $flash = $tmp; }
}

// WHERE
$where  = [];
$params = [];
if ($status !== 'all') {
  $where[] = 'pr.status = :status';
  $params['status'] = $status;
}
if ($search !== '') {
  $where[] = '(u.email LIKE :q OR u.username LIKE :q OR u.full_name LIKE :q OR pr.id = :qid)';
  $params['q']   = '%'.$search.'%';
  $params['qid'] = ctype_digit($search) ? (int)$search : 0;
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// count
$stCount = $pdo->prepare("
  SELECT COUNT(*)
  FROM payment_requests pr
  JOIN users u ON u.id = pr.user_id
  $whereSql
");
$stCount->execute($params);
$total = (int)$stCount->fetchColumn();

// data
$sql = "
  SELECT pr.id, pr.user_id, pr.method, pr.amount_usd, pr.currency, pr.notes, pr.receipt_path, pr.status, pr.created_at,
         u.full_name, u.username, u.email, u.plan, u.account_state
  FROM payment_requests pr
  JOIN users u ON u.id = pr.user_id
  $whereSql
  ORDER BY pr.created_at DESC, pr.id DESC
  LIMIT :lim OFFSET :off
";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $st->bindValue(':'.$k, $v); }
$st->bindValue(':lim', PAGE_SIZE, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// helpers
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function chip($status){
  $status = strtolower($status);
  if ($status==='pending')  return ['🟠','Pendiente','#f59e0b','#fff7ed','#f59e0b'];
  if ($status==='approved') return ['🟢','Aprobado','#16a34a','#ecfdf5','#16a34a'];
  if ($status==='rejected') return ['🔴','Rechazado','#dc2626','#fef2f2','#dc2626'];
  return ['🔷',ucfirst($status),'#2563eb','#eff6ff','#2563eb'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin · Solicitudes de pago</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="stylesheet" href="style.css">

<style>
/* ========== Estilos mínimos del panel de soporte (admin) ========== */
.sap{ margin-top:22px; background:#0f172a; border:1px solid #1f2937; border-radius:14px; color:#e5e7eb; }
.sap-header{ display:flex; justify-content:space-between; align-items:center; padding:12px 14px; border-bottom:1px solid #1f2937; }
.sap-header h2{ margin:0; font-size:18px; }
.sap-controls{ display:flex; gap:10px; align-items:center; }
.sap-btn{ padding:8px 12px; border-radius:10px; border:1px solid #374151; background:#1f2937; color:#e5e7eb; cursor:pointer; }
.sap-btn:hover{ filter:brightness(1.08); }
.sap-btn.brand{ background:#16a34a; border-color:#22c55e; color:#fff; }
.sap-btn.danger{ background:#b91c1c; border-color:#dc2626; color:#fff; }
.sap-autorefresh{ font-size:13px; color:#9ca3af; display:flex; align-items:center; gap:6px; }

.sap-grid{ display:grid; grid-template-columns: 320px 1fr; gap:0; min-height:420px; }
@media (max-width: 980px){ .sap-grid{ grid-template-columns: 1fr; } }

.sap-list{ border-right:1px solid #1f2937; max-height:60vh; overflow:auto; }
@media (max-width: 980px){ .sap-list{ max-height:unset; } }

#sapTickets{ list-style:none; margin:0; padding:0; }
#sapTickets li{ border-bottom:1px solid #1f2937; padding:10px 12px; display:flex; gap:10px; align-items:center; cursor:pointer; }
#sapTickets li:hover{ background:#111827; }
#sapTickets li.active{ background:#0b1220; box-shadow: inset 0 0 0 1px #22c55e33; }
.ticket-main{ display:flex; flex-direction:column; gap:3px; min-width:0; }
.ticket-title{ font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ticket-meta{ font-size:12px; color:#9ca3af; display:flex; gap:8px; flex-wrap:wrap; }
.ticket-badges{ margin-left:auto; display:flex; gap:8px; align-items:center; }
.badge{ font-size:11px; padding:2px 6px; border-radius:999px; border:1px solid #374151; color:#e5e7eb; }
.badge.unread{ background:#14532d; border-color:#16a34a; }

.sap-empty{ padding:12px; text-align:center; color:#9ca3af; }

.sap-thread{ display:flex; flex-direction:column; min-height:420px; }
.sap-thread-header{ display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-bottom:1px solid #1f2937; }
.sap-thread-title{ font-weight:700; }
.sap-thread-meta{ font-size:12px; color:#9ca3af; }

.sap-messages{ padding:12px; display:flex; flex-direction:column; gap:10px; height:48vh; overflow:auto; }
@media (max-width: 980px){ .sap-messages{ height:40vh; } }
.sap-placeholder{ color:#9ca3af; }

.msg{ max-width:78%; padding:8px 10px; border-radius:12px; border:1px solid #374151; background:#111827; }
.msg.admin{ margin-left:auto; background:#0b3b2f; border-color:#16a34a66; }
.msg .who{ font-weight:600; font-size:13px; margin-bottom:2px; }
.msg .text{ white-space:pre-wrap; word-break:break-word; }
.msg .att{ margin-top:6px; font-size:13px; }
.msg .att a{ color:#93c5fd; }

.sap-reply{ display:grid; gap:8px; border-top:1px solid #1f2937; padding:10px; }
.sap-reply textarea{ min-height:70px; padding:.6rem .7rem; border-radius:12px; border:1px solid #374151; background:#0b1220; color:#fff; outline:none; resize:vertical; }
.sap-reply-tools{ display:flex; align-items:center; gap:10px; }
.sap-attach{ padding:6px 10px; border:1px solid #374151; border-radius:10px; background:#111827; color:#e5e7eb; cursor:pointer; }
.sap-attach input{ display:none; }
.sap-hint{ font-size:12px; color:#9ca3af; }
</style>

  <style>
    :root{
      --sidebar-w:260px; --bg:#f5f7fb; --card:#ffffff; --text:#0f172a; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb; --shadow:0 16px 32px rgba(2,6,23,.08); --radius:16px;
      --green:#16a34a; --green-strong:#15803d; --red:#dc2626; --red-strong:#b91c1c; --blue:#2563eb; --blue-strong:#1d4ed8;
    }
    body{ margin:0; background:var(--bg); color:var(--text); font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
    .app-shell{ display:flex; min-height:100vh; }
    .content{ flex:1; padding:28px 24px 48px; }
    .with-fixed-sidebar{ margin-left:var(--sidebar-w); }
    .container{ max-width: min(1600px, 96vw); margin:0 auto; }

    .card{ background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow); padding:18px; }
    h1{ margin:0 0 4px; font-size:24px; font-weight:900; letter-spacing:-0.01em; }
    .subtitle{ color:var(--muted); margin:0; }

    .toolbar{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; margin:14px 0; }
    .filters{ display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .field{ display:flex; flex-direction:column; gap:6px; }
    .field label{ font-size:12px; color:var(--muted); font-weight:700; }
    .select, .input{
      padding:12px 12px; border-radius:12px; border:1px solid var(--border); background:#fff; min-width:220px;
      font-weight:600;
    }
    .btn{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      padding:10px 14px; border-radius:12px; border:1px solid transparent; cursor:pointer; font-weight:800; text-decoration:none;
      transition:transform .04s ease, box-shadow .2s ease;
    }
    .btn:active{ transform: translateY(1px); }

    .btn.primary{ background:var(--blue); color:#fff; border-color:var(--blue-strong); }
    .btn.primary:hover{ background:var(--blue-strong); }
    .btn.approve{ background:var(--green); color:#fff; border-color:var(--green-strong); }
    .btn.approve:hover{ background:var(--green-strong); }
    .btn.reject{ background:var(--red); color:#fff; border-color:var(--red-strong); }
    .btn.reject:hover{ background:var(--red-strong); }
    .btn.ghost{ background:#fff; color:#111827; border:1px solid var(--border); }

    .table-wrap{ overflow-x: auto; border-radius:16px; border:1px solid var(--border); background:#fff; }
    table{ width:100%; border-collapse:separate; border-spacing:0; }
    thead th{
      position:sticky; top:0; z-index:5; background:#f8fafc; color:#475569; font-size:12px; letter-spacing:.04em; text-transform:uppercase;
      padding:12px; border-bottom:1px solid var(--border);
    }
    tbody td{ padding:12px 10px; border-bottom:1px solid var(--border); vertical-align:top; }
    tbody tr:hover{ background:#fafafa; }
    tbody tr:last-child td{ border-bottom:0; }
    td .muted{ color:#6b7280; font-size:12px; }
    .nowrap{ white-space:nowrap; }
    .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:420px; display:inline-block; vertical-align:bottom; }

    .chip{
      display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; font-weight:900; font-size:12px; border:1px solid transparent;
      white-space:nowrap;
    }

    .row-actions{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-start; }
    .flash{ padding:10px 12px; border-radius:12px; margin-bottom:12px; font-weight:800; }
    .flash.success{ background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
    .flash.error{ background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

    .pagination{ display:flex; gap:8px; justify-content:flex-end; margin-top:14px; }
    .pagination a, .pagination span{ padding:10px 14px; border-radius:12px; border:1px solid var(--border); background:#fff; text-decoration:none; color:#111827; font-weight:800; }
    .pagination .active{ background:#111827; color:#fff; border-color:#111827; }
  </style>
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="content with-fixed-sidebar">
      <div class="container">
        <div class="card" style="margin-bottom:12px;">
          <h1>Solicitudes de pago manual</h1>
          <p class="subtitle">Revisa comprobantes y confirma o rechaza pagos.</p>
        </div>

        <div class="card">
          <?php if ($flash): ?>
            <div class="flash <?= esc($flash['type']) ?>"><?= esc($flash['msg']) ?></div>
          <?php endif; ?>

          <form class="toolbar" method="get" action="admin_orders.php">
            <div class="filters">
              <div class="field">
                <label>Estado</label>
                <select name="status" class="select" onchange="this.form.submit()">
                  <option value="pending"  <?= $status==='pending'?'selected':''; ?>>Pendiente</option>
                  <option value="approved" <?= $status==='approved'?'selected':''; ?>>Aprobado</option>
                  <option value="rejected" <?= $status==='rejected'?'selected':''; ?>>Rechazado</option>
                  <option value="all"      <?= $status==='all'?'selected':''; ?>>Todos</option>
                </select>
              </div>
              <div class="field">
                <label>Buscar</label>
                <input class="input" type="text" name="q" placeholder="email, usuario, nombre o #ID" value="<?= esc($search) ?>">
              </div>
            </div>
            <div>
              <button class="btn ghost" type="submit">Filtrar</button>
            </div>
          </form>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th class="nowrap">#</th>
                  <th>Usuario</th>
                  <th>Contacto</th>
                  <th>Solicitud</th>
                  <th>Comprobante</th>
                  <th>Estado</th>
                  <th class="nowrap">Creado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr><td colspan="8" class="muted" style="text-align:center; padding:20px;">No hay resultados</td></tr>
                <?php else: foreach ($rows as $r):
                  list($icon,$label,$fg,$bg,$bd) = chip($r['status']); ?>
                  <tr>
                    <td class="nowrap"><?= (int)$r['id'] ?></td>
                    <td>
                      <div class="ellipsis" title="<?= esc($r['full_name'] ?: $r['username']) ?>">
                        <strong><?= esc($r['full_name'] ?: $r['username']) ?></strong>
                      </div>
                      <div class="muted">@<?= esc($r['username']) ?></div>
                    </td>
                    <td>
                      <div class="ellipsis" title="<?= esc($r['email']) ?>"><?= esc($r['email']) ?></div>
                      <div class="muted">Plan: <strong><?= esc($r['plan'] ?: 'free') ?></strong> · Estado: <strong><?= esc($r['account_state'] ?: 'active') ?></strong></div>
                    </td>
                    <td>
                      <div><strong><?= strtoupper(esc($r['method'])) ?></strong> · $<?= number_format((float)$r['amount_usd'], 2) ?> (<?= esc($r['currency']) ?>)</div>
                      <?php if (!empty($r['notes'])): ?>
                        <div class="muted ellipsis" title="<?= esc($r['notes']) ?>">Notas: <?= esc($r['notes']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($r['receipt_path'])): ?>
                        <a class="btn primary" href="<?= esc($r['receipt_path']) ?>" target="_blank" rel="noopener noreferrer">Ver comprobante</a>
                      <?php else: ?>
                        <span class="muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="chip" style="background:<?= $bg ?>; color:<?= $fg ?>; border-color:<?= $bd ?>;">
                        <span><?= $icon ?></span><?= esc($label) ?>
                      </span>
                    </td>
                    <td class="nowrap"><?= esc($r['created_at']) ?></td>
                    <td class="row-actions">
                      <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" onsubmit="return confirm('¿Aprobar la solicitud #<?= (int)$r['id'] ?>?');" style="display:inline;">
                          <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
                          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                          <input type="hidden" name="action" value="approve">
                          <button class="btn approve" type="submit">Aprobar</button>
                        </form>
                        <form method="post" onsubmit="return confirm('¿Rechazar la solicitud #<?= (int)$r['id'] ?>?');" style="display:inline;">
                          <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
                          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                          <input type="hidden" name="action" value="reject">
                          <button class="btn reject" type="submit">Rechazar</button>
                        </form>
                      <?php else: ?>
                        <span class="muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

<!-- ========== Panel de Soporte (Admin) ========== -->
<section id="supportAdminPanel" class="sap">
  <header class="sap-header">
    <h2>Soporte (Chats de usuarios)</h2>
    <div class="sap-controls">
      <select id="sapStatus">
        <option value="open" selected>Abiertos</option>
        <option value="resolved">Resueltos</option>
      </select>
      <button class="sap-btn" id="sapRefresh" title="Recargar">⟲</button>
      <label class="sap-autorefresh">
        <input type="checkbox" id="sapAuto"> Autorefresco (15s)
      </label>
    </div>
  </header>

  <div class="sap-grid">
    <!-- Columna izquierda: lista de tickets -->
    <aside class="sap-list">
      <ul id="sapTickets"></ul>
      <div class="sap-empty" id="sapEmptyList" style="display:none;">No hay tickets.</div>
    </aside>

    <!-- Columna derecha: hilo y acciones -->
    <main class="sap-thread">
      <div id="sapThreadHeader" class="sap-thread-header">
        <div>
          <div class="sap-thread-title">Selecciona un ticket</div>
          <div class="sap-thread-meta" id="sapThreadMeta"></div>
        </div>
        <div class="sap-thread-actions">
          <button class="sap-btn danger" id="sapResolve" disabled>Finalizar caso</button>
        </div>
      </div>

      <div id="sapMessages" class="sap-messages">
        <div class="sap-placeholder">Selecciona un ticket a la izquierda para ver el chat.</div>
      </div>

      <form id="sapReplyForm" class="sap-reply" enctype="multipart/form-data">
        <textarea id="sapReplyText" placeholder="Escribe una respuesta para el usuario..." disabled></textarea>
        <div class="sap-reply-tools">
          <label class="sap-attach">
            📎 Adjuntar
            <input type="file" id="sapReplyFile" accept=".png,.jpg,.jpeg,.pdf">
          </label>
          <span class="sap-hint">Máx. 2&nbsp;MB</span>
          <button class="sap-btn brand" id="sapSend" disabled>Enviar</button>
        </div>
      </form>
    </main>
  </div>
</section>
<!-- ========== /Panel de Soporte (Admin) ========== -->

<script>
// Exponer APP_SLUG a JS para construir URLs absolutas al API admin
window.APP_SLUG = <?php echo json_encode(APP_SLUG); ?>;
window.API_ADMIN_SUPPORT = window.location.origin + (window.APP_SLUG ? window.APP_SLUG.replace(/\/$/,'') : '') + '/api/support_admin.php';

// Helper robusto para parsear JSON (evita "Unexpected end of JSON input")
async function parseJsonResponse(res){
  const raw = await res.text();
  let data = null;
  try { data = raw ? JSON.parse(raw) : null; }
  catch {
    console.error('Respuesta no JSON:', raw?.slice(0,400));
    throw new Error('Respuesta no válida del servidor');
  }
  if (!res.ok) {
    const msg = data?.error || `HTTP ${res.status}`;
    throw new Error(msg);
  }
  return data;
}
</script>

<script>
(function(){
  const $  = s => document.querySelector(s);
  const $$ = s => Array.from(document.querySelectorAll(s));

  const listEl   = $('#sapTickets');
  const emptyEl  = $('#sapEmptyList');
  const statusEl = $('#sapStatus');
  const refreshEl= $('#sapRefresh');
  const autoEl   = $('#sapAuto');

  const threadTitle = $('#sapThreadHeader .sap-thread-title');
  const threadMeta  = $('#sapThreadMeta');
  const msgsEl      = $('#sapMessages');
  const replyForm   = $('#sapReplyForm');
  const replyText   = $('#sapReplyText');
  const replyFile   = $('#sapReplyFile');
  const sendBtn     = $('#sapSend');
  const resolveBtn  = $('#sapResolve');

  const API = {
    list:   (status='open') => fetch(`${window.API_ADMIN_SUPPORT}?action=list&status=${encodeURIComponent(status)}`).then(parseJsonResponse),
    thread: (ticketId)      => fetch(`${window.API_ADMIN_SUPPORT}?action=thread&ticket_id=${ticketId}`).then(parseJsonResponse),
    reply:  (ticketId, text, file) => {
      const fd = new FormData();
      fd.append('action','reply');
      fd.append('ticket_id', String(ticketId));
      fd.append('message', text);
      if (file) fd.append('file', file);
      return fetch(window.API_ADMIN_SUPPORT, { method:'POST', body: fd }).then(parseJsonResponse);
    },
    resolve:(ticketId) => {
      const fd = new FormData();
      fd.append('action','resolve');
      fd.append('ticket_id', String(ticketId));
      return fetch(window.API_ADMIN_SUPPORT, { method:'POST', body: fd }).then(parseJsonResponse);
    }
  };

  let state = {
    status: 'open',
    tickets: [],
    selected: null,
    pollTimer: null
  };

  function fmtDate(iso){
    if (!iso) return '';
    // Si tu DB guarda UTC, puedes añadir 'Z' para forzar parse como UTC:
    const d = new Date(iso.replace(' ','T') + 'Z');
    return d.toLocaleString();
  }

  function renderTickets(){
    listEl.innerHTML = '';
    if (!state.tickets.length){
      emptyEl.style.display = 'block';
      return;
    }
    emptyEl.style.display = 'none';
    state.tickets.forEach(t=>{
      const li = document.createElement('li');
      li.dataset.id = t.id;
      li.className = (state.selected === t.id ? 'active' : '');
      li.innerHTML = `
        <div class="ticket-main">
          <div class="ticket-title">${t.public_id} · ${t.full_name ?? ('Usuario #'+t.user_id)}</div>
          <div class="ticket-meta">
            <span>${t.status}</span>
            <span>Último: ${fmtDate(t.last_message_at)}</span>
          </div>
        </div>
        <div class="ticket-badges">
          ${Number(t.unread_admin) ? '<span class="badge unread">Nuevo</span>' : ''}
        </div>
      `;
      li.addEventListener('click', ()=> selectTicket(t.id));
      listEl.appendChild(li);
    });
  }

  async function loadTickets(){
    try{
      const data = await API.list(state.status);
      if (!data.ok){ throw new Error(data.error || 'Error list'); }
      state.tickets = data.tickets || [];
      renderTickets();
    }catch(e){
      console.error(e);
      listEl.innerHTML = '<li>Error al cargar tickets</li>';
    }
  }

  function clearThread(){
    threadTitle.textContent = 'Selecciona un ticket';
    threadMeta.textContent = '';
    msgsEl.innerHTML = '<div class="sap-placeholder">Selecciona un ticket a la izquierda para ver el chat.</div>';
    replyText.disabled = true; replyFile.disabled = true; sendBtn.disabled = true; resolveBtn.disabled = true;
    state.selected = null;
  }

  function renderThread(ticket, msgs){
    threadTitle.textContent = `${ticket.public_id} — ${ticket.full_name ?? ('Usuario #'+ticket.user_id)}`;
    threadMeta.textContent  = `Estado: ${ticket.status} · Último mensaje: ${fmtDate(ticket.last_message_at)} · Creado: ${fmtDate(ticket.created_at)}`;

    msgsEl.innerHTML = '';
    msgs.forEach(m=>{
      const div = document.createElement('div');
      div.className = 'msg ' + (m.sender === 'admin' ? 'admin' : '');
      const who = (m.sender === 'admin') ? 'Admin' : 'Usuario';
      const safe = (m.message || '').replace(/[&<>]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[s]));
      const att = m.file_path ? `<div class="att">Adjunto: <a href="${m.file_path}" target="_blank" rel="noopener">Ver archivo</a></div>` : '';
      div.innerHTML = `
        <div class="who">${who} · <small>${fmtDate(m.created_at)}</small></div>
        <div class="text">${safe}</div>
        ${att}
      `;
      msgsEl.appendChild(div);
    });
    msgsEl.scrollTop = msgsEl.scrollHeight + 120;

    const resolved = (ticket.status === 'resolved');
    replyText.disabled = resolved; replyFile.disabled = resolved; sendBtn.disabled = resolved;
    resolveBtn.disabled = resolved;
  }

  async function selectTicket(id){
    state.selected = id;
    $$('#sapTickets li').forEach(li => li.classList.toggle('active', Number(li.dataset.id) === id));
    try{
      const data = await API.thread(id);
      if (!data.ok){ throw new Error(data.error || 'Error thread'); }
      renderThread(data.ticket, data.messages || []);
      // marcar como leído en UI
      const t = state.tickets.find(x => x.id === id);
      if (t){ t.unread_admin = 0; }
      renderTickets();
    }catch(e){
      console.error(e);
      msgsEl.innerHTML = '<div class="sap-placeholder">No se pudo cargar el hilo.</div>';
    }
  }

  async function sendReply(e){
    e.preventDefault();
    const id = state.selected;
    if (!id) return;

    const text = (replyText.value || '').trim();
    const file = replyFile.files && replyFile.files[0];

    if (!text && !file){
      alert('Escribe un mensaje o adjunta un archivo.');
      return;
    }
    // Límite 2MB (refuerzo en front)
    if (file && file.size > 2 * 1024 * 1024){
      alert('Adjunto supera 2 MB.');
      return;
    }

    sendBtn.disabled = true;
    try{
      const res = await API.reply(id, text, file || null);
      if (!res.ok){ throw new Error(res.error || 'Error reply'); }
      replyText.value = '';
      if (replyFile) replyFile.value = '';

      // refresca hilo
      const data = await API.thread(id);
      if (data.ok){ renderThread(data.ticket, data.messages || []); }

      // mueve el ticket arriba por actividad
      await loadTickets();
    }catch(e){
      console.error(e);
      alert('No se pudo enviar la respuesta.');
    }finally{
      sendBtn.disabled = false;
    }
  }

  async function resolveTicket(){
    const id = state.selected;
    if (!id) return;
    if (!confirm('¿Finalizar este caso?')) return;

    resolveBtn.disabled = true;
    try{
      const res = await API.resolve(id);
      if (!res.ok){ throw new Error(res.error || 'Error resolve'); }

      // refresca hilo (quedará como read-only)
      const data = await API.thread(id);
      if (data.ok){ renderThread(data.ticket, data.messages || []); }

      // si estás en “Abiertos”, recarga y desaparecerá de la lista
      if (state.status === 'open'){
        await loadTickets();
        clearThread();
      } else {
        await loadTickets();
      }
    }catch(e){
      console.error(e);
      alert('No se pudo finalizar el caso.');
    }finally{
      resolveBtn.disabled = false;
    }
  }

  // Eventos UI
  statusEl.addEventListener('change', async ()=>{
    state.status = statusEl.value;
    clearThread();
    await loadTickets();
  });
  refreshEl.addEventListener('click', loadTickets);
  replyForm.addEventListener('submit', sendReply);
  resolveBtn.addEventListener('click', resolveTicket);

  // Autorefresco opcional
  autoEl.addEventListener('change', ()=>{
    if (state.pollTimer){ clearInterval(state.pollTimer); state.pollTimer = null; }
    if (autoEl.checked){
      state.pollTimer = setInterval(async ()=>{
        const prevSel = state.selected;
        await loadTickets();
        if (prevSel){
          try{
            const data = await API.thread(prevSel);
            if (data.ok){ renderThread(data.ticket, data.messages || []); }
          }catch(e){}
        }
      }, 15000);
    }
  });

  // Inicial
  clearThread();
  loadTickets();
})();
</script>

          <?php
            $totalPages = (int)ceil($total / PAGE_SIZE);
            if ($totalPages > 1):
          ?>
          <div class="pagination">
            <?php for ($p=1; $p <= $totalPages; $p++):
              $isActive = ($p === $page);
              $qs = http_build_query(['status'=>$status, 'q'=>$search, 'page'=>$p]);
            ?>
              <?php if ($isActive): ?>
                <span class="active"><?= $p ?></span>
              <?php else: ?>
                <a href="admin_orders.php?<?= $qs ?>"><?= $p ?></a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</body>
</html>

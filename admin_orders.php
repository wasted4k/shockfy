<?php
// admin_orders.php — Panel admin SOLO para aprobar/rechazar pagos manuales.

// 1) Sesión primero (para que auth_check.php vea $_SESSION)
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';

// Usuario y rol
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

// Verificar rol admin (ajusta si usas otro campo/valor)
if (empty($currentUser['role']) || strtolower($currentUser['role']) !== 'admin') {
  http_response_code(403);
  echo 'Acceso denegado';
  exit;
}

// ===== Configuración mínima =====
const PAGE_SIZE = 15;
const PREMIUM_PLAN_CODE = 'starter'; // plan activado al aprobar

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

// ===== Acciones POST (aprobar/rechazar) =====
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
    // Leer solicitud + usuario
    $stmt = $pdo->prepare("
      SELECT pr.id, pr.user_id, pr.status, pr.method, pr.amount_usd, pr.currency, pr.receipt_path,
             u.plan, u.account_state
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
          $pdo->prepare("
            UPDATE payment_requests
            SET status='approved', updated_at=UTC_TIMESTAMP()
            WHERE id=:id
          ")->execute(['id' => $pr_id]);

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
            'plan' => PREMIUM_PLAN_CODE,
          ]);

          $pdo->commit();
          $flash = ['type'=>'success','msg'=>"Solicitud #{$pr_id} aprobada. Plan ".PREMIUM_PLAN_CODE." activo por 30 días."];

        } elseif ($action === 'reject') {
          // Rechazar solicitud
          $pdo->prepare("
            UPDATE payment_requests
            SET status='rejected', updated_at=UTC_TIMESTAMP()
            WHERE id=:id
          ")->execute(['id' => $pr_id]);

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

// ===== Consultas (listado y paginación) =====
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

  <!-- Estilos de la página (diseño original preservado) -->
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

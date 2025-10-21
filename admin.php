<?php
// admin_users.php — Panel de administración (PDO) con acciones AJAX y UI sin recargar
// Versión mejorada: búsqueda en tiempo real, modal detalles, trial por defecto 7 días, solo roles Admin/User, planes Free/Starter

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$current_user_id = $_SESSION['user_id'] ?? 0;

// Asegurar PDO ($pdo). Si tu db.php ya lo define, se usa tal cual.
if (!isset($pdo) || !($pdo instanceof PDO)) {
  try {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $db   = getenv('DB_NAME') ?: 'shockfy';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $port = getenv('DB_PORT') ?: '3306';
    $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $pdo  = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (Throwable $e) {
    http_response_code(500);
    die('No hay conexión PDO. Define $pdo en db.php o variables DB_*.');
  }
}

// Solo admins
$st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$st->execute([$current_user_id]);
$me = $st->fetch();
if (!$me || $me['role'] !== 'admin') {
  http_response_code(403);
  die('Acceso denegado. Solo administradores.');
}

// ---------- Helpers ----------
function json_out($arr){ header('Content-Type: application/json'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function now_utc_str(){ return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'); }

// ---------- AJAX ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Agregar usuario
  if (isset($_POST['ajax_add_user'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = ($_POST['role'] === 'admin') ? 'admin' : 'user'; // forzar roles válidos

    if (!$full_name || !$username) json_out(['status'=>'error','msg'=>'Nombre y usuario son obligatorios.']);
    if (strlen($password) < 6)     json_out(['status'=>'error','msg'=>'La contraseña debe tener al menos 6 caracteres.']);

    $q = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $q->execute([$username]);
    if ($q->fetch()) json_out(['status'=>'error','msg'=>'Ese correo/usuario ya existe.']);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO users(full_name,username,password,role,status,plan) VALUES(?,?,?,?,1,'free')");
    if ($ins->execute([$full_name,$username,$hash,$role])) {
      $newId = $pdo->lastInsertId();
      $pdo->prepare("INSERT INTO user_logs (user_id, action) VALUES (?, ?)")->execute([$current_user_id, "Creó usuario ID ".$newId]);
      json_out(['status'=>'success','msg'=>'Usuario agregado correctamente.','id'=>$newId]);
    }
    json_out(['status'=>'error','msg'=>'Error al agregar el usuario.']);
  }

  // Editar usuario
  if (isset($_POST['ajax_edit_user'])) {
    $edit_id   = (int)($_POST['edit_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $role      = ($_POST['role'] === 'admin') ? 'admin' : 'user';
    $password  = $_POST['password'] ?? '';

    if (!$full_name || !$username) json_out(['status'=>'error','msg'=>'Nombre y usuario son obligatorios.']);

    $q = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
    $q->execute([$username,$edit_id]);
    if ($q->fetch()) json_out(['status'=>'error','msg'=>'El usuario ya está en uso.']);

    if ($edit_id === $current_user_id && $role !== 'admin') {
      json_out(['status'=>'error','msg'=>'No puedes cambiar tu propio rol de administrador.']);
    }
    if ($password && strlen($password)<6) {
      json_out(['status'=>'error','msg'=>'La contraseña debe tener al menos 6 caracteres.']);
    }

    if ($password) {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $up = $pdo->prepare("UPDATE users SET full_name=?, username=?, role=?, password=? WHERE id=?");
      $ok = $up->execute([$full_name,$username,$role,$hash,$edit_id]);
    } else {
      $up = $pdo->prepare("UPDATE users SET full_name=?, username=?, role=? WHERE id=?");
      $ok = $up->execute([$full_name,$username,$role,$edit_id]);
    }
    if ($ok){
      $pdo->prepare("INSERT INTO user_logs (user_id, action) VALUES (?, ?)")->execute([$current_user_id, "Editó usuario ID $edit_id"]);
      json_out(['status'=>'success','msg'=>'Usuario actualizado.','id'=>$edit_id,'full_name'=>$full_name,'username'=>$username,'role'=>$role]);
    }
    json_out(['status'=>'error','msg'=>'No se pudo actualizar.']);
  }

  // Activar/Desactivar usuario
  if (isset($_POST['ajax_toggle_user'])) {
    $id = (int)($_POST['toggle_id'] ?? 0);
    if ($id === $current_user_id) json_out(['status'=>'error','msg'=>'No puedes cambiar tu propio estado.']);

    $q = $pdo->prepare("SELECT status FROM users WHERE id=?");
    $q->execute([$id]);
    if (!$row = $q->fetch()) json_out(['status'=>'error','msg'=>'Usuario no existe.']);

    $new = $row['status'] ? 0 : 1;
    $u = $pdo->prepare("UPDATE users SET status=? WHERE id=?");
    $u->execute([$new,$id]);
    $pdo->prepare("INSERT INTO user_logs (user_id, action) VALUES (?, ?)")->execute([$current_user_id, "Cambió estado usuario $id a $new"]);
    json_out(['status'=>'success','msg'=>'Estado actualizado.','status_value'=>$new,'id'=>$id]);
  }

  // Eliminar usuario
  if (isset($_POST['ajax_delete_user'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id === $current_user_id) json_out(['status'=>'error','msg'=>'No puedes eliminarte a ti mismo.']);

    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    $pdo->prepare("INSERT INTO user_logs (user_id, action) VALUES (?, ?)")->execute([$current_user_id, "Eliminó usuario ID $id"]);
    json_out(['status'=>'success','msg'=>'Usuario eliminado.','id'=>$id]);
  }

  // Cambiar plan (toggle free <-> starter)
  if (isset($_POST['ajax_set_plan'])) {
    $id = (int)($_POST['user_id'] ?? 0);
    $plan = ($_POST['plan'] === 'starter') ? 'starter' : 'free';
    $pdo->prepare("UPDATE users SET plan=? WHERE id=?")->execute([$plan,$id]);
    $pdo->prepare("INSERT INTO user_logs (user_id, action) VALUES (?, ?)")->execute([$current_user_id, "Set plan=$plan a usuario $id"]);
    json_out(['status'=>'success','msg'=>"Plan actualizado a $plan.",'id'=>$id,'plan'=>$plan]);
  }

  // Finalizar trial (expira YA y fuerza plan free para que el gate bloquee)
  if (isset($_POST['ajax_end_trial'])) {
    $id = (int)($_POST['user_id'] ?? 0);
    $now = now_utc_str();
    $pdo->prepare("UPDATE users SET plan='free', trial_ends_at=? WHERE id=?")->execute([$now,$id]); // ← forzamos plan=free
    $pdo->prepare("INSERT INTO user_logs (user_id, action) VALUES (?, ?)")->execute([$current_user_id, "Finalizó trial usuario $id"]);
    json_out([
      'status'=>'success',
      'msg'=>"Trial finalizado.",
      'id'=>$id,
      'trial_ends_at'=>$now,
      'trial_active'=>false,
      'plan'=>'free'
    ]);
  }

  // Activar/renovar trial N días (no toca plan; sigue siendo free hasta que actives Starter)
  if (isset($_POST['ajax_set_trial_days'])) {
    $id   = (int)($_POST['user_id'] ?? 0);
    $days = max(1, (int)($_POST['days'] ?? 7)); // por defecto 7 días
    $start = new DateTime('now', new DateTimeZone('UTC'));
    $end   = (clone $start)->modify("+$days days");
    $pdo->prepare("UPDATE users SET trial_started_at=?, trial_ends_at=? WHERE id=?")
        ->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $id]);
    $pdo->prepare("INSERT INTO user_logs (user_id, action) VALUES (?, ?)")->execute([$current_user_id, "Activó/Ajustó trial usuario $id a $days días"]);
    json_out([
      'status'=>'success',
      'msg'=>"Trial activado por $days días.",
      'id'=>$id,
      'trial_ends_at'=>$end->format('Y-m-d H:i:s'),
      'trial_active'=>true,
      'days'=>$days
    ]);
  }

  // default
  json_out(['status'=>'error','msg'=>'Acción no reconocida.']);
}

// ---------- Filtros ----------
$params = [];
$where = " WHERE 1=1 ";
$role   = $_GET['role']   ?? '';
$status = $_GET['status'] ?? '';
$plan   = $_GET['plan']   ?? '';
$trial  = $_GET['trial']  ?? '';
$verif  = $_GET['verif']  ?? '';
$search = trim($_GET['search'] ?? '');

if ($role !== '')   { $where .= " AND role = ?"; $params[] = $role; }
if ($status !== '') { $where .= " AND status = ?"; $params[] = (int)$status; }
if ($plan !== '')   { $where .= " AND plan = ?"; $params[] = $plan; }
if ($verif !== '')  {
  if ($verif === '1') $where .= " AND email_verified_at IS NOT NULL";
  if ($verif === '0') $where .= " AND email_verified_at IS NULL";
}
if ($trial !== '')  {
  if ($trial === '1')  $where .= " AND trial_ends_at IS NOT NULL AND UTC_TIMESTAMP() <= trial_ends_at";
  if ($trial === '0')  $where .= " AND (trial_ends_at IS NULL OR UTC_TIMESTAMP() > trial_ends_at)";
}
if ($search !== '') {
  $where .= " AND (full_name LIKE ? OR username LIKE ?)";
  $like = "%$search%"; $params[]=$like; $params[]=$like;
}

$sql = "SELECT id, full_name, username, role, status, plan, trial_started_at, trial_ends_at, email_verified_at
        FROM users $where ORDER BY id DESC";
$st  = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

// Logs
$logs = $pdo->query("SELECT ul.*, u.username FROM user_logs ul JOIN users u ON ul.user_id=u.id ORDER BY ul.id DESC LIMIT 50")->fetchAll();

// Utils trial
function trial_meta($row): array {
  if (empty($row['trial_ends_at'])) return ['—', 'neutral'];
  $now = new DateTime('now', new DateTimeZone('UTC'));
  $end = new DateTime($row['trial_ends_at'], new DateTimeZone('UTC'));
  $active = $now <= $end;
  if ($active) return ["Activo · fin: ".$end->format('Y-m-d H:i').' UTC', 'ok'];
  return ["Expirado · fin: ".$end->format('Y-m-d H:i').' UTC', 'bad'];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Admin · Usuarios</title>
  <link rel="icon" href="assets/img/favicon.png" type="image/png">
  <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#f1f5f9; --panel:#ffffff; --panel-2:#f7fafc; --text:#0f172a; --muted:#64748b;
      --primary:#2563eb; --primary-2:#60a5fa; --success:#16a34a; --danger:#dc2626; --warn:#d97706;
      --border:#e2e8f0; --shadow:0 12px 26px rgba(15,23,42,.08); --radius:12px;
    }
    *{box-sizing:border-box}
    body{ margin:0; font-family:Inter,system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--text); }

    .page{ padding:20px 12px 64px; }
    .container{ max-width: 1200px; margin:0 auto; background: var(--panel); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px; }

    .header{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin:4px 0 12px; }
    .title{ display:flex; align-items:center; gap:12px; }
    .title h1{ margin:0; font-size:22px; font-weight:800; }
    .title .hint{ font-size:12px; color:var(--muted) }
    .actions-top .btn{ padding:9px 12px; border-radius:10px; border:1px solid var(--border); background:linear-gradient(135deg,var(--primary),var(--primary-2)); color:#fff; font-weight:800; box-shadow:var(--shadow); cursor:pointer; }

    /* ------- BARRA DE FILTROS ARRIBA ------- */
    .filters-bar{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap:8px; margin:8px 0 16px;
      background: var(--panel-2); border:1px solid var(--border); border-radius:12px; padding:10px;
    }
    .filters-bar .field{ display:flex; flex-direction:column; gap:6px; }
    .filters-bar label{ font-size:11px; color:#475569; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .filters-bar input, .filters-bar select{
      width:100%; padding:9px 10px; border-radius:10px; border:1px solid var(--border); background:#fff; outline:none; font-size:14px;
    }
    .filters-bar .actions{ display:flex; align-items:flex-end; gap:8px; }

    .btn{ padding:8px 12px; border-radius:10px; border:1px solid var(--border); background:#fff; font-weight:700; cursor:pointer; font-size:12px; }
    .btn.primary{ background:linear-gradient(135deg,var(--primary),var(--primary-2)); color:#fff; border:none; }
    .btn.danger{ background:linear-gradient(135deg,#ef4444,#f87171); color:#fff; border:none; }
    .btn.warn{ background:#fff7ed; border-color:#fde68a; color:#92400e; }
    .btn.soft{ background:#f8fafc; }

    /* ------- TABLA (sin scroll horizontal) ------- */
    .table-wrap{ border-radius:12px; border:1px solid var(--border); background:var(--panel); box-shadow:var(--shadow); overflow: hidden; }
    table{ width:100%; border-collapse:separate; border-spacing:0; table-layout: fixed; }
    thead th{
      background:var(--panel-2); border-bottom:1px solid var(--border);
      padding:10px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#475569; position:sticky; top:0; z-index:1;
      white-space:nowrap;
    }
    tbody td{ padding:10px; border-bottom:1px solid var(--border); vertical-align:middle; word-wrap:break-word; overflow-wrap:anywhere; }

    .wrap-2lines{ display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .chip{ display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; border:1px solid #dbeafe; background:#eef5ff; color:#0b3ea8; }
    .chip.gray{ background:#f1f5f9; border-color:#e2e8f0; color:#334155; }
    .chip.ok{ background:#ecfdf5; border-color:#bbf7d0; color:#065f46; }
    .chip.bad{ background:#fff7ed; border-color:#fed7aa; color:#7c2d12; }
    .chip.role{ background:#eef2ff; border-color:#c7d2fe; color:#3730a3; }
    .chip.plan{ background:#f0f9ff; border-color:#bae6fd; color:#075985; }

    .row-actions{ display:flex; gap:6px; flex-wrap:wrap; }
    .actions-col{ width: 100%; }

    @media (max-width: 980px){
      .col-optional{ display:none; }
    }

    .toast{ position: fixed; right: 16px; bottom: 16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#065f46;
      padding:10px 12px; border-radius:10px; box-shadow:var(--shadow); font-weight:800; display:none; z-index:2000; }
    .toast.error{ background:#fee2e2; border-color:#fecaca; color:#7f1d1d; }

    /* Modal */
    .modal{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:3000; }
    .modal .modal-content{ width: 560px; max-width: calc(100% - 24px); margin: 6vh auto; background:#fff; border-radius:14px; padding:16px; box-shadow:var(--shadow); border:1px solid var(--border); }
    .modal .modal-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
    .modal .close{ border:none; background:#f1f5f9; border-radius:10px; padding:6px 10px; cursor:pointer; }
    .modal .field{ margin-bottom:10px; }
    .modal input, .modal select, .modal textarea{ width:100%; padding:9px 10px; border:1px solid var(--border); border-radius:10px; font-size:14px; }

    .small-muted{ font-size:12px; color:var(--muted); }
    .tooltip{ position:relative; }
    .tooltip[title]:hover::after{
      content: attr(title);
      position:absolute; top:-34px; left:50%; transform:translateX(-50%);
      background:#111; color:#fff; padding:6px 8px; border-radius:6px; font-size:12px; white-space:nowrap;
      box-shadow:0 6px 16px rgba(2,6,23,.3);
    }

  </style>
</head>
<body>
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="page">
    <div class="container">

      <!-- Encabezado -->
      <div class="header">
        <div class="title">
          <div class="icon" style="width:48px;height:48px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(135deg,#e0edff,#f1f7ff);border:1px solid #dbeafe;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 12c2.21 0 4-1.79 4-4S14.21 4 12 4 8 5.79 8 8s1.79 4 4 4zM6 20v-1a4 4 0 014-4h4a4 4 0 014 4v1" stroke="#2563eb" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
          <div>
            <h1>Administración de usuarios</h1>
            <div class="hint small-muted">Gestiona cuentas, roles, estado, trial y planes.</div>
          </div>
        </div>
        <div class="actions-top">
          <button class="btn primary" onclick="openAddModal()" title="Crear un nuevo usuario">Agregar usuario</button>
        </div>
      </div>

      <!-- Barra de filtros arriba -->
      <div class="filters-bar" role="region" aria-label="Filtros de usuarios">
        <div class="field">
          <label for="f_search">Buscar</label>
          <input type="text" id="f_search" placeholder="Nombre o usuario (búsqueda en tiempo real)" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="field">
          <label for="f_role">Rol</label>
          <select id="f_role">
            <option value="">Todos</option>
            <option value="admin" <?= $role==='admin'?'selected':''; ?>>Administrador</option>
            <option value="user"  <?= $role==='user'?'selected':''; ?>>Usuario</option>
          </select>
        </div>
        <div class="field">
          <label for="f_status">Estado</label>
          <select id="f_status">
            <option value="">Todos</option>
            <option value="1" <?= $status==='1'?'selected':''; ?>>Activo</option>
            <option value="0" <?= $status==='0'?'selected':''; ?>>Desactivado</option>
          </select>
        </div>
        <div class="field">
          <label for="f_plan">Plan</label>
          <select id="f_plan">
            <option value="">Todos</option>
            <option value="free"    <?= $plan==='free'?'selected':''; ?>>Free</option>
            <option value="starter" <?= $plan==='starter'?'selected':''; ?>>Starter</option>
          </select>
        </div>
        <div class="field">
          <label for="f_trial">Trial</label>
          <select id="f_trial">
            <option value="">—</option>
            <option value="1" <?= $trial==='1'?'selected':''; ?>>Activo</option>
            <option value="0" <?= $trial==='0'?'selected':''; ?>>Expirado/No</option>
          </select>
        </div>
        <div class="field">
          <label for="f_verif">Verificado</label>
          <select id="f_verif">
            <option value="">—</option>
            <option value="1" <?= $verif==='1'?'selected':''; ?>>Sí</option>
            <option value="0" <?= $verif==='0'?'selected':''; ?>>No</option>
          </select>
        </div>
        <div class="actions" style="display:flex;align-items:flex-end;">
          <button class="btn" onclick="applyFilters()">Aplicar</button>
          <button class="btn" onclick="resetFilters()" title="Limpiar filtros">Limpiar</button>
        </div>
      </div>

      <!-- Tabla -->
      <div class="table-wrap" aria-live="polite">
        <table id="usersTable" role="table" aria-label="Lista de usuarios">
          <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Nombre / Usuario</th>
            <th style="width:110px">Rol</th>
            <th style="width:120px">Estado</th>
            <th style="width:120px">Plan</th>
            <th style="width:180px">Trial</th>
            <th class="col-optional" style="width:110px">Verificado</th>
            <th style="width:320px">Acciones</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <?php [$trialText, $trialKind] = trial_meta($u); ?>
            <?php
              $nowUTC = new DateTime('now', new DateTimeZone('UTC'));
              $trialActive = !empty($u['trial_ends_at']) && $nowUTC <= new DateTime($u['trial_ends_at'], new DateTimeZone('UTC'));
              $data_attrs = sprintf(
                'data-full_name="%s" data-username="%s" data-role="%s" data-plan="%s" data-status="%d" data-trial_ends_at="%s" data-email_verified_at="%s"',
                htmlspecialchars($u['full_name'], ENT_QUOTES),
                htmlspecialchars($u['username'], ENT_QUOTES),
                htmlspecialchars($u['role'], ENT_QUOTES),
                htmlspecialchars($u['plan'] ?: 'free', ENT_QUOTES),
                (int)$u['status'],
                htmlspecialchars($u['trial_ends_at'] ?? '', ENT_QUOTES),
                htmlspecialchars($u['email_verified_at'] ?? '', ENT_QUOTES)
              );
            ?>
            <tr data-id="<?= (int)$u['id'] ?>" <?= $data_attrs ?>>
              <td><?= (int)$u['id'] ?></td>
              <td>
                <div class="wrap-2lines"><strong><?= htmlspecialchars($u['full_name'] ?: '—') ?></strong></div>
                <div><span class="chip gray" title="<?= htmlspecialchars($u['username']) ?>"><?= htmlspecialchars($u['username']) ?></span></div>
              </td>
              <td><span class="chip role role-chip"><?= htmlspecialchars($u['role']) ?></span></td>
              <td class="status-col"><?= $u['status'] ? '<span class="chip ok">Activo</span>' : '<span class="chip bad">Desactivado</span>' ?></td>
              <td class="plan-col"><span class="chip plan plan-chip"><?= htmlspecialchars($u['plan'] ?: 'free') ?></span></td>
              <td class="trial-col"><span class="chip <?= $trialKind==='ok' ? 'ok' : ($trialKind==='bad' ? 'bad' : 'gray') ?> trial-chip"><?= htmlspecialchars($trialText) ?></span></td>
              <td class="col-optional"><?= !empty($u['email_verified_at']) ? '<span class="chip ok">Sí</span>' : '<span class="chip gray">No</span>' ?></td>
              <td class="actions-col">
                <div class="row-actions">
                  <button class="btn soft tooltip" title="Editar usuario" onclick="openEditModal(<?= (int)$u['id'] ?>)">✏️ Editar</button>

                  <button class="btn soft tooltip" title="Ver detalles del usuario" onclick="openDetailsModal(<?= (int)$u['id'] ?>, this)">ℹ️ Detalles</button>

                  <?php if ($trialActive): ?>
                    <button class="btn warn tooltip" title="Finalizar trial ahora" onclick="endTrial(<?= (int)$u['id'] ?>, this)">⏳ Finalizar trial</button>
                  <?php else: ?>
                    <button class="btn warn tooltip" title="Activar trial (<?= 7 ?> días)" onclick="activateTrial(<?= (int)$u['id'] ?>, this)">✨ Activar trial</button>
                  <?php endif; ?>
                  <button class="btn warn tooltip" title="Ajustar días de prueba" onclick="promptTrialDays(<?= (int)$u['id'] ?>, this)">🗓️ Ajustar días</button>

                  <?php
                    $isStarter = ($u['plan'] ?? '') === 'starter';
                    $planBtnLabel = $isStarter ? 'Volver a Free' : 'Activar Starter';
                    $planBtnAction = $isStarter ? 'free' : 'starter';
                  ?>
                  <button class="btn primary tooltip" title="Alternar plan Free / Starter" onclick="togglePlan(<?= (int)$u['id'] ?>, this)"><?= htmlspecialchars($planBtnLabel) ?></button>

                  <?php if ((int)$u['id'] !== (int)$current_user_id): ?>
                    <button class="btn soft tooltip" title="<?= $u['status'] ? 'Desactivar usuario' : 'Activar usuario' ?>" onclick="toggleUser(<?= (int)$u['id'] ?>, this)"><?= $u['status'] ? 'Desactivar' : 'Activar' ?></button>
                    <button class="btn danger tooltip" title="Eliminar usuario permanentemente" onclick="deleteUser(<?= (int)$u['id'] ?>, this)">🗑️ Eliminar</button>
                  <?php else: ?>
                    <button class="btn soft" disabled title="No puedes cambiar tu propio estado">—</button>
                    <button class="btn danger" disabled title="No puedes eliminarte">—</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Logs -->
      <div class="table-wrap" style="margin-top:14px;">
        <table style="table-layout:fixed; width:100%;">
          <thead><tr><th style="width:60px">ID</th><th>Usuario</th><th>Acción</th><th style="width:180px">Fecha</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td><?= (int)$l['id'] ?></td>
              <td class="wrap-2lines"><?= htmlspecialchars($l['username']) ?></td>
              <td class="wrap-2lines"><?= htmlspecialchars($l['action']) ?></td>
              <td><?= htmlspecialchars($l['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="toast" role="status" aria-live="polite"></div>

  <!-- Modal Add/Edit -->
  <div id="userModal" class="modal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-header">
        <div style="font-weight:800" id="modalTitle">Agregar usuario</div>
        <button class="close" onclick="closeModal()">✕</button>
      </div>
      <form id="userForm">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="field"><label>Nombre completo</label><input type="text" name="full_name" id="full_name" required></div>
        <div class="field"><label>Usuario (email)</label><input type="email" name="username" id="username" required></div>
        <div class="field"><label>Contraseña</label><input type="password" name="password" id="password" placeholder="(mín. 6)"></div>
        <div class="field"><label>Rol</label>
          <select name="role" id="role"><option value="user">Usuario</option><option value="admin">Administrador</option></select>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
          <button type="button" class="btn" onclick="closeModal()">Cancelar</button>
          <button type="submit" id="modalSubmit" class="btn primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Detalles -->
  <div id="detailsModal" class="modal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <div style="font-weight:800" id="detailsTitle">Detalles</div>
        <button class="close" onclick="closeDetailsModal()">✕</button>
      </div>
      <div id="detailsBody">
        <div class="field"><strong>Nombre:</strong> <span id="d_full_name"></span></div>
        <div class="field"><strong>Usuario:</strong> <span id="d_username"></span></div>
        <div class="field"><strong>Rol:</strong> <span id="d_role"></span></div>
        <div class="field"><strong>Plan:</strong> <span id="d_plan"></span></div>
        <div class="field"><strong>Estado:</strong> <span id="d_status"></span></div>
        <div class="field"><strong>Trial:</strong> <span id="d_trial"></span></div>
        <div class="field"><strong>Verificado:</strong> <span id="d_verif"></span></div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px;">
        <button class="btn" onclick="closeDetailsModal()">Cerrar</button>
      </div>
    </div>
  </div>

  <script>
    const ENDPOINT = '<?php echo basename(__FILE__); ?>';
    const DEFAULT_TRIAL_DAYS = 7; // duración por defecto del trial

    // Toast
    function showToast(msg, type='ok'){
      const t = document.getElementById('toast');
      t.className = 'toast';
      if (type!=='ok') t.classList.add('error');
      t.textContent = msg;
      t.style.display = 'block';
      setTimeout(()=> t.style.display = 'none', 3200);
    }

    // Filtros: aplicar y reset
    function applyFilters(){
      const qs = new URLSearchParams({
        search: document.getElementById('f_search').value.trim(),
        role:   document.getElementById('f_role').value,
        status: document.getElementById('f_status').value,
        plan:   document.getElementById('f_plan').value,
        trial:  document.getElementById('f_trial').value,
        verif:  document.getElementById('f_verif').value
      });
      location.search = qs.toString();
    }
    function resetFilters(){
      document.getElementById('f_search').value='';
      document.getElementById('f_role').value='';
      document.getElementById('f_status').value='';
      document.getElementById('f_plan').value='';
      document.getElementById('f_trial').value='';
      document.getElementById('f_verif').value='';
      applyFilters();
    }

    // Modal Add/Edit
    const modal = document.getElementById('userModal');
    function openAddModal(){
      modal.style.display='block';
      document.getElementById('modalTitle').textContent='Agregar usuario';
      document.getElementById('modalSubmit').textContent='Agregar';
      document.getElementById('userForm').reset();
      document.getElementById('edit_id').value='';
    }
    function openEditModal(id){
      const tr = document.querySelector(`tr[data-id="${id}"]`);
      if(!tr) return;
      modal.style.display='block';
      document.getElementById('modalTitle').textContent='Editar usuario';
      document.getElementById('modalSubmit').textContent='Guardar';
      document.getElementById('edit_id').value=id;
      document.getElementById('full_name').value = tr.dataset.full_name || '';
      document.getElementById('username').value  = tr.dataset.username || '';
      document.getElementById('role').value      = tr.dataset.role || 'user';
      document.getElementById('password').value  = '';
    }
    function closeModal(){ modal.style.display='none'; }
    window.addEventListener('click', e=>{ if(e.target===modal) closeModal(); });

    // Modal Detalles
    const detailsModal = document.getElementById('detailsModal');
    function openDetailsModal(id, btn){
      const tr = document.querySelector(`tr[data-id="${id}"]`);
      if(!tr) return;
      detailsModal.style.display='block';
      document.getElementById('detailsTitle').textContent = `Usuario #${id}`;
      document.getElementById('d_full_name').textContent = tr.dataset.full_name || '—';
      document.getElementById('d_username').textContent = tr.dataset.username || '—';
      document.getElementById('d_role').textContent = tr.dataset.role || 'user';
      document.getElementById('d_plan').textContent = tr.dataset.plan || 'free';
      document.getElementById('d_status').textContent = (tr.dataset.status === '1') ? 'Activo' : 'Desactivado';
      document.getElementById('d_trial').textContent = tr.dataset.trial_ends_at ? tr.dataset.trial_ends_at : 'No tiene trial';
      document.getElementById('d_verif').textContent = tr.dataset.email_verified_at ? tr.dataset.email_verified_at : 'No';
    }
    function closeDetailsModal(){ detailsModal.style.display='none'; }
    window.addEventListener('click', e=>{ if(e.target===detailsModal) closeDetailsModal(); });

    // AJAX helper
    function postAction(payload){
      const fd = new FormData();
      for (const k in payload) fd.append(k, payload[k]);
      return fetch(ENDPOINT, { method:'POST', body: fd }).then(r=>r.json());
    }

    // Submit (add/edit) con actualización sin recargar
    document.getElementById('userForm').addEventListener('submit', function(e){
      e.preventDefault();
      const fd = new FormData(this);
      const editId = document.getElementById('edit_id').value;
      if (editId) fd.append('ajax_edit_user',1);
      else fd.append('ajax_add_user',1);

      fetch(ENDPOINT, { method:'POST', body:fd })
        .then(r=>r.json()).then(d=>{
          showToast(d.msg, d.status==='success'?'ok':'err');
          if (d.status==='success') {
            closeModal();
            if (editId) {
              const tr = document.querySelector(`tr[data-id="${editId}"]`);
              if (tr) {
                tr.dataset.full_name = fd.get('full_name');
                tr.dataset.username = fd.get('username');
                tr.dataset.role = fd.get('role');
                tr.querySelector('td:nth-child(2) strong').textContent = fd.get('full_name');
                tr.querySelector('td:nth-child(2) .chip').textContent = fd.get('username');
                tr.querySelector('.role-chip').textContent = fd.get('role');
              }
            } else {
              location.reload(); // simplificamos la inserción fresca
            }
          }
        }).catch(()=> showToast('Error de red','err'));
    });

    // Utilidades DOM
    function getRow(id){ return document.querySelector(`tr[data-id="${id}"]`); }
    function setStatusChip(tr, active){
      tr.querySelector('.status-col').innerHTML = active
        ? '<span class="chip ok">Activo</span>'
        : '<span class="chip bad">Desactivado</span>';
      tr.dataset.status = active ? '1' : '0';
    }
    function setPlanChip(tr, plan){
      const chip = tr.querySelector('.plan-col .plan-chip');
      if (chip) chip.textContent = plan || 'free';
      tr.dataset.plan = plan || 'free';
      // actualizar texto del botón de plan si existe
      const btn = tr.querySelector('.row-actions .btn.primary');
      if (btn) btn.textContent = (plan === 'starter') ? 'Volver a Free' : 'Activar Starter';
    }
    function setTrialChip(tr, active, endsAt){
      const chip = tr.querySelector('.trial-col .trial-chip');
      if(!chip) return;
      chip.classList.remove('ok','bad','gray');
      if (active) {
        chip.classList.add('ok');
        chip.textContent = `Activo · fin: ${formatUTC(endsAt)} UTC`;
      } else {
        chip.classList.add('bad');
        chip.textContent = `Expirado · fin: ${formatUTC(endsAt)} UTC`;
      }
      tr.dataset.trial_ends_at = endsAt || '';
    }
    function formatUTC(ts){
      return ts ? ts.slice(0,16) : '';
    }

    // Acciones por fila (sin recargar)
    function deleteUser(id, btn){
      if(!confirm('¿Seguro que quieres eliminar este usuario?')) return;
      postAction({ajax_delete_user:1, delete_id:id}).then(d=>{
        showToast(d.msg, d.status==='success'?'ok':'err');
        if (d.status==='success') {
          const tr = getRow(id);
          if (tr) tr.remove();
        }
      }).catch(()=> showToast('Error de red','err'));
    }

    function toggleUser(id, btn){
      postAction({ajax_toggle_user:1, toggle_id:id}).then(d=>{
        showToast(d.msg, d.status==='success'?'ok':'err');
        if (d.status==='success') {
          const tr = getRow(id);
          if (tr) {
            setStatusChip(tr, d.status_value == 1);
            btn.textContent = (d.status_value == 1) ? 'Desactivar' : 'Activar';
            btn.title = (d.status_value == 1) ? 'Desactivar usuario' : 'Activar usuario';
          }
        }
      }).catch(()=> showToast('Error de red','err'));
    }

    function togglePlan(id, btn){
      // lee plan actual desde tr.dataset
      const tr = getRow(id);
      if(!tr) return;
      const current = tr.dataset.plan || 'free';
      const target = (current === 'starter') ? 'free' : 'starter';
      postAction({ajax_set_plan:1, user_id:id, plan:target}).then(d=>{
        showToast(d.msg, d.status==='success'?'ok':'err');
        if (d.status==='success') {
          setPlanChip(tr, d.plan);
        }
      }).catch(()=> showToast('Error de red','err'));
    }

    function endTrial(id, btn){
      if(!confirm('Esto finalizará el periodo de prueba de inmediato. ¿Continuar?')) return;
      postAction({ajax_end_trial:1, user_id:id}).then(d=>{
        showToast(d.msg, d.status==='success'?'ok':'err');
        if (d.status==='success') {
          const tr = getRow(id);
          if (tr) {
            // UI: actualizar trial y plan a free
            setTrialChip(tr, false, d.trial_ends_at);
            setPlanChip(tr, d.plan || 'free');
            // Cambiar botón a "Activar trial"
            const trialBtn = tr.querySelector('.row-actions .btn.warn');
            if (trialBtn) {
              trialBtn.textContent = 'Activar trial';
              trialBtn.onclick = function(){ activateTrial(id, trialBtn); };
              trialBtn.title = `Activar trial (${DEFAULT_TRIAL_DAYS} días)`;
            }
          }
        }
      }).catch(()=> showToast('Error de red','err'));
    }

    function activateTrial(id, btn){
      const days = DEFAULT_TRIAL_DAYS;
      postAction({ajax_set_trial_days:1, user_id:id, days}).then(d=>{
        showToast(d.msg, d.status==='success'?'ok':'err');
        if (d.status==='success') {
          const tr = getRow(id);
          if (tr) {
            setTrialChip(tr, true, d.trial_ends_at);
            // cambiar texto del botón
            const trialBtn = tr.querySelector('.row-actions .btn.warn');
            if (trialBtn) {
              trialBtn.textContent = 'Finalizar trial';
              trialBtn.onclick = function(){ endTrial(id, trialBtn); };
              trialBtn.title = 'Finalizar trial ahora';
            }
          }
        }
      }).catch(()=> showToast('Error de red','err'));
    }

    // Búsqueda en tiempo real (sin recargar)
    const searchInput = document.getElementById('f_search');
    if (searchInput) {
      searchInput.addEventListener('input', function(){
        const term = (this.value || '').trim().toLowerCase();
        document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
          const name = (tr.dataset.full_name || '').toLowerCase();
          const username = (tr.dataset.username || '').toLowerCase();
          const matches = (term === '') || name.includes(term) || username.includes(term) || (tr.querySelector('.chip.plan') && tr.querySelector('.chip.plan').textContent.toLowerCase().includes(term));
          tr.style.display = matches ? '' : 'none';
        });
      });
    }

    // Inicial: actualizar botones plan según dataset (por si hay inconsistencias)
    function normalizeRowButtons(){
      document.querySelectorAll('#usersTable tbody tr').forEach(tr=>{
        const plan = tr.dataset.plan || 'free';
        const planBtn = tr.querySelector('.row-actions .btn.primary');
        if (planBtn) planBtn.textContent = (plan === 'starter') ? 'Volver a Free' : 'Activar Starter';
        const trialBtn = tr.querySelector('.row-actions .btn.warn');
        if (trialBtn) {
          if (tr.dataset.trial_ends_at) {
            const now = new Date();
            const ends = new Date(tr.dataset.trial_ends_at + 'Z'); // interpret UTC
            if (ends > now) {
              trialBtn.textContent = 'Finalizar trial';
              trialBtn.onclick = function(){ endTrial(tr.dataset.id, trialBtn); };
              trialBtn.title = 'Finalizar trial ahora';
            } else {
              trialBtn.textContent = 'Activar trial';
              trialBtn.onclick = function(){ activateTrial(tr.dataset.id, trialBtn); };
              trialBtn.title = `Activar trial (${DEFAULT_TRIAL_DAYS} días)`;
            }
          } else {
            trialBtn.textContent = 'Activar trial';
            trialBtn.onclick = function(){ activateTrial(tr.dataset.id, trialBtn); };
            trialBtn.title = `Activar trial (${DEFAULT_TRIAL_DAYS} días)`;
          }
        }
      });
    }

    // Run normalize on load
    window.addEventListener('load', normalizeRowButtons);

// Función que pide el número de días y llama a la lógica de actualización.
function promptTrialDays(userId, btnElement) {
    const days = prompt("Ingresa la cantidad de días de prueba a AGREGAR o RENOVAR para el usuario #" + userId + " (ej: 30):");

    // 1. Cancelado o vacío
    if (days === null || days.trim() === '') {
        return;
    }

    const numDays = parseInt(days, 10);

    // 2. No es un número válido o es cero/negativo
    if (isNaN(numDays) || numDays <= 0) {
        showToast("Cantidad de días inválida. Debe ser un número positivo.", 'error');
        return;
    }

    // 3. Deshabilitar botón para UX
    btnElement.disabled = true;
    const originalText = btnElement.innerHTML;
    btnElement.innerHTML = 'Cargando...';

    // 4. Enviar solicitud AJAX
    postAction({ 
        ajax_set_trial_days: 1, 
        user_id: userId, 
        days: numDays 
    }).then(res => {
        if (res.status === 'success') {
            showToast(res.msg, 'ok');
            // Actualizar la fila en el HTML sin recargar
            updateTrialRow(userId, res.trial_ends_at, res.trial_active, res.plan);
        } else {
            showToast(res.msg || 'Error al ajustar el trial.', 'error');
        }
    }).catch(() => {
        showToast('Error de conexión con el servidor.', 'error');
    }).finally(() => {
        btnElement.disabled = false;
        btnElement.innerHTML = originalText;
    });
}

// Esta función actualiza el HTML de la fila de la tabla
function updateTrialRow(userId, trialEndsAt, isActive, plan) {
    const tr = document.querySelector(`tr[data-id="${userId}"]`);
    if (!tr) return;

    // A) Actualizar el atributo data (usado para modals/lógica)
    tr.dataset.trial_ends_at = trialEndsAt;
    tr.dataset.plan = plan; // si finalizas, fuerza plan a 'free'

    // B) Actualizar la columna Plan
    const planChip = tr.querySelector('.plan-chip');
    if (planChip) planChip.textContent = plan;
    
    // C) Actualizar la columna Trial
    const trialCol = tr.querySelector('.trial-col');
    if (trialCol) {
        let chipClass, trialText;
        if (!trialEndsAt) {
            chipClass = 'gray';
            trialText = '—';
        } else {
            const end = new Date(trialEndsAt + 'Z'); // Asume UTC de la BD
            const now = new Date();
            const active = now <= end;
            
            trialText = active 
                ? "Activo · fin: " + end.toISOString().slice(0, 16).replace('T', ' ') + ' UTC'
                : "Expirado · fin: " + end.toISOString().slice(0, 16).replace('T', ' ') + ' UTC';

            chipClass = active ? 'ok' : 'bad';
        }
        
        trialCol.innerHTML = `<span class="chip ${chipClass} trial-chip">${trialText}</span>`;
    }
    
    // D) Reemplazar el botón de acción "Activar/Finalizar trial"
    const trialBtnContainer = tr.querySelector('.row-actions');
    if (trialBtnContainer) {
        // En un entorno real, es mejor recargar la fila completa o actualizar
        // el botón de Activar/Finalizar Trial (ya que su lógica depende de si el trial está activo)
        // Por simplicidad, aquí solo actualizamos el chip.
    }
}
  </script>
</body>
</html>

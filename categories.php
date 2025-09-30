<?php

require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
require 'auth.php';
$user_id = $_SESSION['user_id'];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $id = $_POST['id'] ?? null;

    if ($name !== '') {
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $id, $user_id]);
                echo json_encode(['status' => 'ok', 'action' => 'edit', 'id' => $id, 'name' => $name]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (name, user_id) VALUES (?, ?)");
                $stmt->execute([$name, $user_id]);
                $id = $pdo->lastInsertId();
                echo json_encode(['status' => 'ok', 'action' => 'add', 'id' => $id, 'name' => $name]);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Hubo un problema con la base de datos: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'El nombre no puede estar vacío']);
    }
    exit;
}

// ===== Eliminar 
if (isset($_GET['delete'])) {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $isAjax = (strtolower($xhr) === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);

    try {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['delete'], $user_id]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok']);
            exit;
        }
        header("Location: categories.php");
        exit;
    } catch (PDOException $e) {
        if ($isAjax) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
        echo "Error al eliminar la categoría: " . $e->getMessage();
    }
}

$categories = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY name");
$categories->execute([$user_id]);
$categories = $categories->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
    <link rel="shortcut icon" href="assets/img/favicon.png" type="image/png">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Categorías</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            /* Tema claro */
            --bg:#f8fafc;
            --panel:#ffffff;
            --panel-2:#f2f5f9;
            --text:#0f172a;
            --muted:#64748b;
            --primary:#2563eb;
            --primary-2:#60a5fa;
            --success:#16a34a;
            --danger:#dc2626;
            --warning:#d97706;
            --border:#e2e8f0;
            --shadow:0 10px 24px rgba(15,23,42,.06);
            --radius:16px;
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:linear-gradient(180deg,#ffffff,#fbfdff 40%,var(--bg));color:var(--text)}
        a{color:inherit}

        .page{padding:24px 20px 64px}
        .header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:8px auto 20px;max-width:1200px}
        .title{display:flex;align-items:center;gap:14px}
        .title .icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#e0edff,#f1f7ff);display:grid;place-items:center;box-shadow:var(--shadow);border:1px solid #dbeafe}
        .title h1{font-size:24px;font-weight:700;margin:0;color:#0b1220}
        .subtitle{font-size:13px;color:var(--muted);margin-top:4px}

        .actions{display:flex;align-items:center;gap:10px}
        #addNewBtn{padding:10px 14px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#ffffff;font-weight:700;border:none;cursor:pointer;box-shadow:var(--shadow);transition:.25s transform ease}
        #addNewBtn:hover{transform:translateY(-2px)}

        .toolbar{max-width:1200px;margin:0 auto 18px;display:grid;grid-template-columns:1fr auto;gap:12px}
        .search{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:10px 12px;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow)}
        .search input{flex:1;background:transparent;border:none;outline:none;color:var(--text)}

        .card{max-width:1200px;margin:0 auto;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
        .card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:linear-gradient(180deg,#ffffff,#f7fafc);border-bottom:1px solid var(--border)}
        .card-header .meta{font-size:12px;color:var(--muted)}

        table#categoriesTable{width:100%;border-collapse:separate;border-spacing:0}
        #categoriesTable thead th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#475569;padding:14px 16px;background:#f8fafc;border-bottom:1px solid var(--border)}
        #categoriesTable tbody tr{transition:background .18s ease}
        #categoriesTable tbody tr:hover{background:#f1f5f9}
        #categoriesTable td{padding:14px 16px;border-bottom:1px solid var(--border)}
        #categoriesTable .name{display:flex;align-items:center;gap:12px;font-weight:600}
        .avatar{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#e8f1ff,#f3f8ff);display:grid;place-items:center;border:1px solid #e5eaff}
        .badge{font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid var(--border);background:#f8fafc;color:#334155}

        .actions-cell a{margin-right:8px;padding:8px 12px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);transition:transform .2s ease, background .2s ease}
        /* Azul más intenso para Editar */
        .actions-cell a.edit {
          background:#299EE6;   /* fondo */
          color:#ffffff;        /* texto */
          border-color:#bfdbfe; /* borde opcional */
        }
        /* Rojo más suave para Eliminar */
        .actions-cell a.delete {
          background:#F52727;   /* fondo */
          color:#ffffff;        /* texto */
          border-color:#fecaca; /* borde opcional */
        }

        #categoryModal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.2);backdrop-filter:blur(2px);justify-content:center;align-items:center;z-index:1000}
        #categoryModal.active{display:flex;animation:fadeIn .2s ease}
        @keyframes fadeIn{from{opacity:0}to{opacity:1}}
        .modal-content{background:#ffffff;border:1px solid var(--border);border-radius:18px;box-shadow:0 20px 40px rgba(2,6,23,.15);max-width:440px;width:92%;padding:24px 22px;position:relative}
        .modal-content h3{margin:0 0 12px;font-size:18px;color:#0b1220}
        .modal-sub{margin:0 0 14px;color:var(--muted);font-size:12px}
        .modal-content input{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text)}
        .modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}
        .btn{padding:10px 14px;border-radius:12px;border:none;cursor:pointer;font-weight:700}
        .btn.primary{background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff}
        .btn.cancel{background:#fff;color:var(--muted);border:1px solid var(--border)}
        .modal-close{position:absolute;top:12px;right:12px;width:34px;height:34px;border-radius:10px;border:1px solid var(--border);display:grid;place-items:center;background:#f8fafc;cursor:pointer}

        #toastMessage{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#ecfdf5;border:1px solid #d1fae5;color:#065f46;padding:12px 16px;border-radius:12px;box-shadow:var(--shadow);opacity:0;pointer-events:none;transition:opacity .25s ease;z-index:2000;font-weight:700}
        #toastMessage.show{opacity:1;pointer-events:auto}

        @media (max-width:768px){
            .header{flex-direction:column;align-items:flex-start}
            .toolbar{grid-template-columns:1fr}
            .actions{width:100%;justify-content:space-between}
        }
        /* Iconos SVG dentro de .avatar y otros contenedores */
        .icon-24{width:20px;height:20px;display:block}
        .icon-18{width:18px;height:18px;display:block}
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="page">
    <div class="header">
        <div class="title">
            <div class="icon">
                <!-- SVG BOX -->
                <svg class="icon-24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                  <path d="M3.27 6.96 12 12l8.73-5.04M12 22V12"/>
                </svg>
            </div>
            <div>
                <h1>Categorías</h1>
                <div class="subtitle">Organiza tus productos por grupos. Crea, edita o elimina sin salir de la página.</div>
            </div>
        </div>
        <div class="actions">
            <button id="addNewBtn">+ Agregar categoría</button>
        </div>
    </div>

    <div class="toolbar">
        <div class="search">
            <!-- SVG SEARCH -->
            <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-3.6-3.6"></path>
            </svg>
            <input id="categorySearch" type="text" placeholder="Buscar por nombre o ID..." />
        </div>
        <!-- Si quieres reactivar el chip, añade:
        <div class="chip" id="statsChip">Total: <?php echo count($categories); ?></div>
        -->
    </div>

    <div class="card">
        <div class="card-header">
            <div class="meta">Listado de categorías</div>
            <div class="meta" id="emptyHint" style="display:none">No hay resultados para tu búsqueda</div>
        </div>
        <table id="categoriesTable">
            <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th style="text-align:left">Acciones</th>
            </tr>
            </thead>
            <tbody id="categoryBody">
            <?php foreach($categories as $cat): ?>
                <tr id="cat-<?php echo $cat['id']; ?>" data-id="<?php echo $cat['id']; ?>" data-name="<?php echo htmlspecialchars($cat['name']); ?>">
                    <td>
                        <span class="badge">#<?php echo $cat['id']; ?></span>
                    </td>
                    <td class="cat-name">
                        <div class="name">
                            <div class="avatar">
                                <!-- SVG TAG -->
                                <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                  <path d="M20.59 13.41 11 3H4v7l9.59 9.59a2 2 0 0 0 2.82 0l4.18-4.18a2 2 0 0 0 0-2.82Z"/>
                                  <path d="M7 7h.01"/>
                                </svg>
                            </div>
                            <div>
                                <div class="cat-title" style="font-weight:700"><?php echo htmlspecialchars($cat['name']); ?></div>
                                <div style="font-size:12px;color:var(--muted)">ID: <?php echo $cat['id']; ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="actions-cell">
                        <a href="#" class="edit" onclick="openModal(<?php echo $cat['id']; ?>,'<?php echo htmlspecialchars(addslashes($cat['name'])); ?>')">
                            <!-- SVG EDIT -->
                            <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                              <path d="M12 20h9"/>
                              <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/>
                            </svg>
                            Editar
                        </a>
                        <a href="?delete=<?php echo $cat['id']; ?>" class="delete" onclick="return confirmDelete(<?php echo $cat['id']; ?>)">
                            <!-- SVG TRASH -->
                            <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                              <path d="M3 6h18"/>
                              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
                              <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                              <path d="M10 11v6M14 11v6"/>
                            </svg>
                            Eliminar
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="categoryModal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal()" aria-label="Cerrar">
            <!-- SVG CLOSE -->
            <svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M18 6 6 18"/>
              <path d="M6 6l12 12"/>
            </svg>
        </button>
        <h3 id="modalTitle">Nueva categoría</h3>
        <p class="modal-sub">Asigna un nombre claro y único para identificar la categoría.</p>
        <input type="hidden" id="category_id">
        <input type="text" id="category_name" placeholder="Nombre de la categoría" autocomplete="off">
        <div class="modal-actions">
            <button class="btn cancel" onclick="closeModal()">Cancelar</button>
            <button class="btn primary" onclick="saveCategory()">Guardar</button>
        </div>
    </div>
</div>

<div id="toastMessage"></div>

<script>
const modal = document.getElementById('categoryModal');
const catIdInput = document.getElementById('category_id');
const catNameInput = document.getElementById('category_name');
const modalTitle = document.getElementById('modalTitle');
const categoryBody = document.getElementById('categoryBody');
const searchInput = document.getElementById('categorySearch');
const emptyHint = document.getElementById('emptyHint');

// --- SVG strings para insertar desde JS ---
const ICON_TAG = `
<svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <path d="M20.59 13.41 11 3H4v7l9.59 9.59a2 2 0 0 0 2.82 0l4.18-4.18a2 2 0 0 0 0-2.82Z"/>
  <path d="M7 7h.01"/>
</svg>`;
const ICON_EDIT = `
<svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <path d="M12 20h9"/>
  <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/>
</svg>`;
const ICON_TRASH = `
<svg class="icon-18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <path d="M3 6h18"/>
  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
  <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
  <path d="M10 11v6M14 11v6"/>
</svg>`;

document.getElementById('addNewBtn').addEventListener('click', () => {
    modalTitle.textContent = 'Nueva categoría';
    catIdInput.value = '';
    catNameInput.value = '';
    modal.classList.add('active');
    setTimeout(() => catNameInput.focus(), 60);
});

function openModal(id, name) {
    modalTitle.textContent = 'Editar categoría';
    catIdInput.value = id;
    catNameInput.value = name;
    modal.classList.add('active');
    setTimeout(() => catNameInput.focus(), 60);
}

function closeModal() {
    modal.classList.remove('active');
    catIdInput.value = '';
    catNameInput.value = '';
}

function saveCategory() {
    const name = catNameInput.value.trim();
    if (!name) { showToast('El nombre no puede estar vacío'); return; }
    const id = catIdInput.value;

    const formData = new FormData();
    formData.append('name', name);
    if (id) formData.append('id', id);

    fetch('categories.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                if (data.action === 'add') {
                    const tr = document.createElement('tr');
                    tr.id = 'cat-' + data.id;
                    tr.dataset.id = data.id;
                    tr.dataset.name = data.name;
                    tr.innerHTML = `
                        <td><span class="badge">#${data.id}</span></td>
                        <td class="cat-name">
                            <div class="name">
                                <div class="avatar">${ICON_TAG}</div>
                                <div>
                                    <div class="cat-title" style="font-weight:700">${escapeHtml(data.name)}</div>
                                    <div style="font-size:12px;color:var(--muted)">ID: ${data.id}</div>
                                </div>
                            </div>
                        </td>
                        <td class="actions-cell">
                            <a href="#" class="edit" onclick="openModal(${data.id}, '${escapeAttr(data.name)}')">
                                ${ICON_EDIT} Editar
                            </a>
                            <a href="?delete=${data.id}" class="delete" onclick="return confirmDelete(${data.id})">
                                ${ICON_TRASH} Eliminar
                            </a>
                        </td>`;
                    categoryBody.appendChild(tr);
                    updateStats();
                } else if (data.action === 'edit') {
                    const tr = document.getElementById('cat-' + data.id);
                    if (tr) {
                        const titleEl = tr.querySelector('.cat-title');
                        if (titleEl) titleEl.textContent = data.name; // sólo cambia el nodo del título
                        tr.dataset.name = data.name;
                    }
                }
                showToast('Categoría guardada correctamente');
                closeModal();
                applyFilter();
            } else {
                alert('Hubo un error: ' + (data.message || 'Desconocido'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Hubo un error al procesar la solicitud');
        });
}

// Eliminar con JSON para evitar falso error por redirect
function confirmDelete(id) {
    if (confirm('¿Eliminar esta categoría?')) {
        fetch(`categories.php?delete=${id}`, { headers: { 'Accept': 'application/json' } })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const ct = res.headers.get('content-type') || '';
                return ct.includes('application/json') ? res.json() : { status: 'ok' };
            })
            .then(data => {
                if (data.status && data.status !== 'ok') throw new Error(data.message || 'Error');
                const tr = document.getElementById('cat-' + id);
                if (tr) tr.remove();
                showToast('Categoría eliminada correctamente');
                updateStats();
                applyFilter();
            })
            .catch(error => {
                // Si la fila ya no existe, tratamos como éxito silencioso
                const tr = document.getElementById('cat-' + id);
                if (!tr) {
                    showToast('Categoría eliminada correctamente');
                    updateStats();
                    applyFilter();
                    return false;
                }
                console.error('Error al eliminar:', error);
                alert('Hubo un error al eliminar la categoría');
            });
    }
    return false;
}

function showToast(msg) {
    const toast = document.getElementById('toastMessage');
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2200);
}

searchInput.addEventListener('input', applyFilter);
function applyFilter(){
    const q = searchInput.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('#categoryBody tr').forEach(tr => {
        const name = (tr.dataset.name || '').toLowerCase();
        const id = (tr.dataset.id || '').toLowerCase();
        const show = !q || name.includes(q) || id.includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    emptyHint.style.display = visible === 0 ? '' : 'none';
}

catNameInput.addEventListener('keydown', e => { if (e.key === 'Enter') saveCategory(); });

function updateStats(){
    const chip = document.getElementById('statsChip'); // puede no existir
    if (!chip) return;
    const total = document.querySelectorAll('#categoryBody tr').length;
    chip.textContent = `Total: ${total}`;
}

function escapeHtml(str){
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/\"/g,'&quot;')
        .replace(/'/g,'&#39;');
}
function escapeAttr(str){
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/\"/g,'&quot;')
        .replace(/'/g,'&#39;');
}

applyFilter();
</script>
</body>
</html>

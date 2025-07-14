<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = get_pdo();

// --- Helpers ---
function get_pages(PDO $pdo): array {
    return $pdo->query("SELECT * FROM page ORDER BY sort_order, title")
               ->fetchAll(PDO::FETCH_ASSOC);
}

function get_page_data(PDO $pdo, int $page_id): array {
    $sections = [];
    $sec = $pdo->prepare("SELECT * FROM section WHERE page_id = ? ORDER BY sort_order, name");
    $sec->execute([$page_id]);
    while ($s = $sec->fetch(PDO::FETCH_ASSOC)) {
        $tools = $pdo->prepare("SELECT * FROM link WHERE section_id = ? ORDER BY sort_order, name");
        $tools->execute([$s['id']]);
        $s['tools'] = $tools->fetchAll(PDO::FETCH_ASSOC);
        $sections[] = $s;
    }
    return $sections;
}

function update_sort_order(PDO $pdo, string $table, array $order): void {
    $upd = $pdo->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ?");
    foreach ($order as $i => $id) {
        $upd->execute([$i, (int)$id]);
    }
}

function save_page_groups(PDO $pdo, int $page_id, array $groupIds): void {
    $pdo->prepare("DELETE FROM page_group WHERE page_id = ?")
        ->execute([$page_id]);
    $ins = $pdo->prepare("INSERT INTO page_group (page_id, group_id) VALUES (?, ?)");
    foreach ($groupIds as $g) {
        $ins->execute([$page_id, (int)$g]);
    }
}

// Fetch all groups
$allGroups = $pdo->query("SELECT id, name FROM `groups` ORDER BY name")
                 ->fetchAll(PDO::FETCH_ASSOC);

// --- Handle POST ---
$alert = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Page
    if (isset($_POST['add_page'])) {
        $title = trim($_POST['page_title'] ?? '');
        if ($title === '') {
            $alert = ['danger', 'Page title required.'];
        } else {
            $pdo->prepare(
                "INSERT INTO page (title, sort_order)
                 VALUES (?, COALESCE((SELECT MAX(sort_order)+1 FROM page),0))"
            )->execute([$title]);
            $alert = ['success', "Page '{$title}' added."];
        }
    }

    // Edit Page (rename + groups)
    if (isset($_POST['edit_page'])) {
        $pid   = (int)$_POST['page_id'];
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') {
            $pdo->prepare("UPDATE page SET title = ? WHERE id = ?")
                ->execute([$title, $pid]);
        }
        $sel = $_POST['page_groups'] ?? [];
        save_page_groups($pdo, $pid, $sel);
        $alert = ['success', 'Page settings saved.'];
    }

    // Delete Page
    if (isset($_POST['delete_page'])) {
        $pid = (int)$_POST['page_id'];
        $pdo->prepare("DELETE FROM link WHERE section_id IN (SELECT id FROM section WHERE page_id = ?)")
            ->execute([$pid]);
        $pdo->prepare("DELETE FROM section WHERE page_id = ?")
            ->execute([$pid]);
        $pdo->prepare("DELETE FROM page WHERE id = ?")
            ->execute([$pid]);
        $alert = ['success', 'Page deleted.'];
    }

    // Add Section
    if (isset($_POST['add_section'])) {
        $pid  = (int)$_POST['page_id'];
        $name = trim($_POST['section_name'] ?? '');
        $desc = trim($_POST['section_description'] ?? '');
        $pdo->prepare(
            "INSERT INTO section (page_id,name,description,sort_order)
             VALUES (?, ?, ?, COALESCE((SELECT MAX(sort_order)+1 FROM section WHERE page_id=?),0))"
        )->execute([$pid, $name, $desc, $pid]);
        $alert = ['success', 'Section added.'];
    }

    // Rename Section
    if (isset($_POST['rename_section'])) {
        $sid  = (int)$_POST['section_id'];
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $pdo->prepare("UPDATE section SET name = ?, description = ? WHERE id = ?")
            ->execute([$name, $desc, $sid]);
        $alert = ['success', 'Section renamed.'];
    }

    // Delete Section
    if (isset($_POST['delete_section'])) {
        $sid = (int)$_POST['section_id'];
        $pdo->prepare("DELETE FROM link WHERE section_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM section WHERE id = ?")->execute([$sid]);
        $alert = ['success', 'Section deleted.'];
    }

    // Reorder Sections (AJAX)
    if (isset($_POST['reorder_sections'])) {
        update_sort_order($pdo, 'section', json_decode($_POST['order'], true));
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Reorder Links (AJAX)
    if (isset($_POST['reorder_links'])) {
        update_sort_order($pdo, 'link', json_decode($_POST['order'], true));
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Add Link
    if (isset($_POST['add_link'])) {
        $sid = (int)$_POST['section_id'];
        $pdo->prepare(
            "INSERT INTO link
             (section_id,name,description,url,logo,background,color,sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order)+1 FROM link WHERE section_id=?),0))"
        )->execute([
          $sid,
          trim($_POST['link_name'] ?? ''),
          trim($_POST['link_description'] ?? ''),
          trim($_POST['link_url'] ?? ''),
          trim($_POST['link_logo'] ?? ''),
          trim($_POST['link_background'] ?? ''),
          trim($_POST['link_color'] ?? ''),
          $sid
        ]);
        $alert = ['success','Link added.'];
    }

    // Edit Link
    if (isset($_POST['edit_link'])) {
        $lid = (int)$_POST['link_id'];
        $pdo->prepare(
            "UPDATE link
             SET name=?,description=?,url=?,logo=?,background=?,color=?
             WHERE id=?"
        )->execute([
          trim($_POST['name'] ?? ''),
          trim($_POST['description'] ?? ''),
          trim($_POST['url'] ?? ''),
          trim($_POST['logo'] ?? ''),
          trim($_POST['background'] ?? ''),
          trim($_POST['color'] ?? ''),
          $lid
        ]);
        $alert = ['success','Link updated.'];
    }

    // Delete Link
    if (isset($_POST['delete_link'])) {
        $lid = (int)$_POST['link_id'];
        $pdo->prepare("DELETE FROM link WHERE id = ?")->execute([$lid]);
        $alert = ['success','Link deleted.'];
    }

    header('Location: edit.php?page_id='
           . urlencode((int)($_POST['page_id'] ?? $_GET['page_id'])));
    exit;
}

// --- Load data for display ---
$pages           = get_pages($pdo);
$current_page_id = (int)($_GET['page_id'] ?? $pages[0]['id'] ?? 0);
$current_page    = null;
foreach ($pages as $p) {
    if ($p['id'] === $current_page_id) {
        $current_page = $p;
        break;
    }
}
$sections       = $current_page ? get_page_data($pdo, $current_page_id) : [];
$pageGroups     = get_page_groups($pdo, $current_page_id);
$pageGroupIds   = array_column($pageGroups, 'id');

// Prepare link data for modals
$linkData = [];
foreach ($sections as $sec) {
    foreach ($sec['tools'] as $tool) {
        $linkData[$tool['id']] = $tool;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit <?= htmlspecialchars($current_page['title'] ?? '') ?></title>
  <link rel="stylesheet" href="/css/styles.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>

<div class="container py-4">
  <?php if ($alert): ?>
    <div id="statusAlert" class="alert alert-<?= htmlspecialchars($alert[0]) ?>">
      <?= htmlspecialchars($alert[1]) ?>
    </div>
  <?php endif; ?>

  <!-- 1) Page Title -->
  <div class="row mb-2">
    <div class="col"><h2>Edit <?= htmlspecialchars($current_page['title'] ?? '') ?></h2></div>
  </div>

  <!-- 2) Visibility -->
  <div class="row mb-3">
    <div class="col page-visibility">
      <strong>Visibility:</strong>
      <?php if (empty($pageGroupIds)): ?>
        Public
      <?php else: ?>
        <?= implode(', ', array_column($pageGroups, 'name')) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- 3) Page selector + Edit/Delete Page -->
  <div class="row mb-3">
    <div class="col-auto">
      <select class="form-select" onchange="location='?page_id='+this.value">
        <?php foreach ($pages as $p): ?>
          <option value="<?= $p['id'] ?>"
            <?= $p['id'] === $current_page_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-secondary"
              data-bs-toggle="modal" data-bs-target="#editPageModal">
        Edit Page
      </button>
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-danger"
              data-bs-toggle="modal" data-bs-target="#deletePageModal">
        Delete Page
      </button>
    </div>
  </div>

  <!-- 4) Add Page + Add Section -->
  <div class="row mb-4">
    <div class="col-auto">
      <button class="btn btn-outline-primary"
              data-bs-toggle="modal" data-bs-target="#addPageModal">
        Add Page
      </button>
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-success"
              data-bs-toggle="modal" data-bs-target="#addSectionModal">
        Add Section
      </button>
    </div>
  </div>

  <!-- Sections & Links -->
  <div class="mb-3">
  <button id="saveSectionsOrderBtn"
          class="btn btn-outline-secondary btn-sm"
          style="display: none;">
    Save Section Order
  </button>
</div>
<div id="sections">
    <?php foreach ($sections as $sec): ?>
      <div class="card mb-4" data-si="<?= $sec['id'] ?>">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="draghandle" style="cursor:grab">☰</span>
          <strong><?= htmlspecialchars($sec['name']) ?></strong>
          <button class="btn btn-sm btn-outline-secondary"
                  data-bs-toggle="modal"
                  data-bs-target="#renameSectionModal<?= $sec['id'] ?>">
            Rename
          </button>
          <button class="btn btn-sm btn-outline-danger"
                  data-bs-toggle="modal"
                  data-bs-target="#deleteSectionModal<?= $sec['id'] ?>">
            Delete
          </button>
        </div>
        <div class="card-body">
          <button class="btn btn-outline-secondary btn-sm mb-2 saveLinksOrderBtn"
                  data-section="<?= $sec['id'] ?>" style="display:none">
            Save Link Order
          </button>
          <table class="table table-sm table-light mb-3 links" data-section="<?= $sec['id'] ?>">
            <thead class="table-light">
              <tr><th style="width:1%"></th><th>Name</th><th>Description</th><th>URL</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($sec['tools'] as $tool): ?>
                <tr data-li="<?= $tool['id'] ?>">
                  <td style="width: 5%;"><span class="draghandle" style="cursor:grab">☰</span></td>
                  <td style="width: 15%;"><?= htmlspecialchars($tool['name']) ?></td>
                  <td style="width: 30%;"><?= htmlspecialchars($tool['description']) ?></td>
                  <td style="width: 50%;"><?= htmlspecialchars($tool['url']) ?></td>
                  <td class="d-flex gap-1">
                    <button class="btn btn-sm btn-secondary"
                            data-bs-toggle="modal"
                            data-bs-target="#editLinkModal<?= $tool['id'] ?>">
                      Edit
                    </button>
                    <button class="btn btn-sm btn-outline-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#deleteLinkModal<?= $tool['id'] ?>">
                      Delete
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <button class="btn btn-success"
                  data-bs-toggle="modal"
                  data-bs-target="#addLinkModal<?= $sec['id'] ?>">
            Add Link
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modals -->

<!-- Add Page Modal -->
<div class="modal fade" id="addPageModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content">
    <input type="hidden" name="add_page" value="1">
    <div class="modal-header">
      <h5 class="modal-title">Add Page</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <label class="form-label">Title</label>
      <input name="page_title" class="form-control" required>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-primary">Add Page</button>
    </div>
  </form>
</div></div>

<!-- Edit Page Modal -->
<div class="modal fade" id="editPageModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content">
    <input type="hidden" name="edit_page" value="1">
    <input type="hidden" name="page_id"   value="<?= $current_page_id ?>">
    <div class="modal-header">
      <h5 class="modal-title">Edit Page & Visibility</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="mb-3">
        <label class="form-label">Title</label>
        <input name="title"
               class="form-control"
               value="<?= htmlspecialchars($current_page['title']) ?>"
               required>
      </div>
      <div class="mb-2"><strong>Visibility Groups</strong></div>
      <?php foreach ($allGroups as $g): ?>
        <div class="form-check form-check-inline">
          <input class="form-check-input"
                 type="checkbox"
                 name="page_groups[]"
                 value="<?= $g['id'] ?>"
                 id="pg<?= $g['id'] ?>"
                 <?= in_array($g['id'], $pageGroupIds, true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="pg<?= $g['id'] ?>">
            <?= htmlspecialchars($g['name']) ?>
          </label>
        </div>
      <?php endforeach; ?>
      <div class="form-text">Leave unchecked for public access.</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-primary">Save Changes</button>
    </div>
  </form>
</div></div>

<!-- Delete Page Modal -->
<div class="modal fade" id="deletePageModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content">
    <input type="hidden" name="delete_page" value="1">
    <input type="hidden" name="page_id"     value="<?= $current_page_id ?>">
    <div class="modal-header">
      <h5 class="modal-title">Delete Page</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      Delete “<strong><?= htmlspecialchars($current_page['title']) ?></strong>”?
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-danger">Delete Page</button>
    </div>
  </form>
</div></div>

<!-- Add Section Modal -->
<div class="modal fade" id="addSectionModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content">
    <input type="hidden" name="add_section" value="1">
    <input type="hidden" name="page_id"     value="<?= $current_page_id ?>">
    <div class="modal-header">
      <h5 class="modal-title">Add Section</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <label class="form-label">Section Name</label>
      <input name="section_name" class="form-control mb-2" required>
      <label class="form-label">Description</label>
      <textarea name="section_description" class="form-control"></textarea>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-primary">Add Section</button>
    </div>
  </form>
</div></div>

<?php foreach ($sections as $sec): ?>
  <!-- Rename Section Modal -->
  <div class="modal fade" id="renameSectionModal<?= $sec['id'] ?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="rename_section" value="1">
      <input type="hidden" name="section_id"     value="<?= $sec['id'] ?>">
      <div class="modal-header">
        <h5 class="modal-title">Rename Section</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">New Name</label>
        <input name="name" class="form-control" value="<?= htmlspecialchars($sec['name']) ?>" required>
        <label class="form-label mt-3">Description</label>
        <textarea name="description" class="form-control"><?= htmlspecialchars($sec['description']) ?></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Rename Section</button>
      </div>
    </form>
  </div></div>

  <!-- Delete Section Modal -->
  <div class="modal fade" id="deleteSectionModal<?= $sec['id'] ?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="delete_section" value="1">
      <input type="hidden" name="section_id"     value="<?= $sec['id'] ?>">
      <div class="modal-header">
        <h5 class="modal-title">Delete Section</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Delete section “<strong><?= htmlspecialchars($sec['name']) ?></strong>” and its links?
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger">Delete Section</button>
      </div>
    </form>
  </div></div>

  <!-- Add Link Modal -->
  <div class="modal fade" id="addLinkModal<?= $sec['id'] ?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="add_link" value="1">
      <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
      <div class="modal-header">
        <h5 class="modal-title">Add Link</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input name="link_name" class="form-control mb-2" placeholder="Name" required>
        <textarea name="link_description" class="form-control mb-2" placeholder="Description"></textarea>
        <input name="link_url" class="form-control mb-2" placeholder="URL">
        <input name="link_logo" class="form-control mb-2" placeholder="Logo URL">
        <div class="d-flex gap-2 mb-2">
          <div class="flex-fill">
            <label class="form-label">Background</label>
            <input type="color" class="form-control form-control-color"
                   onchange="this.nextElementSibling.value=this.value"
                   value="#000000">
            <input name="link_background" class="form-control mt-1" placeholder="#000000">
          </div>
          <div class="flex-fill">
            <label class="form-label">Text Color</label>
            <input type="color" class="form-control form-control-color"
                   onchange="this.nextElementSibling.value=this.value"
                   value="#ffffff">
            <input name="link_color" class="form-control mt-1" placeholder="#ffffff">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Add Link</button>
      </div>
    </form>
  </div></div>
<?php endforeach; ?>

<!-- Edit Link Modals -->
<?php foreach ($linkData as $tool): ?>
  <div class="modal fade" id="editLinkModal<?= $tool['id'] ?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="edit_link" value="1">
      <input type="hidden" name="link_id"   value="<?= $tool['id'] ?>">
      <div class="modal-header">
        <h5 class="modal-title">Edit Link – <?= htmlspecialchars($tool['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Name</label>
        <input name="name" class="form-control mb-2" value="<?= htmlspecialchars($tool['name']) ?>" required>

        <label class="form-label">Description</label>
        <textarea name="description" class="form-control mb-2"><?= htmlspecialchars($tool['description']) ?></textarea>

        <label class="form-label">URL</label>
        <input name="url" class="form-control mb-2" value="<?= htmlspecialchars($tool['url']) ?>">

        <label class="form-label">Logo URL</label>
        <input name="logo" class="form-control mb-2" value="<?= htmlspecialchars($tool['logo']) ?>">

        <div class="d-flex gap-2 mb-2">
          <div class="flex-fill">
            <label class="form-label">Background</label>
            <input type="color"
                   class="form-control form-control-color"
                   id="bg_picker<?= $tool['id'] ?>"
                   value="<?= htmlspecialchars($tool['background'] ?? '#000000') ?>">
            <input name="background"
                   id="bg_field<?= $tool['id'] ?>"
                   class="form-control mt-1"
                   value="<?= htmlspecialchars($tool['background'] ?? '#000000') ?>">
          </div>
          <div class="flex-fill">
            <label class="form-label">Text Color</label>
            <input type="color"
                   class="form-control form-control-color"
                   id="color_picker<?= $tool['id'] ?>"
                   value="<?= htmlspecialchars($tool['color'] ?? '#ffffff') ?>">
            <input name="color"
                   id="color_field<?= $tool['id'] ?>"
                   class="form-control mt-1"
                   value="<?= htmlspecialchars($tool['color'] ?? '#ffffff') ?>">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Save Link</button>
      </div>
    </form>
  </div></div>
<?php endforeach; ?>

<!-- Delete Link Modals -->
<?php foreach ($linkData as $tool): ?>
  <div class="modal fade" id="deleteLinkModal<?= $tool['id'] ?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="delete_link" value="1">
      <input type="hidden" name="link_id"     value="<?= $tool['id'] ?>">
      <div class="modal-header">
        <h5 class="modal-title">Delete Link</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Delete link “<strong><?= htmlspecialchars($tool['name']) ?></strong>”?
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger">Delete Link</button>
      </div>
    </form>
  </div></div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
  // Fade alert
  const st = document.getElementById('statusAlert');
  if (st) setTimeout(() => st.classList.add('fade'), 3000);

  // Section reorder
  const sectionsContainer = document.getElementById('sections');
const saveSectionsBtn = document.getElementById('saveSectionsOrderBtn');
const originalSectionsOrder = Array.from(sectionsContainer.children).map(e => e.outerHTML);

new Sortable(sectionsContainer, {
  handle: '.draghandle',
  animation: 150,
  ghostClass: 'sortable-ghost',
  onEnd: () => {
    if (saveSectionsBtn) saveSectionsBtn.style.display = 'inline-block';
    if (!document.getElementById('cancelSectionsOrderBtn')) {
      const cancelBtn = document.createElement('button');
      cancelBtn.id = 'cancelSectionsOrderBtn';
      cancelBtn.className = 'btn btn-outline-secondary btn-sm ms-2';
      cancelBtn.textContent = 'Cancel';
      saveSectionsBtn.after(cancelBtn);

      cancelBtn.addEventListener('click', () => {
        sectionsContainer.innerHTML = originalSectionsOrder.join('');
        cancelBtn.remove();
        saveSectionsBtn.style.display = 'none';
        location.reload(); // reload JS listeners
      });
    }
  }
});

saveSectionsBtn?.addEventListener('click', () => {
  const order = Array.from(document.querySelectorAll('[data-si]')).map(e => e.dataset.si);
  const fd = new FormData();
  fd.append('reorder_sections', 1);
  fd.append('order', JSON.stringify(order));
  fetch(location.href, { method: 'POST', body: fd }).then(r => r.ok && location.reload());
});
  document.getElementById('saveSectionsOrderBtn')?.addEventListener('click', ()=> {
    const order = Array.from(document.querySelectorAll('[data-si]')).map(e=>e.dataset.si);
    const fd = new FormData();
    fd.append('reorder_sections',1);
    fd.append('order',JSON.stringify(order));
    fetch(location.href,{method:'POST',body:fd})
      .then(r=>r.ok&&location.reload());
  });

  // Link reorder
  
document.querySelectorAll('.links').forEach(tb => {
  const sid = tb.dataset.section;
  const btn = tb.closest('.card-body').querySelector('.saveLinksOrderBtn');
  const cancelBtn = document.createElement('button');
  cancelBtn.className = 'btn btn-outline-secondary btn-sm mb-2 ms-2 cancelLinksOrderBtn';
  cancelBtn.textContent = 'Cancel';
  cancelBtn.style.display = 'none';
  btn.after(cancelBtn);

  const tbody = tb.querySelector('tbody');
  let originalOrder = Array.from(tbody.querySelectorAll('tr')).map(r => r.outerHTML);

  new Sortable(tbody, {
    handle: '.draghandle',
    animation: 150,
    ghostClass: 'sortable-ghost',
    onEnd: () => {
      btn.style.display = 'inline-block';
      cancelBtn.style.display = 'inline-block';
    }
  });

  btn.addEventListener('click', () => {
    const order = Array.from(tbody.querySelectorAll('tr')).map(r => r.dataset.li);
    const fd = new FormData();
    fd.append('reorder_links', 1);
    fd.append('order', JSON.stringify(order));
    fd.append('section_id', sid);
    fetch(location.href, { method: 'POST', body: fd }).then(r => r.ok && location.reload());
  });

  cancelBtn.addEventListener('click', () => {
    tbody.innerHTML = originalOrder.join('');
    btn.style.display = 'none';
    cancelBtn.style.display = 'none';
  });
});


  // Color pickers ↔ text sync
  document.querySelectorAll('input.form-control-color').forEach(ci=>{
    const idMatch = ci.id.match(/\d+$/);
    if(!idMatch) return;
    const id = idMatch[0];
    const isBg = ci.id.startsWith('bg_picker');
    const tf = document.getElementById((isBg?'bg_field':'color_field')+id);
    ['input','change'].forEach(evt=>ci.addEventListener(evt,()=>tf.value=ci.value.toUpperCase()));
    tf.addEventListener('input',()=>{
      if(/^#[0-9A-F]{6}$/.test(tf.value.toUpperCase())) ci.value=tf.value.toUpperCase();
    });
    ci.addEventListener('focus',()=>{
      if(/^#[0-9A-F]{6}$/.test(tf.value.toUpperCase())) ci.value=tf.value.toUpperCase();
    });
  });
</script>

<?php include 'footer.php'; ?>
</body>
</html>

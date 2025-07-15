<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// must be logged in
require_login();

$pdo     = get_pdo();
$user    = get_user();
$isAdmin = is_admin();

// --- fetch all pages ---
$allPages = $pdo
  ->query("SELECT * FROM page ORDER BY sort_order, title")
  ->fetchAll(PDO::FETCH_ASSOC);

// --- build the list of pages this user may manage ---
if ($isAdmin) {
  $editablePages = $allPages;
} else {
  // normal user: only pages with at least one group they belong to
  $myGroups = array_column(
    get_user_groups($pdo, (int)$user['id']),
    'id'
  );
  $editablePages = [];
  foreach ($allPages as $p) {
    $pgs = array_column(
      get_page_groups($pdo, (int)$p['id']),
      'id'
    );
    if (count($pgs) > 0 && array_intersect($pgs, $myGroups)) {
      $editablePages[] = $p;
    }
  }
}

// --- determine current page_id ---
$current_page_id = (int)($_GET['page_id'] ?? $editablePages[0]['id'] ?? 0);

// --- unauthorized if non-admin tries to pick an out-of-scope page_id ---
if (!$isAdmin) {
  $allowedIds = array_column($editablePages,'id');
  if (!in_array($current_page_id, $allowedIds, true)) {
    http_response_code(403);
    $first = $allowedIds[0] ?? '';
    // full HTML so header/footer & CSS are loaded
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>Unauthorized</title>
      <link rel="stylesheet" href="/css/styles.css">
      <link rel="stylesheet"
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    </head>
    <body>
      <?php include __DIR__ . '/header.php'; ?>
      <div class="container py-5">
        <div class="alert alert-danger text-center">
          Unauthorized action. Redirecting...
        </div>
      </div>
      <script>
        setTimeout(function(){
          location = 'edit.php?page_id=<?= htmlspecialchars($first,ENT_QUOTES) ?>';
        }, 3000);
      </script>
      <?php include __DIR__ . '/footer.php'; ?>
    </body>
    </html>
    <?php
    exit;
  }
}

// --- helper functions ---
function get_page_data(PDO $pdo, int $page_id): array {
  $sections = [];
  $sec = $pdo->prepare(
    "SELECT * FROM section WHERE page_id=? ORDER BY sort_order,name"
  );
  $sec->execute([$page_id]);
  while ($s = $sec->fetch(PDO::FETCH_ASSOC)) {
    $tools = $pdo->prepare(
      "SELECT * FROM link WHERE section_id=? ORDER BY sort_order,name"
    );
    $tools->execute([$s['id']]);
    $s['tools'] = $tools->fetchAll(PDO::FETCH_ASSOC);
    $sections[] = $s;
  }
  return $sections;
}
function update_sort_order(PDO $pdo, string $table, array $order): void {
  $upd = $pdo->prepare("UPDATE {$table} SET sort_order=? WHERE id=?");
  foreach ($order as $i=>$id) {
    $upd->execute([$i,(int)$id]);
  }
}
function save_page_groups(PDO $pdo, int $page_id, array $groupIds): void {
  $pdo->prepare("DELETE FROM page_group WHERE page_id=?")
      ->execute([$page_id]);
  $ins = $pdo->prepare(
    "INSERT INTO page_group (page_id,group_id) VALUES (?,?)"
  );
  foreach ($groupIds as $g) {
    $ins->execute([$page_id,(int)$g]);
  }
}

// --- fetch all groups for the “Edit Page” modal ---
$allGroups = $pdo
  ->query("SELECT id,name FROM `groups` ORDER BY name")
  ->fetchAll(PDO::FETCH_ASSOC);

// --- handle POST submissions ---
$alert = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // --- Admin-only: Add/Edit/Delete Page ---
  if ($isAdmin) {
    if (isset($_POST['add_page'])) {
      $title = trim($_POST['page_title'] ?? '');
      if ($title === '') {
        $alert = ['danger','Page title required.'];
      } else {
        $pdo->prepare(
          "INSERT INTO page (title,sort_order)
           VALUES (?,COALESCE((SELECT MAX(sort_order)+1 FROM page),0))"
        )->execute([$title]);
        $alert = ['success',"Page '{$title}' added."];
      }
    }
    if (isset($_POST['edit_page'])) {
      $pid   = (int)$_POST['page_id'];
      $title = trim($_POST['title'] ?? '');
      if ($title!=='') {
        $pdo->prepare("UPDATE page SET title=? WHERE id=?")
            ->execute([$title,$pid]);
      }
      $sel = $_POST['page_groups'] ?? [];
      save_page_groups($pdo,$pid,$sel);
      $alert = ['success','Page settings saved.'];
    }
    if (isset($_POST['delete_page'])) {
      $pid = (int)$_POST['page_id'];
      $pdo->prepare(
        "DELETE FROM link WHERE section_id IN
           (SELECT id FROM section WHERE page_id=?)"
      )->execute([$pid]);
      $pdo->prepare("DELETE FROM section WHERE page_id=?")
          ->execute([$pid]);
      $pdo->prepare("DELETE FROM page WHERE id=?")
          ->execute([$pid]);
      $alert = ['success','Page deleted.'];
    }
  }

  // --- Everyone who can manage this page: Add/Rename/Delete/Sort Sections & Links ---
  if (isset($_POST['add_section'])) {
    $pid  = (int)$_POST['page_id'];
    $name = trim($_POST['section_name'] ?? '');
    $desc = trim($_POST['section_description'] ?? '');
    $pdo->prepare(
      "INSERT INTO section (page_id,name,description,sort_order)
       VALUES (?, ?, ?, COALESCE((SELECT MAX(sort_order)+1
                                   FROM section WHERE page_id=?),0))"
    )->execute([$pid,$name,$desc,$pid]);
    $alert = ['success','Section added.'];
  }
  if (isset($_POST['rename_section'])) {
    $sid  = (int)$_POST['section_id'];
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $pdo->prepare(
      "UPDATE section SET name=?,description=? WHERE id=?"
    )->execute([$name,$desc,$sid]);
    $alert = ['success','Section renamed.'];
  }
  if (isset($_POST['delete_section'])) {
    $sid = (int)$_POST['section_id'];
    $pdo->prepare("DELETE FROM link WHERE section_id=?")
        ->execute([$sid]);
    $pdo->prepare("DELETE FROM section WHERE id=?")
        ->execute([$sid]);
    $alert = ['success','Section deleted.'];
  }

  if (isset($_POST['reorder_sections'])) {
    update_sort_order($pdo,'section',json_decode($_POST['order'],true));
    echo json_encode(['status'=>'ok']);
    exit;
  }
  if (isset($_POST['reorder_links'])) {
    update_sort_order($pdo,'link',json_decode($_POST['order'],true));
    echo json_encode(['status'=>'ok']);
    exit;
  }

  if (isset($_POST['add_link'])) {
    $sid = (int)$_POST['section_id'];
    $pdo->prepare(
      "INSERT INTO link
       (section_id,name,description,url,logo,background,color,sort_order)
       VALUES(?,?,?,?,?,?,?,
              COALESCE((SELECT MAX(sort_order)+1
                        FROM link WHERE section_id=?),0))"
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
  if (isset($_POST['edit_link'])) {
    $lid = (int)$_POST['link_id'];
    $pdo->prepare(
      "UPDATE link SET
         name=?,description=?,url=?,logo=?,background=?,color=?
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
  if (isset($_POST['delete_link'])) {
    $lid = (int)$_POST['link_id'];
    $pdo->prepare("DELETE FROM link WHERE id=?")
        ->execute([$lid]);
    $alert = ['success','Link deleted.'];
  }

  // redirect back
  header('Location: edit.php?page_id=' . urlencode($current_page_id));
  exit;
}

// --- load data for display ---
$sections     = get_page_data($pdo,$current_page_id);
$pageGroups   = get_page_groups($pdo,$current_page_id);
$pageGroupIds = array_column($pageGroups,'id');

// prepare linkData for edit/delete link modals
$linkData = [];
foreach ($sections as $sec) {
  foreach ($sec['tools'] as $t) {
    $linkData[$t['id']] = $t;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit <?= htmlspecialchars(
    array_values(array_filter($allPages,fn($pp)=>
      $pp['id']===$current_page_id
    ))[0]['title'] ?? ''
  ) ?></title>
  <link rel="stylesheet" href="/css/styles.css">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container py-4">
  <?php if ($alert): ?>
    <div id="statusAlert" class="alert alert-<?= htmlspecialchars($alert[0]) ?>">
      <?= htmlspecialchars($alert[1]) ?>
    </div>
  <?php endif; ?>

  <!-- Page selector for ALL managers -->
  <div class="row mb-3">
    <div class="col-auto">
      <select class="form-select"
              onchange="location='?page_id='+this.value">
        <?php foreach ($editablePages as $p): ?>
          <option value="<?= $p['id'] ?>"
            <?= $p['id']===$current_page_id?'selected':'' ?>>
            <?= htmlspecialchars($p['title'],ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Title & (admin) Edit/Delete Page -->
  <div class="row mb-2">
    <div class="col"><h2 style="color: white !important">
      Edit <?= htmlspecialchars(
        array_values(array_filter($allPages,fn($pp)=>
          $pp['id']===$current_page_id
        ))[0]['title']
      ) ?>
    </h2></div>
  </div>

  <!-- Visibility -->
  <div class="row mb-3">
    <div class="col page-visibility">
      <strong>Visibility:</strong>
      <?php if (empty($pageGroupIds)): ?>
        Public
      <?php else: ?>
        <?= implode(', ',array_column($pageGroups,'name')) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Add Page/Section -->
  <div class="row mb-4">
    <?php if ($isAdmin): ?>
      <div class="col-auto">
        <button class="btn btn-outline-primary"
                data-bs-toggle="modal"
                data-bs-target="#addPageModal">
          Add Page
        </button>
        <button class="btn btn-outline-secondary"
                data-bs-toggle="modal"
                data-bs-target="#editPageModal">
          Edit Page
        </button>
        <button class="btn btn-outline-danger"
                data-bs-toggle="modal"
                data-bs-target="#deletePageModal">
          Delete Page
        </button>
      </div>
    <?php endif; ?>
    <div class="col-auto">
      <button class="btn btn-outline-success"
              data-bs-toggle="modal"
              data-bs-target="#addSectionModal">
        Add Section
      </button>
    </div>
  </div>

  <!-- Sort controls -->
  <div class="mb-3">
    <button id="saveSectionsOrderBtn"
            class="btn btn-outline-secondary btn-sm"
            style="display:none">Save Section Order</button>
    <button id="cancelSectionsOrderBtn"
            class="btn btn-outline-secondary btn-sm ms-2"
            style="display:none">Cancel</button>
  </div>

  <!-- Sections & Links -->
  <div id="sections">
  <?php foreach ($sections as $sec): ?>
    <div class="card mb-4" data-si="<?= $sec['id'] ?>">
      <div class="card-header d-flex align-items-center">
        <span class="draghandle me-2" style="cursor:grab">☰</span>
        <strong class="me-auto"><?= htmlspecialchars($sec['name']) ?></strong>
        <button class="btn btn-sm btn-secondary me-2"
                data-bs-toggle="modal"
                data-bs-target="#renameSectionModal<?= $sec['id'] ?>">
          Edit
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
        <button class="btn btn-outline-secondary btn-sm mb-2 ms-2 cancelLinksOrderBtn"
                data-section="<?= $sec['id'] ?>" style="display:none">
          Cancel
        </button>
        <table class="table table-sm table-light mb-3 links"
               data-section="<?= $sec['id'] ?>">
          <thead class="table-light">
            <tr>
              <th style="width:50px;"></th>
              <th style="width:15%;">Name</th>
              <th style="width:35%;">Description</th>
              <th style="width:35%;">URL</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sec['tools'] as $tool): ?>
            <tr data-li="<?= $tool['id'] ?>">
              <td><span class="draghandle" style="cursor:grab">☰</span></td>
              <td><?= htmlspecialchars($tool['name']) ?></td>
              <td><?= htmlspecialchars($tool['description']) ?></td>
              <td><?= htmlspecialchars($tool['url']) ?></td>
              <td class="text-end">
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

<!-- =================
     MODALS (unchanged)
     ================= -->
<?php if ($isAdmin): ?>
  <!-- Add/Edit/Delete Page modals here… -->
<?php endif; ?>

<!-- Add/Rename/Delete Section & Add/Edit/Delete Link modals here… -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
// Fade alert
const st = document.getElementById('statusAlert');
if (st) setTimeout(()=>st.classList.add('fade'),3000);

// Section reorder
const sectionsContainer = document.getElementById('sections');
const saveSecBtn = document.getElementById('saveSectionsOrderBtn');
const cancelSecBtn = document.getElementById('cancelSectionsOrderBtn');
let origSec = Array.from(sectionsContainer.children).map(e=>e.outerHTML);
new Sortable(sectionsContainer,{
  handle: '.draghandle',
  animation:150,
  onEnd:()=>{
    saveSecBtn.style.display='inline-block';
    cancelSecBtn.style.display='inline-block';
  }
});
saveSecBtn.addEventListener('click',()=>{
  const order = Array.from(
    document.querySelectorAll('[data-si]')
  ).map(e=>e.dataset.si);
  const fd = new FormData();
  fd.append('reorder_sections',1);
  fd.append('order',JSON.stringify(order));
  fetch(location.href,{method:'POST',body:fd})
    .then(r=>r.ok && location.reload());
});
cancelSecBtn.addEventListener('click',()=>{
  sectionsContainer.innerHTML = origSec.join('');
  saveSecBtn.style.display='none';
  cancelSecBtn.style.display='none';
});

// Link reorder
document.querySelectorAll('.links').forEach(tb=>{
  const sid    = tb.dataset.section;
  const btn    = tb.closest('.card-body').querySelector('.saveLinksOrderBtn');
  const cancel = tb.closest('.card-body').querySelector('.cancelLinksOrderBtn');
  const tbody  = tb.querySelector('tbody');
  let orig = Array.from(tbody.querySelectorAll('tr')).map(r=>r.outerHTML);

  new Sortable(tbody,{
    handle: '.draghandle',
    animation:150,
    onEnd:()=>{
      btn.style.display='inline-block';
      cancel.style.display='inline-block';
    }
  });
  btn.addEventListener('click',()=>{
    const order = Array.from(tbody.querySelectorAll('tr'))
                       .map(r=>r.dataset.li);
    const fd = new FormData();
    fd.append('reorder_links',1);
    fd.append('order',JSON.stringify(order));
    fd.append('section_id',sid);
    fetch(location.href,{method:'POST',body:fd})
      .then(r=>r.ok && location.reload());
  });
  cancel.addEventListener('click',()=>{
    tbody.innerHTML = orig.join('');
    btn.style.display='none';
    cancel.style.display='none';
  });
});

// Color picker sync
document.querySelectorAll('input.form-control-color').forEach(ci=>{
  const m    = ci.id.match(/\d+$/);
  if(!m) return;
  const id   = m[0],
        isBg = ci.id.startsWith('bg_picker'),
        tf   = document.getElementById(
                 (isBg?'bg_field':'color_field')+id
               );
  ['input','change'].forEach(evt=>
    ci.addEventListener(evt,()=>tf.value=ci.value.toUpperCase())
  );
  tf.addEventListener('input',()=>{
    if(/^#[0-9A-F]{6}$/.test(tf.value.toUpperCase()))
      ci.value = tf.value.toUpperCase();
  });
});
</script>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>


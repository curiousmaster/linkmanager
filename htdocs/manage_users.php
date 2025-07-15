<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_admin();
$pdo = get_pdo();

// Fetch fixed lists
$roles  = $pdo->query("SELECT name FROM role ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$groups = $pdo->query("SELECT id, name FROM groups ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$alert = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- User CRUD ---
    if (isset($_POST['add_user'])) {
        $username  = trim($_POST['username']  ?? '');
        $password  = $_POST['password']      ?? '';
        $selRoles  = $_POST['roles']         ?? [];
        $selGroups = $_POST['groups']        ?? [];
        if (!$username || !$password || empty($selRoles)) {
            $alert = ['danger', 'Username, password & at least one role required.'];
        } elseif (strlen($password) < 6) {
            $alert = ['danger', 'Password must be at least 6 characters.'];
        } else {
            $exists = $pdo->prepare("SELECT id FROM user WHERE username=?");
            $exists->execute([$username]);
            if ($exists->fetch()) {
                $alert = ['danger', "User '$username' already exists."];
            } else {
                $pdo->beginTransaction();
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO user (username,password) VALUES (?,?)")
                        ->execute([$username, $hash]);
                    $uid = (int)$pdo->lastInsertId();
                    // roles
                    foreach ($selRoles as $r) {
                        $rid = $pdo->prepare("SELECT id FROM role WHERE name=?");
                        $rid->execute([$r]);
                        if ($roleId = $rid->fetchColumn()) {
                            $pdo->prepare("INSERT INTO user_role (user_id,role_id) VALUES (?,?)")
                                ->execute([$uid, (int)$roleId]);
                        }
                    }
                    // groups
                    foreach ($selGroups as $g) {
                        $pdo->prepare("INSERT INTO user_group (user_id,group_id) VALUES (?,?)")
                            ->execute([$uid, (int)$g]);
                    }
                    $pdo->commit();
                    $alert = ['success', "User '$username' added."];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $alert = ['danger', 'Failed to add user.'];
                }
            }
        }
    }

    if (isset($_POST['edit_user'])) {
        $uid = intval($_POST['user_id']);
        $stmtU = $pdo->prepare("SELECT username FROM user WHERE id=?");
        $stmtU->execute([$uid]);
        $uname = $stmtU->fetchColumn();
        if ($uname !== 'admin') {
            $selRoles  = $_POST['roles']  ?? [];
            $selGroups = $_POST['groups'] ?? [];
            $newPass   = trim($_POST['password'] ?? '');

            $pdo->beginTransaction();
            try {
                // Update password if provided
                if ($newPass !== '') {
                    if (strlen($newPass) < 6) {
                        throw new Exception("Password too short");
                    }
                    $hash = password_hash($newPass, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE user SET password=? WHERE id=?")
                        ->execute([$hash, $uid]);
                }

                // Refresh roles & groups
                $pdo->prepare("DELETE FROM user_role  WHERE user_id=?")->execute([$uid]);
                $pdo->prepare("DELETE FROM user_group WHERE user_id=?")->execute([$uid]);
                foreach ($selRoles as $r) {
                    $rid = $pdo->prepare("SELECT id FROM role WHERE name=?");
                    $rid->execute([$r]);
                    if ($roleId = $rid->fetchColumn()) {
                        $pdo->prepare("INSERT INTO user_role (user_id,role_id) VALUES (?,?)")
                            ->execute([$uid, (int)$roleId]);
                    }
                }
                foreach ($selGroups as $g) {
                    $pdo->prepare("INSERT INTO user_group (user_id,group_id) VALUES (?,?)")
                        ->execute([$uid, (int)$g]);
                }

                $pdo->commit();
                $alert = ['success','User updated.'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $alert = ['danger','Failed to update user.'];
            }
        } else {
            $alert = ['danger','Cannot edit admin.'];
        }
    }

    if (isset($_POST['delete_user'])) {
        $uid = intval($_POST['user_id']);
        $stmtU = $pdo->prepare("SELECT username FROM user WHERE id=?");
        $stmtU->execute([$uid]);
        $uname = $stmtU->fetchColumn();
        if ($uname !== 'admin') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM user_group WHERE user_id=?")->execute([$uid]);
                $pdo->prepare("DELETE FROM user_role  WHERE user_id=?")->execute([$uid]);
                $pdo->prepare("DELETE FROM user       WHERE id=?")->execute([$uid]);
                $pdo->commit();
                $alert = ['success','User deleted.'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $alert = ['danger','Failed to delete user.'];
            }
        } else {
            $alert = ['danger','Cannot delete admin.'];
        }
    }

    // --- Group CRUD ---
    if (isset($_POST['add_group'])) {
        $gname = trim($_POST['group_name'] ?? '');
        if ($gname === '') {
            $alert = ['danger','Group name required.'];
        } else {
            try {
                $pdo->prepare("INSERT INTO groups (name) VALUES (?)")
                    ->execute([$gname]);
                $alert = ['success', "Group '$gname' added."];
            } catch (Exception $e) {
                $alert = ['danger','Failed to add group.'];
            }
        }
    }

    if (isset($_POST['edit_group'])) {
        $gid   = intval($_POST['group_id']);
        $gname = trim($_POST['group_name'] ?? '');
        if ($gname === '') {
            $alert = ['danger','Group name required.'];
        } else {
            $pdo->prepare("UPDATE groups SET name=? WHERE id=?")
                ->execute([$gname, $gid]);
            $alert = ['success','Group renamed.'];
        }
    }

    if (isset($_POST['delete_group'])) {
        $gid = intval($_POST['group_id']);
        $pdo->prepare("DELETE FROM user_group  WHERE group_id=?")->execute([$gid]);
        $pdo->prepare("DELETE FROM page_group  WHERE group_id=?")->execute([$gid]);
        $pdo->prepare("DELETE FROM groups       WHERE id=?")->execute([$gid]);
        $alert = ['success','Group deleted.'];
    }

    // Redirect to avoid resubmission
    header('Location: manage_users.php');
    exit;
}

// Reload data
$users = $pdo->query("SELECT * FROM user ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as &$u) {
    $u['roles']  = get_user_roles($pdo, $u['id']);
    $u['groups'] = array_column(get_user_groups($pdo, $u['id']), 'id');
}
unset($u);
$groups = $pdo->query("SELECT id,name FROM groups ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Users &amp; Groups</title>
  <link rel="stylesheet" href="/css/styles.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>

<div class="container py-4">
  <h2>Manage Users &amp; Groups</h2>
  <?php if ($alert): ?>
    <div class="alert alert-<?=htmlspecialchars($alert[0])?>"><?=htmlspecialchars($alert[1])?></div>
  <?php endif; ?>

  <!-- Users Section -->
  <div class="mb-5">
    <h3>Users</h3>
    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addUserModal">Add User</button>
    <table class="table table-striped">
      <thead>
	<tr>
	   <th style="width:30%">Username</th>
	   <th style="width:30%">Roles</th>
	   <th style="width:30%">Groups</th>
	   <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?=htmlspecialchars($u['username'])?></td>
          <td><?=htmlspecialchars(implode(', ',$u['roles']))?></td>
          <td>
            <?php foreach ($groups as $g): ?>
              <?php if (in_array($g['id'],$u['groups'])): ?>
                <?=htmlspecialchars($g['name'])?><br>
              <?php endif; ?>
            <?php endforeach; ?>
          </td>
          <td class="text-end">
            <?php if ($u['username'] !== 'admin'): ?>
              <button class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#editUserModal<?=$u['id']?>">Edit</button>
              <button class="btn btn-sm btn-danger"    data-bs-toggle="modal" data-bs-target="#deleteUserModal<?=$u['id']?>">Delete</button>
            <?php else: ?>
              <span class="text-muted">System</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Groups Section -->
  <div>
    <h3>Groups</h3>
    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addGroupModal">Add Group</button>
    <table class="table table-hover">
      <thead>
	<tr>
	  <th style="width:30%">Name</th>
	  <th style="width:30%">Members</th>
	  <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($groups as $g): ?>
        <?php $cnt = $pdo->prepare("SELECT COUNT(*) FROM user_group WHERE group_id=?"); $cnt->execute([$g['id']]); ?>
        <tr>
          <td><?=htmlspecialchars($g['name'])?></td>
          <td><?=$cnt->fetchColumn()?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-secondary me-2" data-bs-toggle="modal" data-bs-target="#editGroupModal<?=$g['id']?>">Edit</button>
            <button class="btn btn-sm btn-danger"              data-bs-toggle="modal" data-bs-target="#deleteGroupModal<?=$g['id']?>">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content">
    <input type="hidden" name="add_user" value="1">
    <div class="modal-header">
      <h5 class="modal-title">Add User</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="mb-2">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required>
      </div>
      <div class="mb-2">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" minlength="6" required>
      </div>
      <div class="mb-2">
        <label class="form-label">Roles</label>
        <?php foreach ($roles as $r): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="roles[]" value="<?=$r?>" id="role_add_<?=$r?>">
            <label class="form-check-label" for="role_add_<?=$r?>"><?=$r?></label>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mb-2">
        <label class="form-label">Groups</label>
        <?php foreach ($groups as $g): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="groups[]" value="<?=$g['id']?>" id="group_add_<?=$g['id']?>">
            <label class="form-check-label" for="group_add_<?=$g['id']?>"><?=htmlspecialchars($g['name'])?></label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-primary" type="submit">Add</button>
    </div>
  </form>
</div></div>

<!-- Edit/Delete User Modals -->
<?php foreach ($users as $u): if ($u['username']==='admin') continue; ?>
  <!-- Edit User -->
  <div class="modal fade" id="editUserModal<?=$u['id']?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="edit_user" value="1">
      <input type="hidden" name="user_id"   value="<?=$u['id']?>">
      <div class="modal-header">
        <h5 class="modal-title">Edit <?=htmlspecialchars($u['username'])?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Optional new password field -->
        <div class="mb-2">
          <label class="form-label">New Password</label>
          <input type="password" name="password" class="form-control" minlength="6" placeholder="Leave empty to keep current">
        </div>
        <div class="mb-2">
          <label class="form-label">Roles</label>
          <?php foreach ($roles as $r): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="roles[]" value="<?=$r?>" id="role<?=$u['id']?>_<?=$r?>"
                     <?=in_array($r, $u['roles'])?'checked':''?>>
              <label class="form-check-label" for="role<?=$u['id']?>_<?=$r?>"><?=$r?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mb-2">
          <label class="form-label">Groups</label>
          <?php foreach ($groups as $g): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="groups[]" value="<?=$g['id']?>" id="group<?=$u['id']?>_<?=$g['id']?>"
                     <?=in_array($g['id'], $u['groups'])?'checked':''?>>
              <label class="form-check-label" for="group<?=$u['id']?>_<?=$g['id']?>"><?=htmlspecialchars($g['name'])?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div></div>

  <!-- Delete User -->
  <div class="modal fade" id="deleteUserModal<?=$u['id']?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="delete_user" value="1">
      <input type="hidden" name="user_id"     value="<?=$u['id']?>">
      <div class="modal-header">
        <h5 class="modal-title">Delete <?=htmlspecialchars($u['username'])?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to delete <strong><?=htmlspecialchars($u['username'])?></strong>?
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" type="submit">Delete</button>
      </div>
    </form>
  </div></div>
<?php endforeach; ?>

<!-- Add/Edit/Delete Group Modals -->
<!-- Add Group -->
<div class="modal fade" id="addGroupModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content">
    <input type="hidden" name="add_group" value="1">
    <div class="modal-header">
      <h5 class="modal-title">Add Group</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <label class="form-label">Group Name</label>
      <input type="text" name="group_name" class="form-control" required>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-primary" type="submit">Add</button>
    </div>
  </form>
</div></div>

<?php foreach ($groups as $g): ?>
  <!-- Edit Group -->
  <div class="modal fade" id="editGroupModal<?=$g['id']?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="edit_group" value="1">
      <input type="hidden" name="group_id"   value="<?=$g['id']?>">
      <div class="modal-header">
        <h5 class="modal-title">Edit Group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">New Name</label>
        <input type="text" name="group_name" value="<?=htmlspecialchars($g['name'])?>" class="form-control" required>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div></div>

  <!-- Delete Group -->
  <div class="modal fade" id="deleteGroupModal<?=$g['id']?>" tabindex="-1"><div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="delete_group" value="1">
      <input type="hidden" name="group_id"     value="<?=$g['id']?>">
      <div class="modal-header">
        <h5 class="modal-title">Delete Group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to delete <strong><?=htmlspecialchars($g['name'])?></strong>?<br>
        This will remove it from all users and pages.
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" type="submit">Delete</button>
      </div>
    </form>
  </div></div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

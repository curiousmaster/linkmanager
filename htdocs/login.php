<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = get_pdo();
    $uname = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, username, password FROM user WHERE username = ?");
    $stmt->execute([$uname]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && password_verify($pass, $row['password'])) {
        // Fetch ALL roles for this user
        $role_stmt = $pdo->prepare("SELECT r.name FROM user_role ur JOIN role r ON ur.role_id = r.id WHERE ur.user_id = ?");
        $role_stmt->execute([$row['id']]);
        $roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);

	$_SESSION['user'] = ['id' => $row['id'], 'username' => $row['username']];

        $_SESSION['roles'] = $roles;

        header('Location: /');
        exit;
    } else {
         $error = "Login failed.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login – Link Collection</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/styles.css">
    <style>
        body {
            background: #121212;
            min-height: 100vh;
            color: #eee;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <main>
        <div class="login-panel mx-auto">
            <img src="images/links.png" alt="Logo" class="logo">
            <div class="login-title mb-4">Sign in to Link Collection</div>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 text-center"><?=htmlspecialchars($error)?></div>
            <?php endif ?>
            <form method="post" autocomplete="off">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input class="form-control" id="username" name="username" placeholder="Username" required autofocus>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label d-flex justify-content-between align-items-center">
                        <span>Password</span>
                    </label>
                    <input class="form-control" id="password" name="password" placeholder="Password" type="password" required>
                </div>
                <button class="btn btn-primary w-100 py-2" type="submit">Login</button>
            </form>
        </div>
    </main>
</body>
</html>


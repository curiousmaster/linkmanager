<?php
require_once __DIR__ . '/db.php';
// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Return current logged-in user array or null.
 */
function get_user(): ?array {
    return (isset($_SESSION['user']) && is_array($_SESSION['user']))
        ? $_SESSION['user']
        : null;
}

/**
 * Fetch all roles assigned to a user.
 */
function get_user_roles(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare(
        "SELECT r.name
         FROM user_role ur
         JOIN role r ON ur.role_id = r.id
         WHERE ur.user_id = ?"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Fetch all groups assigned to a user.
 */
function get_user_groups(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare(
        "SELECT g.id, g.name
         FROM user_group ug
         JOIN groups g ON ug.group_id = g.id
         WHERE ug.user_id = ?"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if currently logged-in user is an admin.
 */
function is_admin(): bool {
    $user = get_user();
    if (!$user) {
        return false;
    }
    $pdo = get_pdo();
    $roles = get_user_roles($pdo, (int)$user['id']);
    return in_array('admin', $roles, true);
}

/**
 * Redirect to login if not logged in.
 */
function require_login(): void {
    if (!get_user()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Redirect to home if not admin.
 */
function require_admin(): void {
    if (!is_admin()) {
        header('Location: /');
        exit;
    }
}

/**
 * Fetch all groups assigned to a page.
 */
function get_page_groups(PDO $pdo, int $page_id): array {
    $stmt = $pdo->prepare(
        "SELECT g.id, g.name
         FROM page_group pg
         JOIN groups g ON pg.group_id = g.id
         WHERE pg.page_id = ?"
    );
    $stmt->execute([$page_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Determine whether the given user (or guest if null) may view the page.
 * - Admins always may.
 * - Public pages (no groups) always may.
 * - Otherwise user must belong to at least one group assigned.
 */
function can_view_page(PDO $pdo, ?array $user, int $page_id): bool {
    // Guests
    if (!$user) {
        $pgs = get_page_groups($pdo, $page_id);
        return count($pgs) === 0;
    }
    // Admin
    if (is_admin()) {
        return true;
    }
    // Public
    $pgs = get_page_groups($pdo, $page_id);
    if (count($pgs) === 0) {
        return true;
    }
    // Check user groups
    $ugs = get_user_groups($pdo, (int)$user['id']);
    $allowed = array_column($ugs, 'id');
    foreach ($pgs as $g) {
        if (in_array($g['id'], $allowed, true)) {
            return true;
        }
    }
    return false;
}
?>


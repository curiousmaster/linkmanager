<?php
require_once __DIR__ . '/auth.php';

$user     = get_user();
$is_admin = is_admin();
$search   = trim($_GET['search']  ?? '');
$view     = $_GET['view']        ?? ($_COOKIE['view'] ?? 'card');

// Make sure $current_page_title is defined (index.php sets $currentPageTitle)
if (!isset($current_page_title) && isset($currentPageTitle)) {
    $current_page_title = $currentPageTitle;
}
$current = htmlspecialchars($current_page_title ?? '', ENT_QUOTES);

// The “main” page is always the first in the $pages array
$mainTitle = $pages[0]['title'] ?? '';
?>
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<header class="headerbar">
  <!-- Logo + Title -->
  <a href="/?page=<?= urlencode($mainTitle) ?>"
     class="d-flex align-items-center text-decoration-none"
     style="gap:.7em;">
    <img src="/images/links.png" class="header-logo" alt="Logo">
    <span class="header-title">LINK MANAGER</span>
  </a>

  <!-- Search form -->
  <div class="header-search-center">
    <form method="get" autocomplete="off" class="header-search-form">
      <!-- Preserve page & view -->
      <input type="hidden" name="page" value="<?= $current ?>">
      <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
      <div class="input-group header-search-group">
        <input type="text"
               class="form-control"
               name="search"
               value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"
               placeholder="Search links…">
        <?php if ($search !== ''): ?>
          <button class="btn btn-outline-secondary clear-btn"
                  type="button"
                  onclick="this.form.search.value=''; this.form.submit();">
            &times;
          </button>
        <?php endif; ?>
        <button class="btn btn-primary search-btn" type="submit">
          <i class="bi bi-search"></i>
        </button>
      </div>
    </form>
  </div>

  <!-- View‐mode + admin + login/logout -->
  <div class="header-actions">
    <a href="?page=<?= urlencode($current) ?>&view=card<?= $search?'&search='.urlencode($search):'' ?>"
       class="header-view-btn<?= $view==='card'?' active':'' ?>" title="Card View">
      <i class="bi bi-grid-3x3-gap"></i>
    </a>
    <a href="?page=<?= urlencode($current) ?>&view=minimal<?= $search?'&search='.urlencode($search):'' ?>"
       class="header-view-btn<?= $view==='minimal'?' active':'' ?>" title="Minimal View">
      <i class="bi bi-list"></i>
    </a>
    <a href="?page=<?= urlencode($current) ?>&view=tree<?= $search?'&search='.urlencode($search):'' ?>"
       class="header-view-btn<?= $view==='tree'?' active':'' ?>" title="Tree View">
      <i class="bi bi-diagram-3"></i>
    </a>

    <?php if ($is_admin): ?>
      <a href="/manage_users.php" class="btn btn-outline-light" title="Manage Users">
        <i class="bi bi-people"></i>
      </a>
      <a href="/edit.php" class="btn btn-outline-light" title="Edit Collection">
        <i class="bi bi-pencil"></i>
      </a>
    <?php endif; ?>

    <?php if ($user): ?>
      <a href="/logout.php" class="btn btn-outline-light" title="Logout">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    <?php else: ?>
      <a href="/login.php" class="btn btn-outline-light" title="Login">
        <i class="bi bi-person"></i>
      </a>
    <?php endif; ?>
  </div>
</header>


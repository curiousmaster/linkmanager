<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// ---- Setup ----
$pdo    = get_pdo();
$user   = get_user();
$search = trim($_GET['search'] ?? '');

// Fetch all pages and apply RBAC
$allPages = $pdo->query("SELECT * FROM page ORDER BY sort_order, title")
                ->fetchAll(PDO::FETCH_ASSOC);
$pages = [];
foreach ($allPages as $p) {
    if (can_view_page($pdo, $user, (int)$p['id'])) {
        $pages[] = $p;
    }
}

// Determine requested page (for non‐search)
$requestedTitle = $_GET['page'] ?? null;
if ($requestedTitle !== null) {
    $allowed = array_column($pages, 'title');
    if (!in_array($requestedTitle, $allowed, true)) {
        // 403 layout using the normal header/sidebar/main/footer
        http_response_code(403);
        $first = urlencode($pages[0]['title'] ?? '');

        // show header
        include __DIR__ . '/header.php';
        ?>
        <div class="d-flex" style="min-height: calc(100vh - 64px);">
          <!-- Sidebar -->
          <nav id="sidebar" class="bg-dark">
            <div class="list-group list-group-flush">
              <?php foreach ($pages as $p): ?>
                <a href="?page=<?= urlencode($p['title']) ?>&view=<?= htmlspecialchars($_GET['view'] ?? ($_COOKIE['view'] ?? 'card'), ENT_QUOTES) ?>"
                   class="list-group-item list-group-item-action<?= strcasecmp($p['title'], $requestedTitle)===0 ? ' active' : '' ?>">
                  <?= htmlspecialchars($p['title'], ENT_QUOTES) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </nav>

          <!-- Main Content -->
          <main class="flex-grow-1 p-3">
            <div class="alert alert-danger text-center">
              <h4>You are not authorized to view “<?= htmlspecialchars($requestedTitle, ENT_QUOTES) ?>”.</h4>
              <p>Redirecting back to your home page in 3 seconds…</p>
            </div>
          </main>
        </div>
        <?php
        include __DIR__ . '/footer.php';
        echo "<script>setTimeout(()=>location='/?page={$first}',3000)</script>";
        exit;
    }
}

// Persist last_page cookie
$cookie           = $_COOKIE['last_page'] ?? '';
$default          = $pages[0]['title'] ?? '';
$currentPageTitle = $requestedTitle
    ? $requestedTitle
    : ($cookie ?: $default);
if (isset($_GET['page']) && $cookie !== $currentPageTitle) {
    setcookie('last_page', $currentPageTitle, time()+2592000, '/');
}

// Find current page record
$currentPage = null;
foreach ($pages as $p) {
    if (strcasecmp($p['title'], $currentPageTitle) === 0) {
        $currentPage = $p;
        break;
    }
}

// View mode (card/minimal/tree)
$view = $_GET['view'] ?? $_COOKIE['view'] ?? 'card';
if (isset($_GET['view']) && $_COOKIE['view'] !== $view) {
    setcookie('view', $view, [
        'expires'  => time()+2592000,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

// —— SEARCH MODE ——
$searchResults = [];
if ($search !== '') {
    foreach ($pages as $p) {
        $secStmt = $pdo->prepare("SELECT * FROM section WHERE page_id=? ORDER BY sort_order,name");
        $secStmt->execute([(int)$p['id']]);
        $pageSecs = $secStmt->fetchAll(PDO::FETCH_ASSOC);

        $matchingSecs = [];
        foreach ($pageSecs as $sec) {
            $toolStmt = $pdo->prepare("SELECT * FROM link WHERE section_id=? ORDER BY sort_order,name");
            $toolStmt->execute([(int)$sec['id']]);
            $tools = $toolStmt->fetchAll(PDO::FETCH_ASSOC);

            $hits = array_filter($tools, function($tool) use($search) {
                return stripos($tool['name'],        $search) !== false
                    || stripos($tool['description'], $search) !== false
                    || stripos($tool['url'],         $search) !== false;
            });
            if ($hits) {
                $sec['tools'] = $hits;
                $matchingSecs[] = $sec;
            }
        }
        if ($matchingSecs) {
            $searchResults[$p['title']] = $matchingSecs;
        }
    }
    // only show pages that had hits in the sidebar
    $pages = array_filter($pages, fn($p)=> isset($searchResults[$p['title']]));
}

// —— NORMAL (NO SEARCH) MODE ——
$sections = [];
if ($search === '' && $currentPage && $view !== 'tree') {
    $s = $pdo->prepare("SELECT * FROM section WHERE page_id=? ORDER BY sort_order,name");
    $s->execute([(int)$currentPage['id']]);
    $sections = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sections as &$sec) {
        $l = $pdo->prepare("SELECT * FROM link WHERE section_id=? ORDER BY sort_order,name");
        $l->execute([(int)$sec['id']]);
        $sec['tools'] = $l->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($sec);
}

// —— TREE MODE ——
$allPagesTree = [];
if ($search === '' && $view === 'tree') {
    foreach ($pages as $page) {
        $s = $pdo->prepare("SELECT * FROM section WHERE page_id=? ORDER BY sort_order,name");
        $s->execute([(int)$page['id']]);
        $pageSecs = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pageSecs as &$sec) {
            $l = $pdo->prepare("SELECT * FROM link WHERE section_id=? ORDER BY sort_order,name");
            $l->execute([(int)$sec['id']]);
            $sec['tools'] = $l->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($sec);
        $allPagesTree[] = ['title'=>$page['title'],'sections'=>$pageSecs];
    }
}

// Page title
if ($search !== '') {
    $pageTitle = "Search results for “" . htmlspecialchars($search, ENT_QUOTES) . "”";
} else {
    $pageTitle = "Link Manager";
    if ($view !== 'tree' && $currentPage) {
        $pageTitle .= " – " . htmlspecialchars($currentPage['title'], ENT_QUOTES);
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $pageTitle ?></title>
  <link rel="stylesheet" href="/css/styles.css">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    /* Sidebar separator & main‐area margin */
    #sidebar {
      width: 220px;
      min-width: 220px;
      border-right: 1px solid #6c757d;  /* gray */
      overflow-y: auto;
    }
    main {
      margin-left: 1.5rem;
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="d-flex" style="min-height: calc(100vh - 64px);">
  <!-- Left Sidebar -->
  <nav id="sidebar" class="bg-dark">
    <div class="list-group list-group-flush">
      <?php foreach ($pages as $p): ?>
        <a href="?page=<?= urlencode($p['title']) ?>&view=<?= htmlspecialchars($view,ENT_QUOTES) ?><?= $search ? '&search='.urlencode($search) : '' ?>"
           class="list-group-item list-group-item-action<?= strcasecmp($p['title'],$currentPageTitle)===0 ? ' active' : '' ?>">
          <?= htmlspecialchars($p['title'], ENT_QUOTES) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="flex-grow-1 p-3">
    <?php if ($search !== ''): ?>
      <h3 class="text-white">Search results for “<?= htmlspecialchars($search, ENT_QUOTES) ?>”</h3>
      <?php if (empty($searchResults)): ?>
        <p class="text-muted">No matches found.</p>
      <?php else: ?>
        <?php foreach ($searchResults as $pt => $secs): ?>
          <h4 class="mt-4 text-white"><?= htmlspecialchars($pt, ENT_QUOTES) ?></h4>
          <?php foreach ($secs as $sec): ?>
            <h5 class="mt-3 text-white"><?= htmlspecialchars($sec['name'], ENT_QUOTES) ?></h5>
            <?php if ($sec['description']): ?>
              <p class="text-muted"><?= htmlspecialchars($sec['description'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <ul class="mb-4">
              <?php foreach ($sec['tools'] as $tool): ?>
                <li>
                  <a href="<?= htmlspecialchars($tool['url'], ENT_QUOTES) ?>"
                     target="<?= stripos($tool['url'],'http')===0?'_blank':'_self' ?>"
                     class="panel-link">
                    <?= htmlspecialchars($tool['name'], ENT_QUOTES) ?>
                  </a>
                  <?php if ($tool['description']): ?>
                    — <small class="text-secondary"><?= htmlspecialchars($tool['description'], ENT_QUOTES) ?></small>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($view==='tree'): ?>
      <div class="tree-list">
        <?php foreach ($allPagesTree as $page): ?>
          <section class="mb-5">
            <h2><?= htmlspecialchars($page['title'], ENT_QUOTES) ?></h2>
            <?php foreach ($page['sections'] as $sec): ?>
              <div class="mb-4">
                <h5><?= htmlspecialchars($sec['name'], ENT_QUOTES) ?></h5>
                <?php if ($sec['description']): ?>
                  <p class="text-muted"><?= htmlspecialchars($sec['description'], ENT_QUOTES) ?></p>
                <?php endif; ?>
                <ul>
                  <?php foreach ($sec['tools'] as $tool): ?>
                    <li>
                      <a href="<?= htmlspecialchars($tool['url'], ENT_QUOTES) ?>"
                         target="<?= stripos($tool['url'],'http')===0?'_blank':'_self' ?>">
                        <?= htmlspecialchars($tool['name'], ENT_QUOTES) ?>
                      </a>
                      <?php if ($tool['description']): ?>
                        <small class="text-secondary">— <?= htmlspecialchars($tool['description'], ENT_QUOTES) ?></small>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </div>

    <?php elseif ($view==='minimal'): ?>
      <div class="row g-3">
        <?php foreach ($sections as $sec): if (empty($sec['tools'])) continue; ?>
          <div class="col-12">
            <h4 class="mt-4 mb-2 text-white"><?= htmlspecialchars($sec['name'], ENT_QUOTES) ?></h4>
          </div>
          <?php foreach ($sec['tools'] as $tool): ?>
            <div class="col-12 col-sm-6 col-md-4">
              <div class="card h-100 d-flex flex-row align-items-stretch"
                   style="<?= (!empty($tool['background']) ? 'background:'.htmlspecialchars($tool['background'],ENT_QUOTES).';':'' )
                          . (!empty($tool['color'])      ? 'color:'.htmlspecialchars($tool['color'],ENT_QUOTES).';':'' ) ?>">
                <?php if (!empty($tool['logo'])): ?>
                  <img src="<?= htmlspecialchars($tool['logo'], ENT_QUOTES) ?>"
                       alt="<?= htmlspecialchars($tool['name'], ENT_QUOTES) ?> logo"
                       style="max-height:80px; object-fit:contain; padding:0.5rem;">
                <?php endif; ?>
                <div class="flex-grow-1 d-flex flex-column">
                  <div class="card-body d-flex justify-content-between align-items-center py-2 px-3">
                    <h5 class="card-title mb-0" style="color:<?= htmlspecialchars($tool['color'], ENT_QUOTES) ?>">
                      <?= htmlspecialchars($tool['name'], ENT_QUOTES) ?>
                    </h5>
                    <a href="<?= htmlspecialchars($tool['url'], ENT_QUOTES) ?>"
                       class="btn btn-sm panel-link"
                       style="border-color:#fbbf24; background:rgba(0,0,0,0.5)"
                       target="<?= stripos($tool['url'],'http')===0?'_blank':'_self' ?>">
                      Go
                    </a>
                  </div>
                  <?php if (!empty($tool['description'])): ?>
                    <p class="small text-muted px-3 pb-2 mb-0" style="color:<?= htmlspecialchars($tool['color'], ENT_QUOTES) ?>">
                      <?= htmlspecialchars($tool['description'], ENT_QUOTES) ?>
                    </p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>

    <?php else /* card view */: ?>
      <?php foreach ($sections as $sec): if (empty($sec['tools'])) continue; ?>
        <h4 class="mt-4 text-white"><?= htmlspecialchars($sec['name'], ENT_QUOTES) ?></h4>
        <?php if ($sec['description']): ?>
          <p class="text-secondary mb-3"><?= htmlspecialchars($sec['description'], ENT_QUOTES) ?></p>
        <?php endif; ?>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4">
          <?php foreach ($sec['tools'] as $tool): ?>
            <div class="col">
              <div class="card h-100" style="<?= (!empty($tool['background']) ? 'background:'.htmlspecialchars($tool['background'],ENT_QUOTES).';':'' )
                                                . (!empty($tool['color'])      ? 'color:'.htmlspecialchars($tool['color'],ENT_QUOTES).';':'' ) ?>">
                <img src="<?= htmlspecialchars($tool['logo']?:'/images/empty.png', ENT_QUOTES) ?>"
                     class="card-img-top p-2"
                     style="height:60px; object-fit:contain; margin:auto;"
                     alt="Logo">
                <div class="card-body">
                  <h5 class="card-title" style="color:<?= htmlspecialchars($tool['color'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars($tool['name'], ENT_QUOTES) ?>
                  </h5>
                  <p class="card-text" style="color:<?= htmlspecialchars($tool['color'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars($tool['description'], ENT_QUOTES) ?>
                  </p>
                </div>
                <div class="card-footer bg-transparent border-0">
                  <a href="<?= htmlspecialchars($tool['url'], ENT_QUOTES) ?>"
                     class="btn w-100 panel-link"
                     style="border-color:#fbbf24; background:rgba(0,0,0,0.5)"
                     target="<?= stripos($tool['url'],'http')===0?'_blank':'_self' ?>">
                    Open
                  </a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>


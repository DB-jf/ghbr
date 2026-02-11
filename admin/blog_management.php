<?php
declare(strict_types=1);

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../config/auth.php";
require_admin();

$ADMIN_BASE = "/ghbr/admin";

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// -------------------------
// Detect blog_posts columns (schema-flexible)
// -------------------------
$cols = [];
try {
  $desc = $pdo->query("DESCRIBE blog_posts")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($desc as $row) {
    $cols[strtolower((string)$row['Field'])] = true;
  }
} catch (Throwable $e) {
  // If table missing or error, show a clear message
  http_response_code(500);
  echo "Error: Unable to read blog_posts schema. Ensure blog_posts table exists.";
  exit;
}

$has = fn(string $c): bool => isset($cols[strtolower($c)]);

// Known fields (only use if present)
$field = [
  'id' => $has('id') ? 'id' : null,
  'title' => $has('title') ? 'title' : null,
  'slug' => $has('slug') ? 'slug' : null,
  'excerpt' => $has('excerpt') ? 'excerpt' : null,
  'category' => $has('category') ? 'category' : null,
  'status' => $has('status') ? 'status' : null,
  'cover_image' => $has('cover_image') ? 'cover_image' : null,
  'views' => $has('views') ? 'views' : null,
  'published_at' => $has('published_at') ? 'published_at' : null,
  'created_at' => $has('created_at') ? 'created_at' : null,
  'updated_at' => $has('updated_at') ? 'updated_at' : null,
];

// Minimal requirement
if (!$field['id'] || !$field['title']) {
  http_response_code(500);
  echo "Error: blog_posts must have at least id + title columns.";
  exit;
}

// -------------------------
// Handle actions (Publish/Unpublish/Delete)
// -------------------------
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $token)) {
    http_response_code(403);
    echo "Invalid CSRF token.";
    exit;
  }

  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);

  if ($id <= 0) {
    $flash = ['type' => 'err', 'msg' => 'Invalid post id.'];
  } else {
    try {
      if ($action === 'publish') {
        if ($field['status']) {
          $sql = "UPDATE blog_posts SET {$field['status']}='published'";
          if ($field['published_at']) $sql .= ", {$field['published_at']}=COALESCE({$field['published_at']}, NOW())";
          if ($field['updated_at']) $sql .= ", {$field['updated_at']}=NOW()";
          $sql .= " WHERE {$field['id']}=? LIMIT 1";
          $pdo->prepare($sql)->execute([$id]);
          $flash = ['type' => 'ok', 'msg' => 'Post published.'];
        } else {
          $flash = ['type' => 'err', 'msg' => 'Your blog_posts table has no status column.'];
        }
      }

      elseif ($action === 'unpublish') {
        if ($field['status']) {
          $sql = "UPDATE blog_posts SET {$field['status']}='draft'";
          if ($field['updated_at']) $sql .= ", {$field['updated_at']}=NOW()";
          // keep published_at (optional) — some systems keep history
          $sql .= " WHERE {$field['id']}=? LIMIT 1";
          $pdo->prepare($sql)->execute([$id]);
          $flash = ['type' => 'ok', 'msg' => 'Post moved back to draft.'];
        } else {
          $flash = ['type' => 'err', 'msg' => 'Your blog_posts table has no status column.'];
        }
      }

      elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM blog_posts WHERE {$field['id']}=? LIMIT 1")->execute([$id]);
        $flash = ['type' => 'ok', 'msg' => 'Post deleted.'];
      }

      else {
        $flash = ['type' => 'err', 'msg' => 'Unknown action.'];
      }
    } catch (Throwable $e) {
      $flash = ['type' => 'err', 'msg' => 'Action failed: ' . $e->getMessage()];
    }
  }
}

// -------------------------
// Filters + Search + Pagination
// -------------------------
$allowedStatuses = ['all','draft','published'];
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, $allowedStatuses, true)) $status = 'all';

$q = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Build WHERE safely
$where = [];
$params = [];

if ($field['status'] && $status !== 'all') {
  $where[] = "{$field['status']} = ?";
  $params[] = $status;
}
if ($field['category'] && $category !== '') {
  $where[] = "{$field['category']} = ?";
  $params[] = $category;
}
if ($q !== '') {
  $like = '%' . $q . '%';
  $sub = [];
  $sub[] = "{$field['title']} LIKE ?";
  $params[] = $like;

  if ($field['excerpt']) { $sub[] = "{$field['excerpt']} LIKE ?"; $params[] = $like; }
  if ($field['category']) { $sub[] = "{$field['category']} LIKE ?"; $params[] = $like; }
  if ($field['slug']) { $sub[] = "{$field['slug']} LIKE ?"; $params[] = $like; }

  $where[] = "(" . implode(" OR ", $sub) . ")";
}

// Get categories for filter dropdown
$categories = [];
if ($field['category']) {
  $catSql = "SELECT DISTINCT {$field['category']} AS c FROM blog_posts WHERE {$field['category']} IS NOT NULL AND {$field['category']}<>'' ORDER BY c ASC";
  try {
    $categories = array_values(array_filter(array_map(
      fn($r) => (string)$r['c'],
      $pdo->query($catSql)->fetchAll(PDO::FETCH_ASSOC)
    )));
  } catch (Throwable $e) {
    $categories = [];
  }
}

// Stats
$totalPosts = (int)$pdo->query("SELECT COUNT(*) c FROM blog_posts")->fetch(PDO::FETCH_ASSOC)['c'];
$draftPosts = 0;
$publishedPosts = 0;
if ($field['status']) {
  $draftPosts = (int)$pdo->query("SELECT COUNT(*) c FROM blog_posts WHERE {$field['status']}='draft'")->fetch(PDO::FETCH_ASSOC)['c'];
  $publishedPosts = (int)$pdo->query("SELECT COUNT(*) c FROM blog_posts WHERE {$field['status']}='published'")->fetch(PDO::FETCH_ASSOC)['c'];
}

// Count results (for pagination)
$countSql = "SELECT COUNT(*) c FROM blog_posts" . ($where ? (" WHERE " . implode(" AND ", $where)) : "");
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalFiltered = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['c'];
$pages = max(1, (int)ceil($totalFiltered / $limit));

// Select list
$select = [
  "{$field['id']} AS id",
  "{$field['title']} AS title",
];
if ($field['slug']) $select[] = "{$field['slug']} AS slug";
if ($field['excerpt']) $select[] = "{$field['excerpt']} AS excerpt";
if ($field['category']) $select[] = "{$field['category']} AS category";
if ($field['status']) $select[] = "{$field['status']} AS status";
if ($field['views']) $select[] = "{$field['views']} AS views";
if ($field['cover_image']) $select[] = "{$field['cover_image']} AS cover_image";
if ($field['published_at']) $select[] = "{$field['published_at']} AS published_at";
if ($field['created_at']) $select[] = "{$field['created_at']} AS created_at";
if ($field['updated_at']) $select[] = "{$field['updated_at']} AS updated_at";

$sql = "SELECT " . implode(", ", $select) . " FROM blog_posts";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);

// Ordering: published first by published_at desc, then drafts by created_at desc.
// If no status/published_at, fallback to created_at desc.
if ($field['status'] && $field['published_at']) {
  $sql .= " ORDER BY ({$field['status']}='published') DESC, {$field['published_at']} DESC, " . ($field['created_at'] ? "{$field['created_at']} DESC" : "{$field['id']} DESC");
} elseif ($field['created_at']) {
  $sql .= " ORDER BY {$field['created_at']} DESC";
} else {
  $sql .= " ORDER BY {$field['id']} DESC";
}

$sql .= " LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query string helper (for pagination links)
function qs(array $add = []): string {
  $base = $_GET;
  foreach ($add as $k => $v) $base[$k] = $v;
  return http_build_query($base);
}

function excerpt(string $s, int $len = 120): string {
  $s = trim(preg_replace('/\s+/', ' ', $s));
  if (mb_strlen($s) <= $len) return $s;
  return mb_substr($s, 0, $len - 1) . '…';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Blog Management — GHBR Admin</title>

  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&family=Merriweather:wght@300;400;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/ghbr/assets/images/favicon.png">
  <style>
    :root{
      --navy:#0c3a59;--gold:#c99b2a;--gold-soft:#e0b54d;--text:#0a2230;
      --bg:linear-gradient(180deg,#fff,#f8fbfd 60%);
      --transition:220ms cubic-bezier(.2,.9,.25,1);
      --shadow-sm:0 8px 20px rgba(12,58,89,0.06);
      --shadow-md:0 18px 40px rgba(12,58,89,0.10);
      --border:1px solid rgba(12,58,89,0.08);
      --radius:14px;--max:1100px;--pad:24px;
    }
    *{box-sizing:border-box}
    body{
      margin:0;font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,Arial;
      color:var(--text);background:var(--bg);line-height:1.55;
      -webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;
    }
    a{color:inherit;text-decoration:none}
    .wrap{max-width:var(--max);margin:0 auto;padding:0 var(--pad)}
    .muted{color:rgba(10,34,48,0.70)}

    header.topbar{
      position:sticky;top:0;z-index:50;
      background:rgba(255,255,255,0.92);
      backdrop-filter:saturate(140%) blur(10px);
      border-bottom:1px solid rgba(12,58,89,0.08);
    }
    .topbar-row{height:76px;display:flex;align-items:center;justify-content:space-between;gap:14px}
    .brand{display:flex;align-items:center;gap:12px;min-width:220px}
    .brand img{height:44px;width:auto}
    .brand-titles{display:flex;flex-direction:column;line-height:1.1}
    .brand-title{font-family:"Merriweather",serif;font-weight:800;color:var(--navy);letter-spacing:0.6px}
    .brand-sub{font-size:12px;color:rgba(10,34,48,0.70);font-weight:650}

    .nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .nav a{
      padding:10px 12px;border-radius:999px;font-weight:850;font-size:14px;
      color:rgba(12,58,89,0.92);background:rgba(12,58,89,0.03);
      border:1px solid rgba(12,58,89,0.08);
      transition:transform var(--transition), background var(--transition), box-shadow var(--transition);
    }
    .nav a:hover{transform:translateY(-2px);background:rgba(201,155,42,0.12)}
    .nav a.primary{
      background:var(--gold-soft);color:var(--navy);
      border-color:rgba(12,58,89,0.10);
      box-shadow:0 12px 28px rgba(12,58,89,0.12);
    }

    .page{padding:22px 0 44px}
    .page-head{
      display:flex;justify-content:space-between;align-items:flex-end;
      gap:14px;flex-wrap:wrap;margin-top:16px;
    }
    h1{
      margin:0;font-family:"Merriweather",serif;color:var(--navy);
      font-size:28px;letter-spacing:-0.2px;
    }

    .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 14px;border-radius:12px;font-weight:900;
      border:1px solid rgba(12,58,89,0.10);background:#fff;
      box-shadow:0 10px 22px rgba(12,58,89,0.08);
      transition:transform var(--transition), box-shadow var(--transition), background var(--transition);
      cursor:pointer;white-space:nowrap;
    }
    .btn:hover{transform:translateY(-2px)}
    .btn.primary{
      background:var(--gold-soft);color:var(--navy);
      border-color:rgba(12,58,89,0.10);
      box-shadow:0 12px 28px rgba(12,58,89,0.12);
    }
    .btn.ghost{background:rgba(12,58,89,0.03);border-color:rgba(12,58,89,0.08)}
    .btn.danger{border-color:rgba(155,28,28,0.25);color:#9b1c1c;background:#fff}

    .grid{
      display:grid;grid-template-columns:repeat(3,1fr);
      gap:14px;margin-top:16px;
    }
    .card{
      background:#fff;border-radius:var(--radius);
      border:var(--border);box-shadow:var(--shadow-sm);
      padding:16px;position:relative;overflow:hidden;
    }
    .card:before{
      content:"";position:absolute;inset:-60px -60px auto -60px;height:160px;
      background:radial-gradient(closest-side, rgba(224,181,77,0.14), rgba(224,181,77,0) 70%);
      pointer-events:none;
    }
    .k{font-size:13px;color:rgba(10,34,48,0.66);font-weight:900;letter-spacing:0.4px;position:relative}
    .v{font-size:34px;font-weight:950;color:var(--navy);margin-top:8px;line-height:1;position:relative}
    .hint{margin-top:10px;font-size:13px;color:rgba(10,34,48,0.62);font-weight:700;position:relative}

    .notice{
      border-radius:12px;padding:12px 14px;margin-top:14px;font-weight:900;
      border:1px solid rgba(12,58,89,0.10);
      background:rgba(12,58,89,0.03);
    }
    .notice.ok{border-color:rgba(22,163,74,0.22);background:rgba(22,163,74,0.10);color:#0f5132}
    .notice.err{border-color:rgba(155,28,28,0.22);background:rgba(155,28,28,0.08);color:#9b1c1c}

    .filters{
      margin-top:14px;
      display:flex;gap:10px;flex-wrap:wrap;align-items:center;
      background:#fff;border:var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow-sm);padding:12px;
    }
    .input, .select{
      font:inherit;
      padding:12px 12px;border-radius:12px;
      border:1px solid rgba(12,58,89,0.10);
      outline:none;background:#fff;
      transition:box-shadow var(--transition), border-color var(--transition);
    }
    .input:focus, .select:focus{
      border-color:rgba(201,155,42,0.55);
      box-shadow:0 0 0 4px rgba(201,155,42,0.18)
    }
    .input{flex:1 1 260px}
    .select{min-width:180px}

    .table{
      margin-top:14px;
      background:#fff;border:var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow-sm);overflow:hidden;
    }
    .thead,.trow{
      display:grid;
      grid-template-columns: 64px 1.6fr 0.8fr 0.8fr 0.7fr 1fr 240px;
      gap:10px;
      padding:14px 14px;
      align-items:center;
    }
    .thead{
      background:rgba(12,58,89,0.03);
      border-bottom:1px solid rgba(12,58,89,0.06);
      font-weight:950;
      color:rgba(12,58,89,0.92);
      font-size:13px;
    }
    .trow{
      border-top:1px solid rgba(12,58,89,0.06);
    }
    .trow:first-child{border-top:none}

    .title{
      font-weight:950;color:rgba(12,58,89,0.95);line-height:1.25;
    }
    .sub{font-size:13px;color:rgba(10,34,48,0.68);margin-top:4px}
    .pill{
      display:inline-flex;align-items:center;gap:6px;
      padding:6px 10px;border-radius:999px;
      background:rgba(12,58,89,0.03);border:1px solid rgba(12,58,89,0.08);
      font-size:12px;font-weight:950;color:rgba(12,58,89,0.85);
      width:max-content;
    }
    .pill.published{background:rgba(22,163,74,0.10);border-color:rgba(22,163,74,0.22);color:#0f5132}
    .pill.draft{background:rgba(224,181,77,0.18);border-color:rgba(201,155,42,0.35)}

    .row-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .btn.small{padding:10px 12px;font-size:13px;font-weight:950;box-shadow:none}

    .pager{
      margin-top:14px;
      display:flex;gap:10px;align-items:center;flex-wrap:wrap;
    }
    .count{font-size:13px;color:rgba(10,34,48,0.65);font-weight:800}

    @media (max-width: 1050px){
      .thead,.trow{grid-template-columns: 64px 1fr 220px}
      .thead > div:nth-child(n+3):not(:last-child),
      .trow  > div:nth-child(n+3):not(:last-child){display:none}
      .row-actions{justify-content:flex-start}
    }
    @media (max-width: 980px){
      .grid{grid-template-columns:1fr}
      .brand-sub{display:none}
      .topbar-row{height:auto;padding:12px 0}
    }
    @media (max-width: 520px){
      .wrap{padding:0 16px}
      .nav a,.btn{width:100%;justify-content:center}
      .thead,.trow{grid-template-columns: 1fr}
      .thead{display:none}
      .row-actions{justify-content:flex-start}
    }

    :focus{outline:3px solid rgba(201,155,42,0.28);outline-offset:3px}
  </style>
</head>

<body>
<header class="topbar" role="banner" aria-label="Admin top bar">
  <div class="wrap topbar-row">
    <a class="brand" href="<?= h($ADMIN_BASE) ?>/index.php" aria-label="Admin dashboard home">
      <img src="/ghbr/assets/images/logo-ghbr%201.png" alt="GHBR logo">
      <div class="brand-titles">
        <div class="brand-title">GHBR Admin</div>
        <div class="brand-sub">Blog management</div>
      </div>
    </a>

    <nav class="nav" role="navigation" aria-label="Admin navigation">
      <a href="<?= h($ADMIN_BASE) ?>/message_view.php">Inbox</a>
      <a href="<?= h($ADMIN_BASE) ?>/blog_management.php" style="background:rgba(201,155,42,0.12)">Blog</a>
      <a class="primary" href="<?= h($ADMIN_BASE) ?>/logout.php">Logout</a>
    </nav>
  </div>
</header>

<div class="wrap page">
  <div class="page-head">
    <div>
      <h1>Blog Management</h1>
      <div class="muted" style="margin-top:6px">Create, edit, publish, and manage your blog posts.</div>
    </div>

    <div class="actions" aria-label="Quick actions">
      <a class="btn ghost" href="<?= h($ADMIN_BASE) ?>/index.php">← Dashboard</a>
      <a class="btn primary" href="<?= h($ADMIN_BASE) ?>/blog_create.php">+ New Post</a>
    </div>
  </div>

  <div class="grid" aria-label="Blog statistics">
    <div class="card">
      <div class="k">Total Posts</div>
      <div class="v"><?= (int)$totalPosts ?></div>
      <div class="hint">All posts in your database.</div>
    </div>
    <div class="card">
      <div class="k">Drafts</div>
      <div class="v"><?= (int)$draftPosts ?></div>
      <div class="hint">Not visible on the public site.</div>
    </div>
    <div class="card">
      <div class="k">Published</div>
      <div class="v"><?= (int)$publishedPosts ?></div>
      <div class="hint">Visible on the public blog.</div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="notice <?= h($flash['type']) ?>" role="status">
      <?= h($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <form class="filters" method="GET" action="blog_management.php" aria-label="Blog filters">
    <input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Search title, excerpt, category, slug…" />

    <select class="select" name="status" aria-label="Status">
      <option value="all" <?= $status==='all'?'selected':'' ?>>All status</option>
      <option value="draft" <?= $status==='draft'?'selected':'' ?>>Draft</option>
      <option value="published" <?= $status==='published'?'selected':'' ?>>Published</option>
    </select>

    <?php if ($field['category']): ?>
      <select class="select" name="category" aria-label="Category">
        <option value="" <?= $category===''?'selected':'' ?>>All categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= h($c) ?>" <?= $category===$c?'selected':'' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <button class="btn primary" type="submit">Apply</button>
    <a class="btn" href="blog_management.php">Clear</a>
  </form>

  <div class="table" role="table" aria-label="Posts list">
    <div class="thead" role="row">
      <div>#</div>
      <div>Post</div>
      <div>Status</div>
      <div>Category</div>
      <div>Views</div>
      <div>Date</div>
      <div style="text-align:right">Actions</div>
    </div>

    <?php if (!$rows): ?>
      <div style="padding:18px;color:rgba(10,34,48,0.75)">
        No posts found for this view.
      </div>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $id = (int)$r['id'];
          $title = (string)($r['title'] ?? '');
          $slug = (string)($r['slug'] ?? '');
          $cat = (string)($r['category'] ?? '—');
          $st = (string)($r['status'] ?? 'draft');
          $views = (int)($r['views'] ?? 0);

          $date = '';
          if (!empty($r['published_at'])) $date = (string)$r['published_at'];
          elseif (!empty($r['created_at'])) $date = (string)$r['created_at'];
          else $date = '—';

          $pill = ($st === 'published') ? 'published' : 'draft';
          $excerptText = '';
          if (!empty($r['excerpt'])) $excerptText = excerpt((string)$r['excerpt'], 120);

          // view link (optional)
          $viewUrl = '';
          if ($slug !== '') {
            $viewUrl = "/ghbr/api/post.php?slug=" . rawurlencode($slug); // adjust if your public post route differs
          }
        ?>
        <div class="trow" role="row">
          <div class="muted" style="font-weight:900"><?= $id ?></div>

          <div>
            <div class="title"><?= h($title) ?></div>
            <?php if ($excerptText !== ''): ?>
              <div class="sub"><?= h($excerptText) ?></div>
            <?php else: ?>
              <div class="sub muted">No excerpt</div>
            <?php endif; ?>
          </div>

          <div>
            <span class="pill <?= h($pill) ?>"><?= h(ucfirst($st)) ?></span>
          </div>

          <div class="muted" style="font-weight:800"><?= h($cat) ?></div>
          <div class="muted" style="font-weight:800"><?= (int)$views ?></div>
          <div class="muted" style="font-weight:800"><?= h($date) ?></div>

          <div class="row-actions">
            <a class="btn small" href="<?= h($ADMIN_BASE) ?>/blog_edit.php?id=<?= $id ?>">Edit</a>

            <?php if ($field['status']): ?>
              <?php if ($st === 'published'): ?>
                <form method="POST" action="blog_management.php?<?= h(qs()) ?>" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="unpublish">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="btn small ghost" type="submit">Unpublish</button>
                </form>
              <?php else: ?>
                <form method="POST" action="blog_management.php?<?= h(qs()) ?>" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="publish">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="btn small primary" type="submit">Publish</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($viewUrl !== ''): ?>
              <a class="btn small ghost" href="<?= h($viewUrl) ?>" target="_blank" rel="noopener">View</a>
            <?php endif; ?>

            <form method="POST" action="blog_management.php?<?= h(qs()) ?>" style="display:inline" onsubmit="return confirm('Delete this post permanently?');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn small danger" type="submit">Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="pager" aria-label="Pagination">
    <div class="count">
      Showing <?= count($rows) ?> of <?= (int)$totalFiltered ?> results • Page <?= (int)$page ?> of <?= (int)$pages ?>
    </div>

    <div class="actions" style="margin-left:auto">
      <?php if ($page > 1): ?>
        <a class="btn" href="blog_management.php?<?= h(qs(['page' => $page - 1])) ?>">← Prev</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a class="btn" href="blog_management.php?<?= h(qs(['page' => $page + 1])) ?>">Next →</a>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>

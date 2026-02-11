<?php
declare(strict_types=1);

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../config/auth.php";
require_admin();

/** Helpers */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function excerpt(string $s, int $len = 110): string {
  $s = trim(preg_replace('/\s+/', ' ', $s));
  if (mb_strlen($s) <= $len) return $s;
  return mb_substr($s, 0, $len - 1) . '…';
}

/** Filters */
$allowedStatus = ['all','draft','published'];
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, $allowedStatus, true)) $status = 'all';

$q = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'newest'); // newest|oldest|title

$sortSql = "created_at DESC";
if ($sort === 'oldest') $sortSql = "created_at ASC";
if ($sort === 'title') $sortSql = "title ASC";

$qLike = '%' . $q . '%';

/** Bulk actions / row actions */
$flash = null;
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);

  // Basic CSRF token (optional but recommended). If you already have CSRF, swap this.
  // For now, we keep it simple and rely on require_admin() + POST.
  if ($id <= 0) {
    $flash = "Invalid post id.";
    $flashType = 'err';
  } else {
    if ($action === 'delete') {
      $pdo->prepare("DELETE FROM blog_posts WHERE id=? LIMIT 1")->execute([$id]);
      $flash = "Post deleted.";
    } elseif ($action === 'publish') {
      $pdo->prepare("UPDATE blog_posts SET status='published', published_at=COALESCE(published_at, NOW()) WHERE id=? LIMIT 1")
          ->execute([$id]);
      $flash = "Post published.";
    } elseif ($action === 'unpublish') {
      $pdo->prepare("UPDATE blog_posts SET status='draft' WHERE id=? LIMIT 1")->execute([$id]);
      $flash = "Post moved to draft.";
    } else {
      $flash = "Unknown action.";
      $flashType = 'err';
    }
  }

  // Redirect to avoid form re-submit
  $redir = "blog_list.php?status=" . urlencode($status)
         . "&sort=" . urlencode($sort)
         . ($q !== '' ? "&q=" . urlencode($q) : "");
  header("Location: $redir");
  exit;
}

/** Counts */
$counts = ['all'=>0,'draft'=>0,'published'=>0];
$counts['all'] = (int)$pdo->query("SELECT COUNT(*) c FROM blog_posts")->fetch()['c'];
foreach (['draft','published'] as $s) {
  $st = $pdo->prepare("SELECT COUNT(*) c FROM blog_posts WHERE status=?");
  $st->execute([$s]);
  $counts[$s] = (int)$st->fetch()['c'];
}

/** Query posts */
$where = [];
$params = [];

if ($status !== 'all') {
  $where[] = "status = ?";
  $params[] = $status;
}
if ($q !== '') {
  $where[] = "(title LIKE ? OR slug LIKE ? OR category LIKE ? OR author_name LIKE ? OR tags LIKE ?)";
  array_push($params, $qLike, $qLike, $qLike, $qLike, $qLike);
}

$sql = "SELECT id, title, slug, excerpt, cover_image, category, tags, author_name, status, published_at, created_at, updated_at
        FROM blog_posts";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);

/**
 * Optional: put drafts on top always:
 * ORDER BY (status='draft') DESC, created_at DESC
 * But we will respect chosen sort while still keeping drafts slightly prominent.
 */
if ($sort === 'title') {
  $sql .= " ORDER BY title ASC";
} else {
  // Keep drafts near the top, then sort by date
  $sql .= " ORDER BY (status='draft') DESC, $sortSql";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Blog — GHBR Admin</title>

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
    body{margin:0;font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,Arial;color:var(--text);background:var(--bg);line-height:1.55}
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
      transition:transform var(--transition), background var(--transition);
    }
    .nav a:hover{transform:translateY(-2px);background:rgba(201,155,42,0.12)}
    .nav a.primary{background:var(--gold-soft);color:var(--navy);border-color:rgba(12,58,89,0.10)}

    .page{padding:22px 0 44px}
    .page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-top:16px}
    h1{margin:0;font-family:"Merriweather",serif;color:var(--navy);font-size:28px}
    .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 14px;border-radius:12px;font-weight:900;
      border:1px solid rgba(12,58,89,0.10);background:#fff;
      box-shadow:0 10px 22px rgba(12,58,89,0.08);
      transition:transform var(--transition);
      cursor:pointer;
    }
    .btn:hover{transform:translateY(-2px)}
    .btn.primary{background:var(--gold-soft);color:var(--navy)}
    .btn.ghost{background:rgba(12,58,89,0.03);box-shadow:none}
    .btn.danger{border-color:rgba(155,28,28,0.25);color:#9b1c1c}

    .tabs{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
    .tab{
      display:inline-flex;align-items:center;gap:8px;
      padding:10px 12px;border-radius:999px;font-weight:900;font-size:13px;
      background:rgba(12,58,89,0.03);border:1px solid rgba(12,58,89,0.08);
      transition:transform var(--transition), background var(--transition);
    }
    .tab:hover{transform:translateY(-2px);background:rgba(201,155,42,0.12)}
    .tab.active{background:rgba(224,181,77,0.22);border-color:rgba(201,155,42,0.35)}
    .badge{
      display:inline-flex;align-items:center;justify-content:center;
      min-width:22px;height:22px;padding:0 8px;border-radius:999px;
      background:rgba(12,58,89,0.06);border:1px solid rgba(12,58,89,0.10);
      font-weight:950;font-size:12px;color:rgba(12,58,89,0.92)
    }

    .toolbar{
      margin-top:12px;
      display:flex;gap:10px;flex-wrap:wrap;align-items:center;
      background:#fff;border:var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow-sm);padding:12px;
    }
    .toolbar input,.toolbar select{
      font:inherit;padding:12px;border-radius:12px;
      border:1px solid rgba(12,58,89,0.10);outline:none;background:#fff;
    }
    .toolbar input{flex:1 1 280px}
    .toolbar input:focus,.toolbar select:focus{border-color:rgba(201,155,42,0.55);box-shadow:0 0 0 4px rgba(201,155,42,0.18)}

    .notice{
      border-radius:12px;padding:12px 14px;margin-top:12px;font-weight:800;
      border:1px solid rgba(12,58,89,0.10);
      background:rgba(12,58,89,0.03);
    }
    .notice.ok{border-color:rgba(22,163,74,0.22);background:rgba(22,163,74,0.10);color:#0f5132}
    .notice.err{border-color:rgba(155,28,28,0.22);background:rgba(155,28,28,0.08);color:#9b1c1c}

    .list{
      margin-top:14px;
      background:#fff;border:var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow-sm);overflow:hidden;
    }
    .row{
      display:grid;
      grid-template-columns: 70px 1.6fr 0.8fr 0.9fr 0.7fr 230px;
      gap:12px;
      padding:14px;
      border-top:1px solid rgba(12,58,89,0.06);
      align-items:center;
    }
    .row:first-child{border-top:none}
    .thumb{
      width:70px;height:52px;border-radius:12px;overflow:hidden;
      border:1px solid rgba(12,58,89,0.08);
      background:#eef3f6;
    }
    .thumb img{width:100%;height:100%;object-fit:cover}
    .title{font-weight:950;color:rgba(12,58,89,0.95);line-height:1.2}
    .sub{font-size:13px;color:rgba(10,34,48,0.70);margin-top:6px}
    .pill{
      display:inline-flex;align-items:center;justify-content:center;
      padding:6px 10px;border-radius:999px;
      border:1px solid rgba(12,58,89,0.08);
      background:rgba(12,58,89,0.03);
      font-size:12px;font-weight:950;color:rgba(12,58,89,0.85)
    }
    .pill.draft{background:rgba(224,181,77,0.18);border-color:rgba(201,155,42,0.35)}
    .pill.published{background:rgba(12,58,89,0.06)}
    .row-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .btn.small{padding:10px 12px;border-radius:12px;font-size:13px;font-weight:900;box-shadow:none}

    .empty{padding:18px;color:rgba(10,34,48,0.75)}

    @media (max-width: 980px){
      .brand-sub{display:none}
      .row{grid-template-columns: 70px 1fr; gap:10px}
      .row > div:nth-child(n+3){display:none}
      .row-actions{justify-content:flex-start}
    }
    @media (max-width: 520px){
      .wrap{padding:0 16px}
      .nav a{width:100%;justify-content:center}
      .btn{width:100%}
    }
    :focus{outline:3px solid rgba(201,155,42,0.28);outline-offset:3px}
  </style>
</head>

<body>
<header class="topbar" role="banner">
  <div class="wrap topbar-row">
    <a class="brand" href="index.php" aria-label="Admin dashboard home">
      <img src="../assets/images/logo-ghbr%201.png" alt="GHBR logo">
      <div class="brand-titles">
        <div class="brand-title">GHBR Admin</div>
        <div class="brand-sub">Blog management</div>
      </div>
    </a>

    <nav class="nav" role="navigation" aria-label="Admin navigation">
      <a href="index.php">Dashboard</a>
      <a href="message_view.php">Messages</a>
      <a class="primary" href="logout.php">Logout</a>
    </nav>
  </div>
</header>

<div class="wrap page">
  <div class="page-head">
    <div>
      <h1>Blog Posts</h1>
      <div class="muted" style="margin-top:6px">Create, publish, edit, or delete posts.</div>
    </div>

    <div class="actions">
      <a class="btn primary" href="blog_create.php">+ New Post</a>
    </div>
  </div>

  <div class="tabs" aria-label="Blog filters">
    <?php
      $mk = function(string $key, string $label) use ($status, $q, $sort, $counts) {
        $active = ($status === $key) ? 'active' : '';
        $url = "blog_list.php?status=" . urlencode($key) . "&sort=" . urlencode($sort);
        if ($q !== '') $url .= "&q=" . urlencode($q);
        return '<a class="tab '.$active.'" href="'.h($url).'">'.$label.' <span class="badge">'.(int)$counts[$key].'</span></a>';
      };
      echo $mk('all','All');
      echo $mk('draft','Drafts');
      echo $mk('published','Published');
    ?>
  </div>

  <form class="toolbar" method="GET" action="blog_list.php" aria-label="Search blog posts">
    <input type="hidden" name="status" value="<?= h($status) ?>">

    <input
      type="search"
      name="q"
      placeholder="Search by title, slug, category, author, tags…"
      value="<?= h($q) ?>"
      aria-label="Search blog posts">

    <select name="sort" aria-label="Sort">
      <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest</option>
      <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Oldest</option>
      <option value="title"  <?= $sort==='title'?'selected':''  ?>>Title (A–Z)</option>
    </select>

    <button class="btn primary" type="submit">Search</button>

    <?php if ($q !== ''): ?>
      <a class="btn" href="blog_list.php?status=<?= h($status) ?>&sort=<?= h($sort) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <div class="list" role="list" aria-label="Posts list">
    <?php if (!$rows): ?>
      <div class="empty">No posts found for this view.</div>
    <?php else: ?>
      <?php foreach ($rows as $p): ?>
        <?php
          $pStatus = (string)($p['status'] ?? 'draft');
          $pill = in_array($pStatus, ['draft','published'], true) ? $pStatus : 'draft';
          $img = (string)($p['cover_image'] ?? '');
          $imgFallback = "../assets/images/team.png";
          $thumbSrc = $img !== '' ? $img : $imgFallback;

          // preview url (front-end post page)
          // Your blog uses post.php?slug=... (from earlier)
          $previewUrl = "../post.php?slug=" . urlencode((string)$p['slug']);
        ?>

        <div class="row" role="listitem">
          <div class="thumb">
            <img src="<?= h($thumbSrc) ?>" alt="">
          </div>

          <div>
            <div class="title"><?= h((string)$p['title']) ?></div>
            <div class="sub">
              <?= h(excerpt((string)$p['excerpt'])) ?>
            </div>
            <div class="sub" style="margin-top:8px">
              <span class="pill <?= h($pill) ?>"><?= h(ucfirst($pill)) ?></span>
              <?php if (!empty($p['category'])): ?>
                <span class="pill" style="margin-left:8px">🏷️ <?= h((string)$p['category']) ?></span>
              <?php endif; ?>
              <?php if (!empty($p['author_name'])): ?>
                <span class="pill" style="margin-left:8px">✍️ <?= h((string)$p['author_name']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="sub">
            <strong class="muted">Slug</strong><br>
            <?= h((string)$p['slug']) ?>
          </div>

          <div class="sub">
            <strong class="muted">Created</strong><br>
            <?= h((string)$p['created_at']) ?>
          </div>

          <div class="sub">
            <strong class="muted">Published</strong><br>
            <?= $p['published_at'] ? h((string)$p['published_at']) : '—' ?>
          </div>

          <div class="row-actions">
            <a class="btn small ghost" href="blog_edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
            <a class="btn small" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Preview</a>

            <?php if ($pill === 'draft'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="action" value="publish">
                <button class="btn small primary" type="submit">Publish</button>
              </form>
            <?php else: ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="action" value="unpublish">
                <button class="btn small ghost" type="submit">Unpublish</button>
              </form>
            <?php endif; ?>

            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this post? This cannot be undone.');">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn small danger" type="submit">Delete</button>
            </form>
          </div>
        </div>

      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

</body>
</html>

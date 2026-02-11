<?php
declare(strict_types=1);

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../config/auth.php";
require_admin();

/**
 * Admin base path (important since project lives under /ghbr)
 * If you ever rename the folder, update here once.
 */
$ADMIN_BASE = "/ghbr/admin";

// Helpers
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function excerpt(string $s, int $len = 120): string {
  $s = trim((string)preg_replace('/\s+/', ' ', $s));
  if ($s === '') return '';
  if (mb_strlen($s) <= $len) return $s;
  return mb_substr($s, 0, $len - 1) . '…';
}
function build_url(string $path, array $query = []): string {
  $qs = $query ? ('?' . http_build_query($query)) : '';
  return $path . $qs;
}

// Filters
$allowedStatuses = ['all','unread','read','replied','archived'];
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, $allowedStatuses, true)) $status = 'all';

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

// Counts (for badges)
$counts = [
  'all' => (int)$pdo->query("SELECT COUNT(*) c FROM contact_messages")->fetch()['c'],
  'unread' => 0,
  'read' => 0,
  'replied' => 0,
  'archived' => 0,
];

foreach (['unread','read','replied','archived'] as $s) {
  $stmtC = $pdo->prepare("SELECT COUNT(*) c FROM contact_messages WHERE status=?");
  $stmtC->execute([$s]);
  $counts[$s] = (int)$stmtC->fetch()['c'];
}

// Build list query
$where = [];
$params = [];

if ($status !== 'all') {
  $where[] = "status = ?";
  $params[] = $status;
}

if ($q !== '') {
  $where[] = "(email LIKE ? OR full_name LIKE ? OR subject LIKE ?)";
  $params[] = $qLike;
  $params[] = $qLike;
  $params[] = $qLike;
}

$sql = "SELECT id, full_name, email, phone, subject, message, status, created_at, read_at
        FROM contact_messages";

if ($where) {
  $sql .= " WHERE " . implode(" AND ", $where);
}

// New messages first, then newest first
$sql .= " ORDER BY (status='unread') DESC, created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Make tabs
function tab_link(string $key, string $label, string $activeStatus, string $q, array $counts): string {
  $active = ($activeStatus === $key) ? 'active' : '';
  $url = build_url("message_view.php", ['status' => $key] + ($q !== '' ? ['q' => $q] : []));
  return '<a class="tab '.$active.'" href="'.h($url).'">'.$label.' <span class="badge">'.(int)$counts[$key].'</span></a>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Inbox — GHBR Admin</title>

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
      transition:transform var(--transition), background var(--transition);
    }
    .nav a:hover{transform:translateY(-2px);background:rgba(201,155,42,0.12)}
    .nav a.primary{background:var(--gold-soft);color:var(--navy);border-color:rgba(12,58,89,0.10)}

    .page{padding:22px 0 44px}
    .page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-top:16px}
    h1{margin:0;font-family:"Merriweather",serif;color:var(--navy);font-size:28px}
    .tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

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

    .searchbar{
      margin-top:12px;
      display:flex;gap:10px;flex-wrap:wrap;align-items:center;
      background:#fff;border:var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow-sm);padding:12px;
    }
    .searchbar input{
      flex:1 1 280px;
      font:inherit;
      padding:12px 12px;border-radius:12px;
      border:1px solid rgba(12,58,89,0.10);
      outline:none;
    }
    .searchbar input:focus{border-color:rgba(201,155,42,0.55);box-shadow:0 0 0 4px rgba(201,155,42,0.18)}

    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 14px;border-radius:12px;font-weight:900;
      border:1px solid rgba(12,58,89,0.10);background:#fff;
      box-shadow:0 10px 22px rgba(12,58,89,0.08);
      transition:transform var(--transition);
      cursor:pointer;
      white-space:nowrap;
    }
    .btn:hover{transform:translateY(-2px)}
    .btn.primary{background:var(--gold-soft);color:var(--navy);border-color:rgba(12,58,89,0.10)}
    .btn.ghost{background:rgba(12,58,89,0.03);border-color:rgba(12,58,89,0.08)}
    .btn.small{padding:10px 12px;border-radius:12px;font-size:13px;font-weight:900;box-shadow:none}

    .list{
      margin-top:14px;
      background:#fff;border:var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow-sm);overflow:hidden;
    }

    .list-head{
      display:grid;
      grid-template-columns: 14px 1.3fr 1.2fr 1.1fr 160px 220px;
      gap:10px;
      padding:12px 14px;
      background:rgba(12,58,89,0.02);
      border-bottom:1px solid rgba(12,58,89,0.06);
      font-size:12px;
      font-weight:950;
      color:rgba(12,58,89,0.72);
      letter-spacing:0.4px;
      text-transform:uppercase;
    }

    .row{
      display:grid;
      grid-template-columns: 14px 1.3fr 1.2fr 1.1fr 160px 220px;
      gap:10px;
      padding:14px 14px;
      border-top:1px solid rgba(12,58,89,0.06);
      align-items:center;
    }
    .row:first-child{border-top:none}

    .dot{width:10px;height:10px;border-radius:999px;background:rgba(12,58,89,0.12)}
    .dot.unread{background:rgba(201,155,42,0.9)}

    .subject{font-weight:900;color:rgba(12,58,89,0.95)}
    .sub{font-size:13px;color:rgba(10,34,48,0.70)}

    .pill{
      display:inline-flex;align-items:center;gap:6px;
      padding:6px 10px;border-radius:999px;
      background:rgba(12,58,89,0.03);border:1px solid rgba(12,58,89,0.08);
      font-size:12px;font-weight:900;color:rgba(12,58,89,0.85)
    }
    .pill.unread{background:rgba(224,181,77,0.18);border-color:rgba(201,155,42,0.35)}
    .pill.read{background:rgba(12,58,89,0.03)}
    .pill.replied{background:rgba(12,58,89,0.06)}
    .pill.archived{background:rgba(10,34,48,0.06)}

    .row-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .empty{padding:18px;color:rgba(10,34,48,0.75)}

    @media (max-width: 980px){
      .brand-sub{display:none}
      .list-head{display:none}
      .row{grid-template-columns: 14px 1fr; gap:8px}
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
    <a class="brand" href="<?= h($ADMIN_BASE) ?>/index.php" aria-label="Admin dashboard home">
      <img src="<?= h($ADMIN_BASE) ?>/../assets/images/logo-ghbr%201.png" alt="GHBR logo">
      <div class="brand-titles">
        <div class="brand-title">GHBR Admin</div>
        <div class="brand-sub">Inbox</div>
      </div>
    </a>

    <nav class="nav" role="navigation" aria-label="Admin navigation">
      <a href="<?= h($ADMIN_BASE) ?>/blog_list.php">Blog</a>
      <a class="primary" href="<?= h($ADMIN_BASE) ?>/logout.php">Logout</a>
    </nav>
  </div>
</header>

<div class="wrap page">
  <div class="page-head">
    <div>
      <h1>Inbox</h1>
      <div class="muted" style="margin-top:6px">New messages are always shown first.</div>
    </div>
    <div class="tools">
      <a class="btn" href="<?= h($ADMIN_BASE) ?>/index.php">← Dashboard</a>
    </div>
  </div>

  <div class="tabs" aria-label="Inbox filters">
    <?= tab_link('all','All', $status, $q, $counts) ?>
    <?= tab_link('unread','Unread', $status, $q, $counts) ?>
    <?= tab_link('read','Read', $status, $q, $counts) ?>
    <?= tab_link('replied','Replied', $status, $q, $counts) ?>
    <?= tab_link('archived','Archived', $status, $q, $counts) ?>
  </div>

  <form class="searchbar" method="GET" action="message_view.php" aria-label="Search inbox">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <input
      type="search"
      name="q"
      placeholder="Search by email, name, or subject…"
      value="<?= h($q) ?>"
      aria-label="Search by email, name, or subject">
    <button class="btn primary" type="submit">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn" href="<?= h(build_url('message_view.php', ['status' => $status])) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <div class="list" role="list" aria-label="Message list">
    <?php if (!$rows): ?>
      <div class="empty">No messages found for this view.</div>
    <?php else: ?>
      <div class="list-head" aria-hidden="true">
        <div></div>
        <div>Message</div>
        <div>Email</div>
        <div>Phone</div>
        <div>Received</div>
        <div style="text-align:right">Actions</div>
      </div>

      <?php foreach ($rows as $m): ?>
        <?php
          $mStatus = (string)($m['status'] ?? 'read');
          $pill = in_array($mStatus, ['unread','read','replied','archived'], true) ? $mStatus : 'read';

          $to = (string)($m['email'] ?? '');
          $subj = "Re: " . (string)($m['subject'] ?? '');

          $quoted =
            "\n\n---\n"
            . "From: " . (string)($m['full_name'] ?? '') . " <" . (string)($m['email'] ?? '') . ">\n"
            . "Phone: " . (string)($m['phone'] ?? '') . "\n"
            . "Date: " . (string)($m['created_at'] ?? '') . "\n"
            . "Subject: " . (string)($m['subject'] ?? '') . "\n\n"
            . (string)($m['message'] ?? '');

          $mailto = "mailto:" . rawurlencode($to)
            . "?subject=" . rawurlencode($subj)
            . "&body=" . rawurlencode($quoted);

          // IMPORTANT: absolute path to the open page to avoid routing issues
          $openUrl = $ADMIN_BASE . "/message_open.php?id=" . (int)$m['id'];
        ?>

        <div class="row" role="listitem">
          <div><span class="dot <?= $pill === 'unread' ? 'unread' : '' ?>" aria-hidden="true"></span></div>

          <div>
            <div class="subject"><?= h((string)$m['subject']) ?></div>
            <div class="sub">
              <?= h((string)$m['full_name']) ?> • <?= h((string)$m['email']) ?>
              <span class="pill <?= h($pill) ?>" style="margin-left:8px;vertical-align:middle"><?= h(ucfirst($pill)) ?></span>
            </div>
            <div class="sub" style="margin-top:4px"><?= h(excerpt((string)$m['message'], 130)) ?></div>
          </div>

          <div class="sub"><?= h((string)$m['email']) ?></div>
          <div class="sub"><?= h((string)($m['phone'] ?: '—')) ?></div>
          <div class="sub"><?= h((string)$m['created_at']) ?></div>

          <div class="row-actions">
            <a class="btn small ghost" href="<?= h($openUrl) ?>">Open</a>
            <a class="btn small" href="<?= h($mailto) ?>">Reply</a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

</body>
</html>

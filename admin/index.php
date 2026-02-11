<?php
declare(strict_types=1);
require __DIR__ . "/../config/db.php";
require __DIR__ . "/../config/auth.php";
require_admin();

$unread = (int)$pdo->query("SELECT COUNT(*) c FROM contact_messages WHERE status='unread'")->fetch()['c'];
$drafts = (int)$pdo->query("SELECT COUNT(*) c FROM blog_posts WHERE status='draft'")->fetch()['c'];
$published = (int)$pdo->query("SELECT COUNT(*) c FROM blog_posts WHERE status='published'")->fetch()['c'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dashboard — GHBR</title>

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
    html,body{height:100%}
    body{
      margin:0;
      font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,Arial;
      color:var(--text);
      background:var(--bg);
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
      line-height:1.55;
    }
    a{color:inherit;text-decoration:none}
    .wrap{max-width:var(--max);margin:0 auto;padding:0 var(--pad)}
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

    header.topbar{
      position:sticky;top:0;z-index:50;
      background:rgba(255,255,255,0.92);
      backdrop-filter:saturate(140%) blur(10px);
      border-bottom:1px solid rgba(12,58,89,0.08);
    }
    .topbar-row{
      height:76px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
    }
    .brand{display:flex;align-items:center;gap:12px;min-width:220px}
    .brand img{height:44px;width:auto}
    .brand-titles{display:flex;flex-direction:column;line-height:1.1}
    .brand-title{font-family:"Merriweather",serif;font-weight:800;color:var(--navy);letter-spacing:0.6px}
    .brand-sub{font-size:12px;color:rgba(10,34,48,0.70);font-weight:650}

    .nav{
      display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;
    }
    .nav a{
      padding:10px 12px;border-radius:999px;font-weight:850;font-size:14px;
      color:rgba(12,58,89,0.92);background:rgba(12,58,89,0.03);
      border:1px solid rgba(12,58,89,0.08);
      transition:transform var(--transition), background var(--transition), box-shadow var(--transition);
    }
    .nav a:hover{transform:translateY(-2px);background:rgba(201,155,42,0.12)}
    .nav a.primary{
      background:var(--gold-soft);
      color:var(--navy);
      border-color:rgba(12,58,89,0.10);
      box-shadow:0 12px 28px rgba(12,58,89,0.12);
    }
    .nav a.primary:hover{box-shadow:0 18px 40px rgba(12,58,89,0.16)}

    .page{padding:26px 0 40px}
    .page-head{
      display:flex;align-items:flex-end;justify-content:space-between;
      gap:14px;flex-wrap:wrap;margin-top:18px;
    }
    h1{margin:0;font-family:"Merriweather",serif;color:var(--navy);font-size:28px;letter-spacing:-0.2px}
    .welcome{color:rgba(10,34,48,0.70);font-weight:700;margin-top:6px}

    .grid{
      display:grid;grid-template-columns:repeat(3, 1fr);
      gap:14px;margin-top:16px;
    }
    .card{
      background:#fff;border-radius:var(--radius);border:var(--border);
      box-shadow:var(--shadow-sm);padding:16px;
      transition:transform var(--transition), box-shadow var(--transition);
      position:relative;overflow:hidden;
    }
    .card:hover{transform:translateY(-6px);box-shadow:var(--shadow-md)}
    .card:before{
      content:"";position:absolute;inset:-60px -60px auto -60px;height:160px;
      background:radial-gradient(closest-side, rgba(224,181,77,0.14), rgba(224,181,77,0) 70%);
      pointer-events:none;
    }
    .k{font-size:13px;color:rgba(10,34,48,0.66);font-weight:900;letter-spacing:0.4px;position:relative}
    .v{font-size:38px;font-weight:950;color:var(--navy);margin-top:8px;line-height:1;position:relative}
    .hint{margin-top:10px;font-size:13px;color:rgba(10,34,48,0.62);font-weight:700;position:relative}

    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 14px;border-radius:12px;font-weight:900;
      border:1px solid rgba(12,58,89,0.10);background:#fff;
      box-shadow:0 10px 22px rgba(12,58,89,0.08);
      transition:transform var(--transition), box-shadow var(--transition), background var(--transition);
    }
    .btn:hover{transform:translateY(-2px)}
    .btn.primary{
      background:var(--gold-soft);color:var(--navy);
      border-color:rgba(12,58,89,0.10);
      box-shadow:0 12px 28px rgba(12,58,89,0.12);
    }
    .badge{
      display:inline-flex;align-items:center;justify-content:center;
      min-width:22px;height:22px;padding:0 8px;border-radius:999px;
      background:rgba(201,155,42,0.20);border:1px solid rgba(201,155,42,0.35);
      color:rgba(12,58,89,0.92);font-weight:950;font-size:12px;margin-left:8px;
    }

    @media (max-width: 980px){
      .grid{grid-template-columns:1fr}
      .brand-sub{display:none}
      .topbar-row{height:auto;padding:12px 0}
    }
    @media (max-width: 520px){
      .wrap{padding:0 16px}
      .nav a{width:100%;justify-content:center}
      .actions .btn{width:100%}
    }
    :focus{outline:3px solid rgba(201,155,42,0.28);outline-offset:3px}
  </style>
</head>

<body>
<header class="topbar" role="banner" aria-label="Admin top bar">
  <div class="wrap topbar-row">
    <a class="brand" href="/ghbr/admin/index.php" aria-label="Admin dashboard home">
      <img src="/ghbr/assets/images/logo-ghbr 1.png" alt="GHBR logo" />
      <div class="brand-titles">
        <div class="brand-title">GHBR Admin</div>
        <div class="brand-sub">Dashboard &amp; content management</div>
      </div>
    </a>

    <nav class="nav" role="navigation" aria-label="Admin navigation">
      <a href="/ghbr/admin/blog_management.php">Blog</a>
      <a class="primary" href="/ghbr/admin/logout.php">Logout</a>
    </nav>
  </div>
</header>

<div class="wrap page">
  <div class="page-head">
    <div>
      <h1>Admin Dashboard</h1>
      <div class="welcome">
        Welcome, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?>
      </div>
    </div>

    <div class="actions" aria-label="Quick actions">
      <a class="btn primary" href="/ghbr/admin/blog_create.php">+ New Post</a>

      <!-- View Messages is now the single entry point -->
      <a class="btn" href="/ghbr/admin/message_view.php">
        View Messages
        <?php if ($unread > 0): ?>
          <span class="badge" title="Unread messages"><?= $unread ?></span>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <div class="grid" aria-label="Dashboard statistics">
    <div class="card">
      <div class="k">Unread Messages</div>
      <div class="v"><?= $unread ?></div>
      <div class="hint">New contact form submissions to review.</div>
    </div>

    <div class="card">
      <div class="k">Draft Posts</div>
      <div class="v"><?= $drafts ?></div>
      <div class="hint">Posts still being prepared for publishing.</div>
    </div>

    <div class="card">
      <div class="k">Published Posts</div>
      <div class="v"><?= $published ?></div>
      <div class="hint">Live blog posts currently visible on the site.</div>
    </div>
  </div>
</div>

</body>
</html>

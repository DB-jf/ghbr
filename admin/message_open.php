<?php
declare(strict_types=1);

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../config/auth.php";
require_admin();

$ADMIN_BASE = "/ghbr/admin";

// Read id safely
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo "Invalid message id";
  exit;
}

// Fetch message
$stmt = $pdo->prepare("SELECT * FROM contact_messages WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$msg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$msg) {
  http_response_code(404);
  echo "Message not found";
  exit;
}

// Mark read once (unread -> read + read_at)
if (($msg['status'] ?? '') === 'unread') {
  $pdo->prepare("UPDATE contact_messages SET status='read', read_at=COALESCE(read_at, NOW()) WHERE id=?")
      ->execute([$id]);

  // update local copy for UI
  $msg['status'] = 'read';
  $msg['read_at'] = $msg['read_at'] ?: date('Y-m-d H:i:s');
}

// helpers
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Reply mailto
$to = (string)($msg['email'] ?? '');
$subject = "Re: " . (string)($msg['subject'] ?? '');

$quoted =
"\n\n---\n"
. "From: " . (string)($msg['full_name'] ?? '') . " <" . (string)($msg['email'] ?? '') . ">\n"
. "Phone: " . (string)($msg['phone'] ?? '') . "\n"
. "Received: " . (string)($msg['created_at'] ?? '') . "\n"
. "Subject: " . (string)($msg['subject'] ?? '') . "\n\n"
. (string)($msg['message'] ?? '');

$mailto = "mailto:" . rawurlencode($to)
  . "?subject=" . rawurlencode($subject)
  . "&body=" . rawurlencode($quoted);

// Status pill class
$status = (string)($msg['status'] ?? 'read');
$pillClass = in_array($status, ['unread','read','replied','archived'], true) ? $status : 'read';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Open Message — GHBR Admin</title>

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
      gap:14px;flex-wrap:wrap;margin-top:16px
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

    .card{
      margin-top:14px;background:#fff;border-radius:var(--radius);
      border:var(--border);box-shadow:var(--shadow-sm);
      padding:18px;overflow:hidden;position:relative;
    }
    .card:before{
      content:"";position:absolute;inset:-60px -60px auto -60px;height:160px;
      background:radial-gradient(closest-side, rgba(224,181,77,0.14), rgba(224,181,77,0) 70%);
      pointer-events:none;
    }
    .card-inner{position:relative}

    .subject{
      font-family:"Merriweather",serif;color:var(--navy);
      font-size:22px;margin:0 0 12px;line-height:1.25;
    }

    .meta-grid{
      display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-top:6px
    }
    .meta-item{
      background:rgba(12,58,89,0.03);
      border:1px solid rgba(12,58,89,0.06);
      border-radius:12px;padding:10px 12px;font-size:13px;
    }
    .meta-item strong{color:rgba(12,58,89,0.92)}

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

    hr.sep{border:none;border-top:1px solid rgba(12,58,89,0.10);margin:14px 0}

    .message{
      white-space:pre-wrap;color:rgba(10,34,48,0.86);
      font-size:15px;line-height:1.65;
    }

    @media (max-width: 820px){
      .brand-sub{display:none}
      .meta-grid{grid-template-columns:1fr}
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
    <a class="brand" href="<?= h($ADMIN_BASE) ?>/index.php" aria-label="Admin dashboard home">
      <img src="/ghbr/assets/images/logo-ghbr%201.png" alt="GHBR logo">
      <div class="brand-titles">
        <div class="brand-title">GHBR Admin</div>
        <div class="brand-sub">Open message</div>
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
      <h1>Open Message</h1>
      <div class="muted" style="margin-top:6px">
        Message #<?= (int)$msg['id'] ?> •
        <span class="pill <?= h($pillClass) ?>"><?= h(strtoupper($status)) ?></span>
      </div>
    </div>

    <div class="actions">
      <a class="btn ghost" href="<?= h($ADMIN_BASE) ?>/message_view.php">← Back to Inbox</a>
      <a class="btn primary" href="<?= h($mailto) ?>">Reply (Email App)</a>
    </div>
  </div>

  <div class="card">
    <div class="card-inner">
      <h2 class="subject"><?= h((string)$msg['subject']) ?></h2>

      <div class="meta-grid" aria-label="Message details">
        <div class="meta-item">
          <strong>From</strong><br>
          <?= h((string)$msg['full_name']) ?><br>
          <a href="mailto:<?= h((string)$msg['email']) ?>" style="text-decoration:underline;text-underline-offset:3px">
            <?= h((string)$msg['email']) ?>
          </a>
        </div>

        <div class="meta-item">
          <strong>Received</strong><br>
          <?= h((string)$msg['created_at']) ?><br>
          <span class="muted" style="font-weight:700">
            Read at: <?= h((string)($msg['read_at'] ?? '—')) ?>
          </span>
        </div>

        <div class="meta-item">
          <strong>Phone</strong><br>
          <?= h((string)((($msg['phone'] ?? '') !== '') ? $msg['phone'] : '—')) ?>
        </div>

        <div class="meta-item">
          <strong>Status</strong><br>
          <span class="pill <?= h($pillClass) ?>"><?= h(ucfirst($status)) ?></span>
        </div>
      </div>

      <hr class="sep">

      <div class="message"><?= h((string)$msg['message']) ?></div>
    </div>
  </div>
</div>

</body>
</html>

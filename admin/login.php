<?php

declare(strict_types=1);
require __DIR__ . "/../config/db.php";
require __DIR__ . "/../config/auth.php";

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify($_POST['csrf'] ?? null);

  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  $stmt = $pdo->prepare("SELECT id, full_name, email, password_hash, is_active FROM admins WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  $admin = $stmt->fetch();

  if ($admin && (int)$admin['is_active'] === 1 && password_verify($password, $admin['password_hash'])) {
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_name'] = $admin['full_name'];

    $pdo->prepare("UPDATE admins SET last_login_at=NOW() WHERE id=?")->execute([$_SESSION['admin_id']]);

    header("Location: /ghbr/admin/index.php");
    exit;
  } else {
    $error = "Invalid login details.";
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login — GHBR</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&family=Merriweather:wght@300;400;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/ghbr/assets/images/favicon.png">
  <style>
    :root{--navy:#0c3a59;--gold:#c99b2a;--muted:#f6f7f9;--text:#0a2230}
    body{margin:0;font-family:Inter,system-ui;background:linear-gradient(180deg,#fff,#f8fbfd);color:var(--text)}
    .wrap{max-width:420px;margin:0 auto;padding:40px 18px}
    .card{background:#fff;border-radius:14px;padding:22px;border:1px solid rgba(12,58,89,.08);box-shadow:0 14px 40px rgba(12,58,89,.07)}
    h1{font-family:Merriweather,serif;color:var(--navy);margin:0 0 12px}
    label{display:block;margin:12px 0 6px;font-weight:700;color:var(--navy);font-size:14px}
    input{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(12,58,89,.12)}
    button{width:100%;margin-top:14px;padding:12px;border-radius:12px;border:0;background:#e0b54d;color:var(--navy);font-weight:800;cursor:pointer}
    .err{background:#ffe7e7;border:1px solid #ffb0b0;padding:10px;border-radius:10px;margin-bottom:12px}
    .notice.success {background: rgba(22,163,74,0.1);border: 1px solid rgba(22,163,74,0.3); padding: 12px;border-radius: 12px;font-weight: 700;margin-bottom: 14px;
}

  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>GHBR Admin</h1>
      <p style="margin:0 0 10px;color:#516771">Login to manage blog posts and messages.</p>

      <?php if($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (isset($_GET['logout'])): ?>
  <div class="notice success" style="color: red;">
      You have been logged out successfully.
  </div>
<?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label>Email</label>
        <input type="email" name="email" required>
        <label>Password</label>
        <input type="password" name="password" required>
        <button type="submit">Login</button>
      </form>
    </div>
  </div>
</body>
</html>

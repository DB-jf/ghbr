<?php
declare(strict_types=1);

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../config/auth.php";
require_admin();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$success = false;

// Form defaults
$form = [
  'title' => '',
  'slug' => '',
  'category' => '',
  'excerpt' => '',
  'content' => '',
  'status' => 'draft',
  'image_url' => '',
];

// Upload config
$uploadDirAbs = realpath(__DIR__ . "/..") . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "blog";
$uploadDirRel = "uploads/blog"; // stored in DB as relative path
$maxBytes = 3 * 1024 * 1024; // 3MB
$allowedExt = ['jpg','jpeg','png','webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($form as $k => $_) {
    $form[$k] = trim((string)($_POST[$k] ?? ''));
  }

  // Basic validation
  if ($form['title'] === '') $errors[] = "Title is required.";
  if ($form['content'] === '') $errors[] = "Content is required.";
  if (!in_array($form['status'], ['draft','published'], true)) $form['status'] = 'draft';

  // Slug: optional; generate if empty
  if ($form['slug'] === '') {
    $base = mb_strtolower($form['title']);
    $base = preg_replace('/[^a-z0-9\s-]/i', '', $base) ?? '';
    $base = preg_replace('/\s+/', '-', trim($base)) ?? '';
    $form['slug'] = $base !== '' ? $base : 'post-' . time();
  }

  // Image rules: allow ONE option only
  $hasUpload = isset($_FILES['image_file']) && is_array($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
  $hasUrl = $form['image_url'] !== '';

  if ($hasUpload && $hasUrl) {
    $errors[] = "Use either Image Upload OR Image URL (not both).";
  }

  // Handle image
  $coverImage = null;           // value saved to DB
  $coverImageSource = null;     // optional enum

  if (!$errors) {
    if ($hasUrl) {
      if (!filter_var($form['image_url'], FILTER_VALIDATE_URL)) {
        $errors[] = "Image URL is invalid.";
      } else {
        $coverImage = $form['image_url'];
        $coverImageSource = 'url';
      }
    }

    if ($hasUpload && !$errors) {
      // Ensure folder exists
      if (!is_dir($uploadDirAbs)) {
        @mkdir($uploadDirAbs, 0775, true);
      }
      if (!is_dir($uploadDirAbs) || !is_writable($uploadDirAbs)) {
        $errors[] = "Upload folder is missing or not writable: /uploads/blog";
      } else {
        $file = $_FILES['image_file'];

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
          $errors[] = "Image upload failed (error code: " . (int)$file['error'] . ").";
        } elseif (($file['size'] ?? 0) > $maxBytes) {
          $errors[] = "Image too large. Max size is 3MB.";
        } else {
          $name = (string)($file['name'] ?? '');
          $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

          if (!in_array($ext, $allowedExt, true)) {
            $errors[] = "Invalid image type. Allowed: JPG, PNG, WEBP.";
          } else {
            // Extra safety: verify it's an image
            $tmp = (string)($file['tmp_name'] ?? '');
            $imgInfo = @getimagesize($tmp);
            if ($imgInfo === false) {
              $errors[] = "Uploaded file is not a valid image.";
            } else {
              $safeName = bin2hex(random_bytes(10)) . "." . $ext;
              $destAbs = $uploadDirAbs . DIRECTORY_SEPARATOR . $safeName;

              if (!@move_uploaded_file($tmp, $destAbs)) {
                $errors[] = "Failed to save uploaded image.";
              } else {
                $coverImage = $uploadDirRel . "/" . $safeName; // store relative path
                $coverImageSource = 'upload';
              }
            }
          }
        }
      }
    }
  }

  // Insert to DB (adjust columns to match your table after you paste structure)
  if (!$errors) {
    // Common columns expected:
    // title, slug, category, excerpt, content, cover_image, status, created_at, published_at
    // If your table uses different names, I’ll align it after you share structure.

    $publishedAt = null;
    if ($form['status'] === 'published') {
      $publishedAt = date('Y-m-d H:i:s');
    }

    $sql = "INSERT INTO blog_posts
      (title, slug, category, excerpt, content, cover_image, status, created_at, published_at)
      VALUES
      (:title, :slug, :category, :excerpt, :content, :cover_image, :status, NOW(), :published_at)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':title' => $form['title'],
      ':slug' => $form['slug'],
      ':category' => $form['category'] !== '' ? $form['category'] : 'General',
      ':excerpt' => $form['excerpt'],
      ':content' => $form['content'],
      ':cover_image' => $coverImage,
      ':status' => $form['status'],
      ':published_at' => $publishedAt,
    ]);

    $success = true;

    // reset form
    $form = [
      'title' => '',
      'slug' => '',
      'category' => '',
      'excerpt' => '',
      'content' => '',
      'status' => 'draft',
      'image_url' => '',
    ];
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Create Post — GHBR Admin</title>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&family=Merriweather:wght@300;400;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/ghbr/assets/images/favicon.png">
  <style>
    :root{
      --navy:#0c3a59;--gold:#c99b2a;--gold-soft:#e0b54d;--text:#0a2230;
      --bg:linear-gradient(180deg,#fff,#f8fbfd 60%);
      --transition:220ms cubic-bezier(.2,.9,.25,1);
      --shadow-sm:0 8px 20px rgba(12,58,89,0.06);
      --border:1px solid rgba(12,58,89,0.08);
      --radius:14px;--max:1100px;--pad:24px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:"Inter",system-ui;background:var(--bg);color:var(--text);line-height:1.55}
    a{color:inherit;text-decoration:none}
    .wrap{max-width:var(--max);margin:0 auto;padding:0 var(--pad)}
    .muted{color:rgba(10,34,48,0.70)}
    header.topbar{position:sticky;top:0;z-index:50;background:rgba(255,255,255,0.92);backdrop-filter:saturate(140%) blur(10px);border-bottom:1px solid rgba(12,58,89,0.08)}
    .topbar-row{height:76px;display:flex;align-items:center;justify-content:space-between;gap:14px}
    .brand{display:flex;align-items:center;gap:12px;min-width:220px}
    .brand img{height:44px;width:auto}
    .brand-title{font-family:"Merriweather",serif;font-weight:800;color:var(--navy)}
    .nav{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .nav a{padding:10px 12px;border-radius:999px;font-weight:850;font-size:14px;color:rgba(12,58,89,0.92);background:rgba(12,58,89,0.03);border:1px solid rgba(12,58,89,0.08);transition:transform var(--transition), background var(--transition)}
    .nav a:hover{transform:translateY(-2px);background:rgba(201,155,42,0.12)}
    .nav a.primary{background:var(--gold-soft);color:var(--navy)}
    .page{padding:22px 0 44px}
    h1{margin:0;font-family:"Merriweather",serif;color:var(--navy);font-size:28px}

    .card{margin-top:14px;background:#fff;border-radius:var(--radius);border:var(--border);box-shadow:var(--shadow-sm);padding:18px}
    label{display:block;font-size:13px;font-weight:900;color:rgba(12,58,89,0.9);margin:10px 0 6px}
    input, textarea, select{
      width:100%;font:inherit;padding:12px;border-radius:12px;border:1px solid rgba(12,58,89,0.10);outline:none;
    }
    input:focus, textarea:focus, select:focus{border-color:rgba(201,155,42,0.55);box-shadow:0 0 0 4px rgba(201,155,42,0.18)}
    textarea{min-height:220px;resize:vertical}

    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 14px;border-radius:12px;font-weight:900;border:1px solid rgba(12,58,89,0.10);background:#fff;cursor:pointer;transition:transform var(--transition)}
    .btn:hover{transform:translateY(-2px)}
    .btn.primary{background:var(--gold-soft);color:var(--navy)}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}

    .notice{border-radius:12px;padding:12px 14px;margin-top:12px;font-weight:850;border:1px solid rgba(12,58,89,0.10);background:rgba(12,58,89,0.03)}
    .notice.ok{border-color:rgba(22,163,74,0.22);background:rgba(22,163,74,0.10);color:#0f5132}
    .notice.err{border-color:rgba(155,28,28,0.22);background:rgba(155,28,28,0.08);color:#9b1c1c}
    .hint{font-size:13px;color:rgba(10,34,48,0.65);margin-top:6px}

    .divider{height:1px;background:rgba(12,58,89,0.10);margin:16px 0}
    @media(max-width:820px){.grid{grid-template-columns:1fr}}
    @media(max-width:520px){.wrap{padding:0 16px}.nav a,.btn{width:100%}}
  </style>
</head>
<body>

<header class="topbar">
  <div class="wrap topbar-row">
    <a class="brand" href="index.php">
      <img src="../assets/images/logo-ghbr%201.png" alt="GHBR logo">
      <div class="brand-title">GHBR Admin</div>
    </a>
    <nav class="nav">
      <a href="blog_management.php">Blog</a>
      <a class="primary" href="logout.php">Logout</a>
    </nav>
  </div>
</header>

<div class="wrap page">
  <h1>Create Blog Post</h1>
  <div class="muted" style="margin-top:6px">Upload an image OR use an external image link (one option only).</div>

  <?php if ($success): ?>
    <div class="notice ok">✅ Post created successfully.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="notice err">
      <strong>Fix these issues:</strong>
      <ul style="margin:8px 0 0;padding-left:18px">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" enctype="multipart/form-data">
      <div class="grid">
        <div>
          <label for="title">Title *</label>
          <input id="title" name="title" required value="<?= h($form['title']) ?>">
        </div>

        <div>
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="draft" <?= $form['status']==='draft'?'selected':'' ?>>Draft</option>
            <option value="published" <?= $form['status']==='published'?'selected':'' ?>>Published</option>
          </select>
        </div>

        <div>
          <label for="slug">Slug (optional)</label>
          <input id="slug" name="slug" value="<?= h($form['slug']) ?>" placeholder="auto-generated if empty">
        </div>

        <div>
          <label for="category">Category</label>
          <input id="category" name="category" value="<?= h($form['category']) ?>" placeholder="e.g., Bioethics">
        </div>
      </div>

      <label for="excerpt">Excerpt (optional)</label>
      <textarea id="excerpt" name="excerpt" style="min-height:120px"><?= h($form['excerpt']) ?></textarea>
      <div class="hint">If left empty, your API can auto-generate an excerpt from content.</div>

      <label for="content">Content *</label>
      <textarea id="content" name="content" required><?= h($form['content']) ?></textarea>

      <div class="divider"></div>

      <div class="grid">
        <div>
          <label for="image_file">Upload cover image (PC)</label>
          <input id="image_file" name="image_file" type="file" accept=".jpg,.jpeg,.png,.webp,image/*">
          <div class="hint">Max 3MB. JPG/PNG/WEBP.</div>
        </div>

        <div>
          <label for="image_url">OR Cover image URL (external link)</label>
          <input id="image_url" name="image_url" value="<?= h($form['image_url']) ?>" placeholder="https://example.com/image.jpg">
          <div class="hint">Use only if you are not uploading an image.</div>
        </div>
      </div>

      <div class="actions">
        <button class="btn primary" type="submit">Save Post</button>
        <a class="btn" href="blog_management.php">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
  // Small UX: if user selects a file, clear URL (and vice versa)
  const file = document.getElementById('image_file');
  const url = document.getElementById('image_url');
  file?.addEventListener('change', () => { if(file.files?.length) url.value = ''; });
  url?.addEventListener('input', () => { if(url.value.trim()) file.value = ''; });
</script>

</body>
</html>

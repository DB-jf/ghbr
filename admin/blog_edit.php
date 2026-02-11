<?php
declare(strict_types=1);

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../config/auth.php";
require_admin();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function slugify(string $text): string {
  $text = trim($text);
  $text = preg_replace('~[^\pL\d]+~u', '-', $text);
  $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
  $text = preg_replace('~[^-\w]+~', '', $text);
  $text = trim($text, '-');
  $text = preg_replace('~-+~', '-', $text);
  $text = strtolower($text);
  return $text ?: 'post';
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo "Invalid post id"; exit; }

/** Fetch post */
$stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) { http_response_code(404); echo "Post not found"; exit; }

$errors = [];
$savedOk = false;

/** Upload config */
$uploadDirFs = realpath(__DIR__ . "/../uploads") ?: (__DIR__ . "/../uploads");
$uploadDirUrl = "/ghbr/uploads"; // URL path
if (!is_dir($uploadDirFs)) @mkdir($uploadDirFs, 0775, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title   = trim((string)($_POST['title'] ?? ''));
  $slugIn  = trim((string)($_POST['slug'] ?? ''));
  $excerpt = trim((string)($_POST['excerpt'] ?? ''));
  $content = trim((string)($_POST['content'] ?? ''));
  $category = trim((string)($_POST['category'] ?? ''));
  $tags = trim((string)($_POST['tags'] ?? ''));
  $author = trim((string)($_POST['author_name'] ?? ''));
  $status = (string)($_POST['status'] ?? 'draft');

  $coverMode = (string)($_POST['cover_mode'] ?? 'keep'); // keep|upload|url|clear
  $coverUrl  = trim((string)($_POST['cover_image_url'] ?? ''));

  if ($title === '') $errors[] = "Title is required.";
  if ($excerpt === '') $errors[] = "Excerpt is required.";
  if ($content === '') $errors[] = "Content is required.";
  if (!in_array($status, ['draft','published'], true)) $status = 'draft';

  // slug
  $slug = $slugIn !== '' ? slugify($slugIn) : slugify($title);

  // unique slug check (exclude self)
  $stmtSlug = $pdo->prepare("SELECT id FROM blog_posts WHERE slug=? AND id<>? LIMIT 1");
  $stmtSlug->execute([$slug, $id]);
  if ($stmtSlug->fetch()) {
    $slug .= '-' . $id; // safe fallback
  }

  // cover_image handling
  $finalCover = (string)($post['cover_image'] ?? '');

  if ($coverMode === 'clear') {
    $finalCover = null;
  }

  if ($coverMode === 'url') {
    if ($coverUrl === '') {
      $errors[] = "Cover image URL is empty.";
    } elseif (!preg_match('~^https?://~i', $coverUrl) && !preg_match('~^/~', $coverUrl)) {
      $errors[] = "Cover URL must start with http(s):// or /path";
    } else {
      $finalCover = $coverUrl;
    }
  }

  if ($coverMode === 'upload') {
    if (!isset($_FILES['cover_file']) || $_FILES['cover_file']['error'] === UPLOAD_ERR_NO_FILE) {
      $errors[] = "Please choose an image to upload.";
    } elseif ($_FILES['cover_file']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = "Upload failed. Try again.";
    } else {
      $tmp = $_FILES['cover_file']['tmp_name'];
      $size = (int)$_FILES['cover_file']['size'];
      $name = (string)$_FILES['cover_file']['name'];

      if ($size > 5 * 1024 * 1024) $errors[] = "Image too large (max 5MB).";

      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $allowed = ['jpg','jpeg','png','webp','gif'];
      if (!in_array($ext, $allowed, true)) $errors[] = "Invalid image type. Use jpg, png, webp, or gif.";

      if (!$errors) {
        $newName = "cover_{$id}_" . time() . "." . $ext;
        $dest = rtrim($uploadDirFs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newName;

        if (!move_uploaded_file($tmp, $dest)) {
          $errors[] = "Could not save uploaded file.";
        } else {
          $finalCover = rtrim($uploadDirUrl, '/') . "/" . $newName;
        }
      }
    }
  }

  // published_at logic
  $publishedAt = $post['published_at'] ?? null;
  if ($status === 'published') {
    if (empty($publishedAt)) {
      $publishedAt = date('Y-m-d H:i:s');
    }
  } else {
    // keep published_at as-is (so if you republish later, you can decide)
    // If you want to clear it for drafts, uncomment:
    // $publishedAt = null;
  }

  if (!$errors) {
    $sql = "UPDATE blog_posts
            SET title=?,
                slug=?,
                excerpt=?,
                content=?,
                cover_image=?,
                category=?,
                tags=?,
                author_name=?,
                status=?,
                published_at=?,
                updated_at=NOW()
            WHERE id=?";

    $pdo->prepare($sql)->execute([
      $title,
      $slug,
      $excerpt,
      $content,
      $finalCover,
      ($category !== '' ? $category : null),
      ($tags !== '' ? $tags : null),
      ($author !== '' ? $author : null),
      $status,
      $publishedAt,
      $id
    ]);

    // refresh post
    $stmt->execute([$id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    $savedOk = true;
  }
}

// preview link (front-end)
$previewSlug = (string)($post['slug'] ?? '');
$previewUrl = "/ghbr/post.php?slug=" . urlencode($previewSlug);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit Post — GHBR Admin</title>

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
      position:sticky;top:0;z-index:50;background:rgba(255,255,255,0.92);
      backdrop-filter:saturate(140%) blur(10px);
      border-bottom:1px solid rgba(12,58,89,0.08);
    }
    .topbar-row{height:76px;display:flex;align-items:center;justify-content:space-between;gap:14px}
    .brand{display:flex;align-items:center;gap:12px;min-width:220px}
    .brand img{height:44px;width:auto}
    .brand-title{font-family:"Merriweather",serif;font-weight:800;color:var(--navy);letter-spacing:0.6px}
    .brand-sub{font-size:12px;color:rgba(10,34,48,0.70);font-weight:650}
    .brand-titles{display:flex;flex-direction:column;line-height:1.1}

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
    .head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-top:16px}
    h1{margin:0;font-family:"Merriweather",serif;color:var(--navy);font-size:28px}

    .actions{display:flex;gap:10px;flex-wrap:wrap}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 14px;border-radius:12px;font-weight:900;
      border:1px solid rgba(12,58,89,0.10);background:#fff;
      box-shadow:0 10px 22px rgba(12,58,89,0.08);
      transition:transform var(--transition);
      cursor:pointer;
    }
    .btn:hover{transform:translateY(-2px)}
    .btn.primary{background:var(--gold-soft);color:var(--navy);border-color:rgba(12,58,89,0.10)}
    .btn.danger{border-color:rgba(155,28,28,0.25);color:#9b1c1c}

    .card{
      margin-top:14px;background:#fff;border-radius:var(--radius);
      border:var(--border);box-shadow:var(--shadow-sm);padding:18px;
    }
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    label{display:block;font-size:13px;font-weight:900;color:rgba(12,58,89,0.9);margin-bottom:6px}
    input, textarea, select{
      width:100%;font:inherit;padding:12px;border-radius:12px;
      border:1px solid rgba(12,58,89,0.10);outline:none;background:#fff;
    }
    input:focus, textarea:focus, select:focus{border-color:rgba(201,155,42,0.55);box-shadow:0 0 0 4px rgba(201,155,42,0.18)}
    textarea{min-height:220px;resize:vertical}

    .notice{
      border-radius:12px;padding:12px 14px;margin-top:12px;font-weight:800;
      border:1px solid rgba(12,58,89,0.10);
      background:rgba(12,58,89,0.03);
    }
    .notice.ok{border-color:rgba(22,163,74,0.22);background:rgba(22,163,74,0.10);color:#0f5132}
    .notice.err{border-color:rgba(155,28,28,0.22);background:rgba(155,28,28,0.08);color:#9b1c1c}

    .cover-preview{
      width:100%;
      height:220px;
      border-radius:14px;
      overflow:hidden;
      border:1px solid rgba(12,58,89,0.10);
      background:#eef3f6;
      display:flex;align-items:center;justify-content:center;
      margin-top:10px;
    }
    .cover-preview img{width:100%;height:100%;object-fit:cover;display:block}
    .small{font-size:13px}

    @media (max-width:820px){ .grid{grid-template-columns:1fr} .brand-sub{display:none} }
    @media (max-width:520px){ .wrap{padding:0 16px} .nav a,.btn{width:100%} }
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
        <div class="brand-sub">Edit post</div>
      </div>
    </a>

    <nav class="nav" role="navigation" aria-label="Admin navigation">
      <a href="blog_management.php" style="background:rgba(201,155,42,0.12)">Blog</a>
      <a href="message_view.php">Inbox</a>
      <a class="primary" href="logout.php">Logout</a>
    </nav>
  </div>
</header>

<div class="wrap page">
  <div class="head">
    <div>
      <h1>Edit Post</h1>
      <div class="muted" style="margin-top:6px">
        Post #<?= (int)$post['id'] ?> • Status: <strong><?= h((string)$post['status']) ?></strong>
      </div>
    </div>

    <div class="actions">
      <a class="btn" href="blog_management.php">← Back</a>
      <a class="btn" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Preview</a>
    </div>
  </div>

  <?php if ($savedOk): ?>
    <div class="notice ok">✅ Saved successfully.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="notice err">
      ⚠️ <?= h(implode(" ", $errors)) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" enctype="multipart/form-data" action="blog_edit.php">
      <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">

      <div class="grid">
        <div>
          <label for="title">Title</label>
          <input id="title" name="title" required value="<?= h((string)$post['title']) ?>">
        </div>

        <div>
          <label for="slug">Slug (optional)</label>
          <input id="slug" name="slug" value="<?= h((string)$post['slug']) ?>">
          <div class="muted small" style="margin-top:6px">If you change slug, the post URL changes.</div>
        </div>
      </div>

      <div class="grid" style="margin-top:12px">
        <div>
          <label for="category">Category</label>
          <input id="category" name="category" value="<?= h((string)($post['category'] ?? '')) ?>">
        </div>

        <div>
          <label for="tags">Tags (comma-separated)</label>
          <input id="tags" name="tags" value="<?= h((string)($post['tags'] ?? '')) ?>">
        </div>
      </div>

      <div class="grid" style="margin-top:12px">
        <div>
          <label for="author_name">Author</label>
          <input id="author_name" name="author_name" value="<?= h((string)($post['author_name'] ?? '')) ?>">
        </div>

        <div>
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="draft" <?= ((string)$post['status'] === 'draft') ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= ((string)$post['status'] === 'published') ? 'selected' : '' ?>>Published</option>
          </select>
          <div class="muted small" style="margin-top:6px">
            Publishing sets <code>published_at</code> automatically if empty.
          </div>
        </div>
      </div>

      <div style="margin-top:12px">
        <label for="excerpt">Excerpt</label>
        <textarea id="excerpt" name="excerpt" required style="min-height:120px"><?= h((string)$post['excerpt']) ?></textarea>
      </div>

      <div style="margin-top:12px">
        <label for="content">Content (HTML or plain text)</label>
        <textarea id="content" name="content" required><?= h((string)$post['content']) ?></textarea>
        <div class="muted small" style="margin-top:6px">
          If you paste HTML, it will render on the post page. Plain text also works.
        </div>
      </div>

      <div style="margin-top:14px">
        <label>Cover image</label>

        <div class="grid">
          <div>
            <label class="small" style="margin-bottom:6px">Choose option</label>
            <select name="cover_mode" id="cover_mode">
              <option value="keep">Keep existing</option>
              <option value="upload">Upload from PC</option>
              <option value="url">Use external URL</option>
              <option value="clear">Remove image</option>
            </select>
          </div>

          <div>
            <label for="cover_image_url" class="small">External image URL (optional)</label>
            <input id="cover_image_url" name="cover_image_url" placeholder="https://example.com/image.jpg">
          </div>
        </div>

        <div style="margin-top:10px">
          <label for="cover_file" class="small">Upload file (optional)</label>
          <input id="cover_file" name="cover_file" type="file" accept="image/*">
        </div>

        <div class="cover-preview" aria-label="Cover preview">
          <?php if (!empty($post['cover_image'])): ?>
            <img src="<?= h((string)$post['cover_image']) ?>" alt="Cover preview">
          <?php else: ?>
            <div class="muted" style="font-weight:800">No cover image</div>
          <?php endif; ?>
        </div>

        <div class="muted small" style="margin-top:8px">
          Uploads are saved to <code>/ghbr/uploads</code>. Max recommended 5MB.
        </div>
      </div>

      <div class="actions" style="margin-top:16px">
        <button class="btn primary" type="submit">Save Changes</button>
        <a class="btn" href="blog_management.php">Cancel</a>
      </div>
    </form>
  </div>
</div>

</body>
</html>

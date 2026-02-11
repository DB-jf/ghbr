<?php
declare(strict_types=1);
$slug = trim((string)($_GET['slug'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Post — GHBR</title>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&family=Merriweather:wght@300;400;700&display=swap" rel="stylesheet">
  <style>
    :root{--navy:#0c3a59;--gold-soft:#e0b54d;--text:#0a2230;--border:1px solid rgba(12,58,89,0.08);--radius:14px}
    body{margin:0;font-family:Inter,system-ui;background:linear-gradient(180deg,#fff,#f8fbfd 60%);color:var(--text);line-height:1.65}
    a{color:inherit}
    .wrap{max-width:900px;margin:0 auto;padding:24px}
    .top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:var(--border);background:#fff;font-weight:900;text-decoration:none}
    .btn.primary{background:var(--gold-soft);color:var(--navy)}
    h1{font-family:Merriweather,serif;color:var(--navy);margin:18px 0 10px;line-height:1.15}
    .meta{color:rgba(10,34,48,0.7);font-weight:700;font-size:13px;display:flex;gap:10px;flex-wrap:wrap}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:rgba(12,58,89,0.03);border:1px solid rgba(12,58,89,0.08);font-weight:900}
    .card{background:#fff;border:var(--border);border-radius:var(--radius);box-shadow:0 10px 30px rgba(12,58,89,0.06);overflow:hidden;margin-top:16px}
    .cover{
  width:100%;
  height:360px;            /* fixed height */
  max-height:360px;
  background:#eef3f6;
  overflow:hidden;
}
.cover img{
  width:100%;
  height:100%;
  object-fit:contain;        /* nicer hero look */
  object-position:center;
  display:block;
}

    .content{padding:18px 18px 24px}
    .content img{max-width:100%;height:auto}
    .muted{color:rgba(10,34,48,0.70)}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <a class="btn" href="blog.html">← Back to blog</a>
      <a class="btn primary" href="index.html#contact">Contact</a>
    </div>

    <?php if ($slug === ''): ?>
      <div class="card"><div class="content">
        <div style="font-weight:900;color:#9b1c1c">Missing post slug.</div>
        <div class="muted">Open a post from the blog list.</div>
      </div></div>
    <?php else: ?>
      <div id="postWrap" class="card" style="display:none">
        <div class="cover"><img id="cover" alt=""></div>
        <div class="content">
          <h1 id="title"></h1>
          <div class="meta">
            <span class="pill" id="cat"></span>
            <span class="pill" id="date"></span>
            <span class="pill" id="author"></span>
          </div>
          <div id="body" style="margin-top:14px"></div>
        </div>
      </div>

      <div id="loading" class="card"><div class="content">
        <div style="font-weight:900;color:var(--navy)">Loading post…</div>
        <div class="muted">Fetching from database.</div>
      </div></div>

      <div id="error" class="card" style="display:none"><div class="content">
        <div style="font-weight:900;color:#9b1c1c">Post not found.</div>
        <div class="muted">It may be a draft or deleted.</div>
      </div></div>

      <script>
        const slug = <?= json_encode($slug) ?>;

        function esc(s){
          return String(s ?? '')
            .replaceAll('&','&amp;')
            .replaceAll('<','&lt;')
            .replaceAll('>','&gt;')
            .replaceAll('"','&quot;')
            .replaceAll("'","&#039;");
        }

        function formatDate(value){
          if(!value) return '—';
          const d = new Date(value);
          if(!Number.isNaN(d.getTime())){
            return d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' });
          }
          return String(value);
        }

        (async () => {
          try{
            const res = await fetch(`/ghbr/api/post.php?slug=${encodeURIComponent(slug)}`, { headers:{'Accept':'application/json'} });
            if(!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            const p = data.post;

            document.getElementById('title').textContent = p.title || 'Untitled';
            document.getElementById('cover').src = p.cover_image || 'assets/images/team.png';
            document.getElementById('cover').alt = p.title || 'Cover image';
            document.getElementById('cat').textContent = '🏷️ ' + (p.category || 'General');
            document.getElementById('date').textContent = '📅 ' + formatDate(p.date || p.published_at || p.created_at);
            document.getElementById('author').textContent = '✍️ ' + (p.author_name || 'GHBR');

            // content is stored in DB as HTML or text
            // If you store plain text, you can do: esc(p.content).replaceAll('\n','<br>')
            document.getElementById('body').innerHTML = (p.content || '<div class="muted">No content.</div>');

            document.getElementById('loading').style.display = 'none';
            document.getElementById('postWrap').style.display = 'block';
          }catch(e){
            console.error(e);
            document.getElementById('loading').style.display = 'none';
            document.getElementById('error').style.display = 'block';
          }
        })();
      </script>
    <?php endif; ?>
  </div>
</body>
</html>

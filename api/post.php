<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../config/db.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') { http_response_code(400); echo json_encode(['error'=>'Missing slug']); exit; }

$stmt = $pdo->prepare("
  SELECT id, title, slug, excerpt, content, cover_image, category, tags, author_name,
         status, published_at, created_at, updated_at
  FROM blog_posts
  WHERE slug=? AND status='published'
  LIMIT 1
");
$stmt->execute([$slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) { http_response_code(404); echo json_encode(['error'=>'Post not found']); exit; }

if (empty($post['cover_image'])) $post['cover_image'] = 'assets/images/team.png';
$post['date'] = $post['published_at'] ?? $post['created_at'] ?? null;

echo json_encode(['post'=>$post], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

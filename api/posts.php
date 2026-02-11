<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config/db.php';

function out(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 6)));
$offset = ($page - 1) * $limit;

$q = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'newest');

$allowedSort = ['newest', 'oldest', 'popular'];
if (!in_array($sort, $allowedSort, true)) $sort = 'newest';

/**
 * WHERE: show only published posts on the public site
 * If you want drafts visible too, remove the first condition.
 */
$where = ["status='published'"];
$params = [];

if ($category !== '') {
  $where[] = "category = ?";
  $params[] = $category;
}

if ($q !== '') {
  $where[] = "(title LIKE ? OR excerpt LIKE ? OR content LIKE ? OR tags LIKE ? OR author_name LIKE ? OR category LIKE ?)";
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like, $like, $like, $like);
}

$whereSql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

/**
 * ORDER BY
 * newest: published_at desc (fallback created_at)
 * oldest: published_at asc (fallback created_at)
 * popular: if you don't have a views column, fallback to newest
 */
if ($sort === 'oldest') {
  $orderBy = " ORDER BY COALESCE(published_at, created_at) ASC, id ASC";
} else {
  // newest OR popular fallback
  $orderBy = " ORDER BY COALESCE(published_at, created_at) DESC, id DESC";
}

/** total count */
$stmtTotal = $pdo->prepare("SELECT COUNT(*) AS c FROM blog_posts" . $whereSql);
$stmtTotal->execute($params);
$total = (int)($stmtTotal->fetch()['c'] ?? 0);

$pages = max(1, (int)ceil($total / $limit));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $limit;

/** fetch posts */
$sql = "
  SELECT
    id, title, slug, excerpt, cover_image, category, tags, author_name, status,
    published_at, created_at, updated_at
  FROM blog_posts
  $whereSql
  $orderBy
  LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/** categories (for dropdown/sidebar) */
$cats = $pdo->query("
  SELECT category, COUNT(*) AS c
  FROM blog_posts
  WHERE status='published' AND category IS NOT NULL AND category <> ''
  GROUP BY category
  ORDER BY c DESC, category ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$categories = array_map(fn($r) => (string)$r['category'], $cats);

out([
  'posts' => array_map(function($p){
    // Normalize date field for frontend convenience
    $p['date'] = $p['published_at'] ?? $p['created_at'] ?? null;

    // Provide safe fallback image
    if (empty($p['cover_image'])) {
      $p['cover_image'] = 'assets/images/team.png';
    }
    return $p;
  }, $posts),
  'page' => $page,
  'pages' => $pages,
  'total' => $total,
  'categories' => $categories
]);

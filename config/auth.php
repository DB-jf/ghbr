<?php
// config/auth.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function require_admin(): void {
  if (empty($_SESSION['admin_id'])) {
    header("Location: /admin/login.php");
    exit;
  }
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}

function csrf_verify(?string $token): void {
  if (empty($_SESSION['csrf']) || !$token || !hash_equals($_SESSION['csrf'], $token)) {
    http_response_code(403);
    echo "Invalid CSRF token.";
    exit;
  }
}

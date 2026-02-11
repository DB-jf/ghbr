<?php
// config/db.php
declare(strict_types=1);

$DB_HOST = "localhost";
$DB_NAME = "ghbr_db";
$DB_USER = "root";
$DB_PASS = ""; // set yours

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false
];

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    $options
  );
} catch (PDOException $e) {
  http_response_code(500);
  echo "Database connection failed.";
  exit;
}

<?php
declare(strict_types=1);

// contact_submit.php is already in /config
require __DIR__ . "/db.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

function clean(string $v): string {
  return trim($v);
}

$full_name = clean($_POST['full_name'] ?? '');
$email     = clean($_POST['email'] ?? '');
$phone     = clean($_POST['phone'] ?? '');
$subject   = clean($_POST['subject'] ?? '');
$message   = clean($_POST['message'] ?? '');

$errors = [];
if ($full_name === '') $errors[] = 'Full name is required';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
if ($subject === '') $errors[] = 'Subject is required';
if ($message === '') $errors[] = 'Message is required';

if ($errors) {
  http_response_code(422);
  echo implode("\n", $errors);
  exit;
}

$sql = "INSERT INTO contact_messages
        (full_name, email, phone, subject, message, status)
        VALUES
        (:full_name, :email, :phone, :subject, :message, 'unread')";

$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':full_name' => $full_name,
  ':email'     => $email,
  ':phone'     => ($phone === '' ? null : $phone),
  ':subject'   => $subject,
  ':message'   => $message,
]);

header("Location: ../index.html#contact?sent=1");
exit;

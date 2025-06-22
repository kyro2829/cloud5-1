<?php
session_start();
$host = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'user_auth';

// Connect to MySQL
$mysqli = new mysqli($host, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo 'Database connection error.';
  exit;
}

function sanitize($input) {
  return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($type, $msg) {
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
  header('Location: index.php');
  exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'register') {
    $username = sanitize($_POST['username'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['confirm_password'] ?? '';

    if (!$username || !$email || !$password || !$passwordConfirm) {
      redirectWithMessage('error', 'Please fill out all registration fields.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      redirectWithMessage('error', 'Invalid email address.');
    }
    if ($password !== $passwordConfirm) {
      redirectWithMessage('error', 'Passwords do not match.');
    }
    if (strlen($password) < 6) {
      redirectWithMessage('error', 'Password must be at least 6 characters.');
    }

    // Check if username or email exist
    $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->bind_param('ss', $username, $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
      $stmt->close();
      redirectWithMessage('error', 'Username or Email already exists.');
    }
    $stmt->close();

    // Insert new user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $email, $hash);
    if ($stmt->execute()) {
      $stmt->close();
      $_SESSION['user_name'] = $username;
      header('Location: drive.php');
      exit();
    } else {
      $stmt->close();
      redirectWithMessage('error', 'Registration failed, try again.');
    }
  } elseif ($action === 'login') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
      redirectWithMessage('error', 'Please fill out all login fields.');
    }

    $stmt = $mysqli->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($id, $hash);
    if ($stmt->fetch()) {
      if (password_verify($password, $hash)) {
        $stmt->close();
        $_SESSION['user_name'] = $username;
        header('Location: drive.php');
        exit();
      }
    }
    $stmt->close();
    redirectWithMessage('error', 'Invalid username or password.');
  } else {
    http_response_code(400);
    echo 'Bad request.';
  }
} else {
  http_response_code(405);
  echo 'Method not allowed.';
}

<?php
session_start();

$supabaseUrl = 'https://vilzpnkkugfovvlcjwvr.supabase.co';
$supabaseKey = 'YOUR_SUPABASE_ANON_KEY'; // Replace with your real anon key

function sanitize($input) {
  return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($type, $msg) {
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
  header('Location: index.php');
  exit();
}

function supabaseSelect($table, $filter) {
  global $supabaseUrl, $supabaseKey;
  $url = $supabaseUrl . "/rest/v1/$table?$filter";

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $supabaseKey",
    "Authorization: Bearer $supabaseKey"
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return json_decode($res, true);
}

function supabaseInsert($table, $data) {
  global $supabaseUrl, $supabaseKey;
  $url = $supabaseUrl . "/rest/v1/$table";

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $supabaseKey",
    "Authorization: Bearer $supabaseKey",
    "Content-Type: application/json",
    "Prefer: return=representation"
  ]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  $res = curl_exec($ch);
  curl_close($ch);
  return json_decode($res, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'register') {
    $username = sanitize($_POST['username'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$username || !$email || !$password || !$confirm) {
      redirectWithMessage('error', 'Please fill out all registration fields.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      redirectWithMessage('error', 'Invalid email address.');
    }
    if ($password !== $confirm) {
      redirectWithMessage('error', 'Passwords do not match.');
    }
    if (strlen($password) < 6) {
      redirectWithMessage('error', 'Password must be at least 6 characters.');
    }

    // Check if user exists
    $existing = supabaseSelect("users", "or=(username.eq.$username,email.eq.$email)");
    if (!empty($existing)) {
      redirectWithMessage('error', 'Username or Email already exists.');
    }

    // Create new user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $res = supabaseInsert("users", [
      "username" => $username,
      "email" => $email,
      "password_hash" => $hash
    ]);

    if (isset($res[0]['id'])) {
      $_SESSION['user_name'] = $username;
      header('Location: drive.php');
      exit();
    } else {
      redirectWithMessage('error', 'Registration failed, try again.');
    }

  } elseif ($action === 'login') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
      redirectWithMessage('error', 'Please fill out all login fields.');
    }

    $user = supabaseSelect("users", "username=eq.$username");
    if (!empty($user) && password_verify($password, $user[0]['password_hash'])) {
      $_SESSION['user_name'] = $username;
      header('Location: drive.php');
      exit();
    }

    redirectWithMessage('error', 'Invalid username or password.');
  } else {
    http_response_code(400);
    echo 'Bad request.';
  }
} else {
  http_response_code(405);
  echo 'Method not allowed.';
}

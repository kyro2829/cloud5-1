<?php
session_start();

// Replace with your actual Supabase project URL and anon key
$supabaseUrl = 'https://vilzpnkkugfovvlcjwvr.supabase.co';
$supabaseKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZpbHpwbmtrdWdmb3Z2bGNqd3ZyIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTA1Nzc0NzksImV4cCI6MjA2NjE1MzQ3OX0.0eYfbF1jQABBZP7jf8DRvZNDXw1Dt0CtXPhEsPwtMH4';

function sanitize($input) {
  return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($type, $msg) {
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
  header('Location: index.php');
  exit();
}

function supabaseQuery($method, $table, $data = [], $filter = '') {
  global $supabaseUrl, $supabaseKey;

  $url = "$supabaseUrl/rest/v1/$table" . ($filter ? "?$filter" : '');
  $headers = [
    "apikey: $supabaseKey",
    "Authorization: Bearer $supabaseKey",
    "Content-Type: application/json"
  ];

  if ($method === 'POST') {
    $headers[] = 'Prefer: return=representation';
  }

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  if ($method === 'POST' && !empty($data)) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  }
  $res = curl_exec($ch);
  curl_close($ch);

  return json_decode($res, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['register-name'], $_POST['register-email'], $_POST['register-password'], $_POST['register-password-confirm'])) {
    $username = sanitize($_POST['register-name']);
    $email = sanitize($_POST['register-email']);
    $password = $_POST['register-password'];
    $confirm = $_POST['register-password-confirm'];

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

    $existing = supabaseQuery('GET', 'users', [], "or=(username.eq.$username,email.eq.$email)");
    if (!empty($existing)) {
      redirectWithMessage('error', 'Username or Email already exists.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $inserted = supabaseQuery('POST', 'users', [
      'username' => $username,
      'email' => $email,
      'password_hash' => $hash
    ]);

    if (isset($inserted[0]['id'])) {
      $_SESSION['user_id'] = $inserted[0]['id'];
      $_SESSION['user_name'] = $username;
      header('Location: drive.php');
      exit();
    } else {
      redirectWithMessage('error', 'Registration failed, try again.');
    }
  }
  elseif (isset($_POST['login-email'], $_POST['login-password'])) {
    $email = sanitize($_POST['login-email']);
    $password = $_POST['login-password'];

    if (!$email || !$password) {
      redirectWithMessage('error', 'Please fill out all login fields.');
    }

    $user = supabaseQuery('GET', 'users', [], "email=eq.$email");
    if (!empty($user) && password_verify($password, $user[0]['password_hash'])) {
      $_SESSION['user_id'] = $user[0]['id'];
      $_SESSION['user_name'] = $user[0]['username'];
      header('Location: drive.php');
      exit();
    } else {
      redirectWithMessage('error', 'Invalid email or password.');
    }
  }
  else {
    http_response_code(400);
    echo 'Bad request.';
  }
} else {
  http_response_code(405);
  echo 'Method not allowed.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login & Register</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --sky-blue: #0ea5e9;
      --white: #ffffff;
      --gray-light: #f9fafb;
      --gray-medium: #6b7280;
      --gray-dark: #374151;
      --border-radius: 0.75rem;
      --transition-speed: 0.3s;
      --input-bg: #f0f9ff;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Poppins', sans-serif;
      background-color: var(--gray-light);
      color: var(--gray-dark);
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
    }

    .container {
      background-color: var(--white);
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      border-radius: var(--border-radius);
      width: 100%;
      max-width: 420px;
      padding: 2rem;
    }

    h2 {
      font-size: 2rem;
      color: var(--sky-blue);
      text-align: center;
      margin-bottom: 1.5rem;
    }

    .tabs {
      display: flex;
      justify-content: space-around;
      margin-bottom: 2rem;
    }

    .tab-button {
      background: none;
      border: none;
      font-weight: 600;
      font-size: 1rem;
      color: var(--gray-medium);
      cursor: pointer;
      padding-bottom: 0.5rem;
      border-bottom: 3px solid transparent;
      transition: all var(--transition-speed);
    }

    .tab-button.active {
      color: var(--sky-blue);
      border-bottom-color: var(--sky-blue);
    }

    form {
      display: none;
      flex-direction: column;
    }

    form.active {
      display: flex;
    }

    .form-group {
      margin-bottom: 1rem;
    }

    label {
      display: block;
      font-size: 0.9rem;
      margin-bottom: 0.3rem;
      color: var(--gray-medium);
    }

    input {
      width: 100%;
      padding: 0.7rem;
      font-size: 1rem;
      border: 1px solid #ccc;
      border-radius: var(--border-radius);
      background-color: var(--input-bg);
      transition: all var(--transition-speed);
    }

    input:focus {
      border-color: var(--sky-blue);
      box-shadow: 0 0 5px rgba(14, 165, 233, 0.5);
      background-color: var(--white);
      outline: none;
    }

    button[type="submit"] {
      margin-top: 1rem;
      background-color: var(--sky-blue);
      color: var(--white);
      font-weight: 600;
      font-size: 1rem;
      padding: 0.75rem;
      border: none;
      border-radius: var(--border-radius);
      cursor: pointer;
      transition: all var(--transition-speed);
    }

    button[type="submit"]:hover {
      background-color: #0c87c9;
      transform: scale(1.03);
    }

    .switch-text {
      text-align: center;
      margin-top: 1rem;
      font-size: 0.9rem;
      color: var(--gray-medium);
    }

    .switch-text button {
      background: none;
      border: none;
      color: var(--sky-blue);
      cursor: pointer;
      font-weight: 600;
      margin-left: 0.3rem;
    }

    .switch-text button:hover {
      color: #0c87c9;
      text-decoration: underline;
    }

    .error-message {
      color: red;
      text-align: center;
      margin-bottom: 1rem;
    }

    .success-message {
      color: green;
      text-align: center;
      margin-bottom: 1rem;
    }

    @media (max-width: 480px) {
      .container { padding: 1.5rem; }
      h2 { font-size: 1.75rem; }
    }
  </style>
</head>
<body>
  <main class="container">
    <h2>Welcome</h2>
    <?php if (!empty($error)): ?>
      <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <nav class="tabs">
      <button class="tab-button active" id="login-tab">Login</button>
      <button class="tab-button" id="register-tab">Register</button>
    </nav>

    <form id="login-form" class="active" action="" method="POST">
      <div class="form-group">
        <label for="login-email">Email</label>
        <input type="email" id="login-email" name="login-email" required />
      </div>
      <div class="form-group">
        <label for="login-password">Password</label>
        <input type="password" id="login-password" name="login-password" required />
      </div>
      <button type="submit">Log In</button>
      <p class="switch-text">Don't have an account?<button type="button" id="to-register">Register</button></p>
    </form>

    <form id="register-form" action="" method="POST">
      <div class="form-group">
        <label for="register-name">Full Name</label>
        <input type="text" id="register-name" name="register-name" required />
      </div>
      <div class="form-group">
        <label for="register-email">Email</label>
        <input type="email" id="register-email" name="register-email" required />
      </div>
      <div class="form-group">
        <label for="register-password">Password</label>
        <input type="password" id="register-password" name="register-password" required />
      </div>
      <div class="form-group">
        <label for="register-password-confirm">Confirm Password</label>
        <input type="password" id="register-password-confirm" name="register-password-confirm" required />
      </div>
      <button type="submit">Register</button>
      <p class="switch-text">Already have an account?<button type="button" id="to-login">Login</button></p>
    </form>
  </main>

  <script>
    const loginTab = document.getElementById('login-tab');
    const registerTab = document.getElementById('register-tab');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const toRegister = document.getElementById('to-register');
    const toLogin = document.getElementById('to-login');

    loginTab.addEventListener('click', () => {
      loginTab.classList.add('active');
      registerTab.classList.remove('active');
      loginForm.classList.add('active');
      registerForm.classList.remove('active');
    });

    registerTab.addEventListener('click', () => {
      registerTab.classList.add('active');
      loginTab.classList.remove('active');
      registerForm.classList.add('active');
      loginForm.classList.remove('active');
    });

    toRegister.addEventListener('click', () => registerTab.click());
    toLogin.addEventListener('click', () => loginTab.click());
  </script>
</body>
</html>

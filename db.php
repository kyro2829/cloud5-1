<?php
$host = "db.vilzpnkkugfovvlcjwvr.supabase.co";
$port = "5432";
$db   = "postgres";
$user = "postgres";
$pass = "Kyro@supabase!";  // <-- Replace with your Supabase database password

$dsn = "pgsql:host=$host;port=$port;dbname=$db";

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "Connected successfully"; // Optional for testing
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
    exit();
}
?>

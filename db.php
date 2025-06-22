<?php
$host = 'aws-0-ap-southeast-1.pooler.supabase.com'; // IPv4-compatible host (Pooler)
$port = '5432';
$db   = 'postgres';
$user = 'postgres.vilzpnkkugfovvlcjwvr'; // Replace with your pooler-compatible username
$pass = 'Kyro@supabase!';    // Replace with your actual Supabase DB password

$dsn = "pgsql:host=$host;port=$port;dbname=$db";

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>

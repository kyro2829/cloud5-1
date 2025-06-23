<?php
ini_set('session.save_path', '/tmp');
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit();
}

$userId = $_SESSION['user_id'];
$baseStorageDir = './uploads/';
$uploadDir = $baseStorageDir . $userId . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fileName = basename($_POST['file'] ?? '');

    if (!$fileName) {
        echo json_encode(["success" => false, "error" => "Missing file parameter"]);
        exit();
    }

    $filePath = $uploadDir . $fileName;

    // Check if file exists
    if (!file_exists($filePath) || !is_file($filePath)) {
        echo json_encode(["success" => false, "error" => "File not found"]);
        exit();
    }

    // Delete file
    if (unlink($filePath)) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => "Failed to delete file"]);
    }
} else {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Invalid request method"]);
}
?>

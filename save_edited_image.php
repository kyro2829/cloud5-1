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

// Make sure user directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Handle uploaded edited image
if (isset($_FILES['edited_image'])) {
    $originalName = basename($_POST['original_name']);
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);

    // Generate a unique filename for edited version
    $newFileName = pathinfo($originalName, PATHINFO_FILENAME) . '_edited_' . time() . '.' . $extension;
    $targetPath = $uploadDir . $newFileName;

    if (move_uploaded_file($_FILES['edited_image']['tmp_name'], $targetPath)) {
        echo json_encode(["success" => true, "file" => $newFileName]);
    } else {
        echo json_encode(["success" => false, "error" => "Failed to save file"]);
    }
} else {
    echo json_encode(["success" => false, "error" => "No file uploaded"]);
}
?>

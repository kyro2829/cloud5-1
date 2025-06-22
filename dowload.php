<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user information
$userId = $_SESSION['user_id'];

// Define the base storage directory (same as in upload.php and drive.php)
$baseStorageDir = '/storage/';

// Get the user's upload directory
$uploadDir = $baseStorageDir . $userId . '/';

// Get the requested filename from the URL
$filename = isset($_GET['file']) ? basename($_GET['file']) : ''; // Use basename for security

// Construct the full path to the file
$filepath = $uploadDir . $filename;

// Check if the file exists and is within the user's directory
if (file_exists($filepath) && strpos(realpath($filepath), realpath($uploadDir)) === 0) {
    // Set the appropriate headers for file download
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filepath));

    // Read the file and output it to the browser
    readfile($filepath);
    exit;
} else {
    // File not found or access denied
    echo "File not found or you do not have permission to access this file.";
}
?>

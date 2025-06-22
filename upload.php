<?php
ini_set('session.save_path', '/tmp');
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'User not logged in']);
    exit();
}

// Check if files were uploaded
if (!isset($_FILES['uploaded_files']) || empty($_FILES['uploaded_files']['name'][0])) {
    http_response_code(400);
    echo json_encode(['error' => 'No files uploaded']);
    exit();
}

$userId = $_SESSION['user_id'];
$baseStorageDir = './files/';
$uploadDir = $baseStorageDir . $userId . '/';

// Create the user's directory if it doesn't exist
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create user directory']);
        exit();
    }
}

// Allowed image types
$allowedTypes = [
    'image/jpeg',
    'image/jpg', 
    'image/png',
    'image/gif',
    'image/webp',
    'image/bmp'
];

// Maximum file size (5MB)
$maxFileSize = 5 * 1024 * 1024;

$uploadedFiles = [];
$errors = [];

// Process each uploaded file
$fileCount = count($_FILES['uploaded_files']['name']);

for ($i = 0; $i < $fileCount; $i++) {
    $fileName = $_FILES['uploaded_files']['name'][$i];
    $fileTmpName = $_FILES['uploaded_files']['tmp_name'][$i];
    $fileSize = $_FILES['uploaded_files']['size'][$i];
    $fileError = $_FILES['uploaded_files']['error'][$i];
    $fileType = $_FILES['uploaded_files']['type'][$i];
    
    // Skip empty files
    if (empty($fileName)) {
        continue;
    }
    
    // Check for upload errors
    if ($fileError !== UPLOAD_ERR_OK) {
        $errors[] = "Error uploading $fileName: Upload error code $fileError";
        continue;
    }
    
    // Verify file type
    if (!in_array($fileType, $allowedTypes)) {
        $errors[] = "File $fileName: Invalid file type. Only images are allowed.";
        continue;
    }
    
    // Check file size
    if ($fileSize > $maxFileSize) {
        $errors[] = "File $fileName: File too large. Maximum size is 5MB.";
        continue;
    }
    
    // Additional security check - verify it's actually an image
    $imageInfo = getimagesize($fileTmpName);
    if ($imageInfo === false) {
        $errors[] = "File $fileName: Not a valid image file.";
        continue;
    }
    
    // Generate safe filename
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $baseName = pathinfo($fileName, PATHINFO_FILENAME);
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
    
    // Ensure filename is unique
    $finalFileName = $safeName . '.' . $fileExtension;
    $counter = 1;
    while (file_exists($uploadDir . $finalFileName)) {
        $finalFileName = $safeName . '_' . $counter . '.' . $fileExtension;
        $counter++;
    }
    
    $destination = $uploadDir . $finalFileName;
    
    // Move the uploaded file
    if (move_uploaded_file($fileTmpName, $destination)) {
        $uploadedFiles[] = $finalFileName;
    } else {
        $errors[] = "Failed to save file $fileName";
    }
}

// Return response
if (!empty($uploadedFiles)) {
    $message = count($uploadedFiles) . " file(s) uploaded successfully";
    if (!empty($errors)) {
        $message .= ". Some files had errors: " . implode(', ', $errors);
    }
    echo json_encode([
        'success' => true,
        'message' => $message,
        'uploaded_files' => $uploadedFiles,
        'errors' => $errors
    ]);
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No files were uploaded successfully',
        'errors' => $errors
    ]);
}
?>

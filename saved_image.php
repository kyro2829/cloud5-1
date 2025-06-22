<?php
session_start();
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}
// === Handle Rename ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_image'], $_POST['new_name'])) {
    $oldName = basename($_POST['rename_image']);
    $newNameRaw = trim($_POST['new_name']);
    $ext = pathinfo($oldName, PATHINFO_EXTENSION);
    $safeNewName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($newNameRaw, PATHINFO_FILENAME));
    $newName = $safeNewName . '.' . $ext;

    $oldPath = __DIR__ . "/uploads/$userId/images/$oldName";
    $newPath = __DIR__ . "/uploads/$userId/images/$newName";

    if ($oldName !== $newName && file_exists($oldPath)) {
        if (rename($oldPath, $newPath)) {
            try {
                $pdo = new PDO("mysql:host=localhost;dbname=user_auth", "root", "");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $stmt = $pdo->prepare("UPDATE uploaded_images SET file_name = ?, file_path = ? WHERE file_name = ? AND user_id = ?");
                $stmt->execute([$newName, $newPath, $oldName, $userId]);

                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } catch (Exception $e) {
                error_log("Rename DB error: " . $e->getMessage());
            }
        }
    }
}

// === Handle Image Upload ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['edited_image'])) {
    if (!isset($_POST['original_name']) || empty($_POST['original_name'])) {
        echo json_encode(['success' => false, 'message' => 'Original filename is missing']);
        exit();
    }

    if ($_FILES['edited_image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Upload error: ' . $_FILES['edited_image']['error']]);
        exit();
    }

    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    $filename = basename(strip_tags($_POST['original_name']));
    $fileType = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type']);
        exit();
    }

    $saveFolder = __DIR__ . "/uploads/$userId/images/";
    if (!is_dir($saveFolder)) mkdir($saveFolder, 0755, true);

    $savePath = $saveFolder . $filename;

    if (!move_uploaded_file($_FILES['edited_image']['tmp_name'], $savePath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save image']);
        exit();
    }

    chmod($savePath, 0644);

    try {
        $pdo = new PDO("mysql:host=localhost;dbname=user_auth", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("UPDATE uploaded_images SET file_path = ?, uploaded_at = NOW() WHERE file_name = ? AND user_id = ?");
        $stmt->execute([$savePath, $filename, $userId]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("INSERT INTO uploaded_images (user_id, file_name, file_path, file_type, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$userId, $filename, $savePath, $fileType]);
        }

        echo json_encode(['success' => true]);
        exit();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle Actions (Delete, View, Download)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['image']) || empty($_POST['image'])) {
        echo json_encode(['success' => false, 'message' => 'Image name is missing']);
        exit();
    }

    $imageName = basename(strip_tags($_POST['image']));
    $imagePath = __DIR__ . "/uploads/$userId/images/" . $imageName;

    switch ($_POST['action']) {
        case 'delete':
            if (file_exists($imagePath) && unlink($imagePath)) {
                try {
                    $pdo = new PDO("mysql:host=localhost;dbname=user_auth", "root", "");
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    $stmt = $pdo->prepare("DELETE FROM uploaded_images WHERE file_name = ? AND user_id = ?");
                    $stmt->execute([$imageName, $userId]);

                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit();
                } catch (Exception $e) {
                    echo "Database error: " . $e->getMessage();
                    exit();
                }
            }
            break;

        case 'view':
            if (file_exists($imagePath)) {
                $mime = mime_content_type($imagePath);
                header('Content-Type: ' . $mime);
                readfile($imagePath);
                exit();
            }
            break;

        case 'download':
            if (file_exists($imagePath)) {
                $mime = mime_content_type($imagePath);
                header('Content-Type: ' . $mime);
                header('Content-Disposition: attachment; filename="' . $imageName . '"');
                header('Content-Length: ' . filesize($imagePath));
                readfile($imagePath);
                exit();
            }
            break;
    }
}

// === END JSON Handler ===
// === BEGIN HTML rendering ===
header('Content-Type: text/html');

$username = $_SESSION['user_name'] ?? 'User';
$uploadDir = "uploads/$userId/images/";
$imageFiles = [];
$searchQuery = $_GET['search'] ?? '';

if (is_dir($uploadDir)) {
    $imageFiles = array_filter(scandir($uploadDir), function($file) use ($uploadDir, $searchQuery) {
        if (!is_file($uploadDir . $file)) return false;
        if (!preg_match('/\.(jpg|jpeg|png|gif)$/i', $file)) return false;
        if ($searchQuery && stripos($file, $searchQuery) === false) return false;
        return true;
    });
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Saved Images</title>
  <h1>Saved Images for <?php echo htmlspecialchars($username); ?></h1>
<form method="GET" style="margin-bottom: 32px;">
  <input type="text" name="search" placeholder="Search images..." value="<?php echo htmlspecialchars($searchQuery); ?>" style="padding: 10px; width: 280px; border-radius: 8px; border: 1px solid #ccc;">
  <button type="submit" style="margin-left: 10px; padding: 10px 20px; border-radius: 8px; background-color: #87ceeb; color: white; border: none; font-weight: 600;">🔍 Search</button>
</form>

  <style>
    /* Base and body styles */
    body {
      font-family: 'Inter', Arial, sans-serif;
      background-color: #ffffff; /* White background */
      color: #003366; /* Dark blue text */
      text-align: center;
      margin: 40px 20px;
      padding: 0;
      line-height: 1.6;
    }

    h1 {
      font-weight: 700;
      font-size: 2.2rem;
      margin-bottom: 40px;
      letter-spacing: 0.03em;
    }

    /* Gallery grid styles */
    .gallery {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
      gap: 32px; /* generous gap */
      max-width: 1200px;
      margin: 0 auto 48px auto;
      padding: 0 16px;
    }

    /* Each image container */
    .gallery > div {
  display: flex;
  flex-direction: column;
  align-items: center;
  background: #ffffff;
  border-radius: 16px;
  padding: 20px;
  margin: 8px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
  transition: box-shadow 0.3s ease, transform 0.2s ease;
  min-width: 320px;
  max-width: 100%;
}

.gallery > div:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
}

.gallery {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 40px;
  max-width: 1200px;
  margin: 0 auto 48px auto;
  padding: 0 16px;
}

    .gallery > div:hover {
      box-shadow: 0 8px 28px rgba(3, 27, 68, 0.2);
      img {
  width: 100%;
  max-width: 100%;
  height: auto;
  border-radius: 10px;
  border: 1px solid #cbd6f0;
  object-fit: cover;
  margin-bottom: 16px;
}

    }

    /* Images styling */
    img {
      width: 100%;
      height: auto;
      border-radius: 10px;
      border: 1px solid #cbd6f0;
      object-fit: cover;
      margin-bottom: 16px;
    }

    /* Form and buttons styling */
    form {
      display: flex;
      justify-content: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    button {
      background-color: #87ceeb; /* sky blue */
      color: #ffffff;
      border: none;
      padding: 10px 20px;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background-color 0.3s ease, transform 0.2s ease;
      min-width: 90px;
      user-select: none;
      box-shadow: 0 2px 8px rgba(135, 206, 235, 0.5);
    }
    button:hover,
    button:focus {
      background-color: #5aa7db;
      outline: none;
      transform: translateY(-2px);
      box-shadow: 0 4px 14px rgba(90, 167, 219, 0.7);
    }

    button:active {
      transform: translateY(0);
      box-shadow: 0 2px 8px rgba(135, 206, 235, 0.5);
    }

    /* Back to drive button styles */
    body > button {
      background-color: #87ceeb;
      color: white;
      border: none;
      padding: 12px 32px;
      border-radius: 10px;
      font-size: 1.1rem;
      font-weight: 700;
      cursor: pointer;
      transition: background-color 0.3s ease, transform 0.2s ease;
      user-select: none;
      box-shadow: 0 4px 16px rgba(135, 206, 235, 0.6);
      margin-top: 32px;
      max-width: 240px;
    }
    body > button:hover,
    body > button:focus {
      background-color: #5aa7db;
      outline: none;
      transform: translateY(-2px);
      box-shadow: 0 8px 28px rgba(90, 167, 219, 0.8);
    }
    body > button:active {
      transform: translateY(0);
      box-shadow: 0 4px 16px rgba(135, 206, 235, 0.6);
    }

    /* Responsive adjustments */
    @media (max-width: 480px) {
      .gallery {
        grid-template-columns: 1fr;
        gap: 24px;
      }
      form {
        flex-direction: column;
        gap: 10px;
      }
      button {
        min-width: 100%;
        padding: 14px;
        font-size: 1.1rem;
      }
      body > button {
        max-width: 100%;
        padding: 14px;
        font-size: 1.2rem;
      }
      .action-button {
        background-color: #87ceeb;
        color: #ffffff;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        min-width: 90px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(135, 206, 235, 0.5);
      }
      .action-button:hover {
        background-color: #5aa7db;
        transform: translateY(-2px);
        box-shadow: 0 4px 14px rgba(90, 167, 219, 0.7);
      }

      .image-filename {
  ...
  cursor: default;
}
.image-filename:hover {
  overflow: visible;
  white-space: normal;
  word-break: break-all;
  background-color: rgba(255,255,255,0.95);
  z-index: 10;
  position: relative;
  padding: 6px;
  border-radius: 6px;
}

      
    }
  </style>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
  <h1>Saved Images for <?php echo htmlspecialchars($username); ?></h1>
  <div class="gallery">
  <?php foreach ($imageFiles as $img): ?>
    <div>
  <img src="<?php echo htmlspecialchars($uploadDir . $img); ?>" alt="<?php echo htmlspecialchars($img); ?>">

  <span class="image-filename"><?php echo htmlspecialchars($img); ?></span>

  <form method="POST" aria-label="Actions for image <?php echo htmlspecialchars($img); ?>">
    <input type="hidden" name="image" value="<?php echo htmlspecialchars($img); ?>">
    <a href="editor.php?image=<?php echo urlencode($img); ?>" style="background-color: #87ceeb; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block;">Edit</a>
    
    <button type="submit" name="action" value="download">Download</button>
    <button type="submit" name="action" value="delete" onclick="return confirm('Are you sure you want to delete this image?');">Delete</button>
  </form>

  <form method="POST" style="margin-top: 10px;">
    <input type="hidden" name="rename_image" value="<?php echo htmlspecialchars($img); ?>">
<input type="text" name="new_name" ... style="padding:6px; border-radius:6px; border:1px solid #ccc; width: 90%; max-width: 280px;">
    <button type="submit" style="padding: 6px 12px; margin-left: 6px; background-color: #87ceeb; color: white; border: none; border-radius: 6px;">Rename</button>
  </form>
</div>

  <?php endforeach; ?>
  </div>

  <button onclick="window.location.href='drive.php'" aria-label="Back to Drive">Back to Drive</button>
</body>
</html>




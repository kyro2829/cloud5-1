<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'db.php';              // <-- $pdo (Supabase Postgres) lives here

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';

/* ------------------------------------------------------------------
   1.  Storage paths (local file system)
-------------------------------------------------------------------*/
$baseStorageDir = './uploads/';
$uploadDir      = $baseStorageDir . $userId . '/';
$docDir         = $uploadDir . 'documents/';
$imageDir       = $uploadDir . 'images/';
@mkdir($docDir,   0755, true);
@mkdir($imageDir, 0755, true);

/* ------------------------------------------------------------------
   2.  RENAME document
-------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['original_file_name'], $_POST['new_document_name'])) {

    $originalFileName = $_POST['original_file_name'];
    $newName          = trim($_POST['new_document_name']);

    if ($newName !== '') {
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM uploaded_documents
                 WHERE file_name = ? AND user_id = ?'
            );
            $stmt->execute([$originalFileName, $userId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                $oldPath      = $doc['file_path'];
                $ext          = pathinfo($oldPath, PATHINFO_EXTENSION);
                $safeNewName  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $newName);
                $newFileName  = $safeNewName . '.' . $ext;
                $newPath      = dirname($oldPath) . '/' . $newFileName;

                if (rename($oldPath, $newPath)) {
                    $upd = $pdo->prepare(
                        'UPDATE uploaded_documents
                         SET file_name = ?, file_path = ?
                         WHERE file_name = ? AND user_id = ?'
                    );
                    $upd->execute([$newFileName, $newPath, $originalFileName, $userId]);
                }
            }

            header('Location: drive.php');
            exit();
        } catch (Exception $e) {
            error_log('Rename failed: ' . $e->getMessage());
        }
    }
}

/* ------------------------------------------------------------------
   3.  DELETE document
-------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    $docId = (int) $_POST['delete_document'];

    try {
        // Fetch path so we can unlink the file
        $stmt = $pdo->prepare(
            'SELECT file_path FROM uploaded_documents WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$docId, $userId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($doc && file_exists($doc['file_path'])) {
            unlink($doc['file_path']);
        }

        // Delete DB row
        $del = $pdo->prepare(
            'DELETE FROM uploaded_documents WHERE id = ? AND user_id = ?'
        );
        $del->execute([$docId, $userId]);

        header('Location: drive.php');
        exit();
    } catch (Exception $e) {
        error_log('Delete failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
   4.  Helper: normalise multiple-file array
-------------------------------------------------------------------*/
function restructureFilesArray(array $files): array
{
    $out = [];
    if (is_array($files['name'])) {
        foreach ($files['name'] as $i => $n) {
            $out[] = [
                'name'     => $n,
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
        }
    } else {
        $out[] = $files;
    }
    return $out;
}

/* ------------------------------------------------------------------
   5.  UPLOAD handler
-------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uploaded_files'])) {

    $allowed   = ['jpg','jpeg','png','gif','webp','bmp','svg','pdf','docx','txt'];
    $docsOnly  = ['pdf','docx','txt'];
    $imgsOnly  = ['jpg','jpeg','png','gif','webp','bmp','svg'];

    $files     = restructureFilesArray($_FILES['uploaded_files']);
    $response  = ['success' => false, 'uploaded' => 0, 'errors' => []];

    foreach ($files as $file) {
        $name = $file['name'];
        $tmp  = $file['tmp_name'];
        $size = $file['size'];
        $err  = $file['error'];

        if ($err !== UPLOAD_ERR_OK) {
            $response['errors'][] = "$name upload error.";
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $response['errors'][] = "$name skipped (unsupported format)";
            continue;
        }

        /* MIME check */
        $mime = mime_content_type($tmp);
        $okMime = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp'  => ['image/bmp'],
            'svg'  => ['image/svg+xml'],
            'pdf'  => ['application/pdf'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/msword'
            ],
            'txt'  => ['text/plain','application/octet-stream'],
        ];
        if (!isset($okMime[$ext]) || !in_array($mime, $okMime[$ext], true)) {
            $response['errors'][] = "$name invalid mime type ($mime)";
            continue;
        }

        /* Size limits */
        if (in_array($ext, $docsOnly, true)  && $size > 10 * 1024 * 1024) {
            $response['errors'][] = "$name too large (max 10 MB)";
            continue;
        }
        if (in_array($ext, $imgsOnly, true) && $size > 5  * 1024 * 1024) {
            $response['errors'][] = "$name too large (max 5 MB)";
            continue;
        }

        /* Build safe filename + target path */
        $safe     = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($name, PATHINFO_FILENAME));
        $final    = "{$safe}_" . time() . '_' . uniqid() . ".$ext";
        $target   = in_array($ext, $docsOnly, true) ? $docDir : $imageDir;
        $fullPath = $target . $final;

        /* Insert DB record */
        $table = in_array($ext, $docsOnly, true) ? 'uploaded_documents' : 'uploaded_images';
        $stmt  = $pdo->prepare(
            "INSERT INTO {$table} (user_id, file_name, file_path, file_type, uploaded_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$userId, $final, $fullPath, $ext]);

        /* Move file */
        if (move_uploaded_file($tmp, $fullPath)) {
            chmod($fullPath, 0644);
            $response['uploaded']++;
        } else {
            $response['errors'][] = "Failed to save $name.";
        }
    }

    $response['success'] = $response['uploaded'] > 0;
    $response['message'] = $response['success'] ? 'Upload successful.' : 'No files uploaded.';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

/* ------------------------------------------------------------------
   6.  Build file list for UI (images only here)
-------------------------------------------------------------------*/
$files = [];
try {
    $stmt = $pdo->prepare(
        'SELECT file_name, file_path, file_type, uploaded_at
         FROM uploaded_images
         WHERE user_id = ?
         ORDER BY uploaded_at DESC'
    );
    $stmt->execute([$userId]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($images as $img) {
        if (file_exists($img['file_path'])) {
            $files[] = [
                'name'     => $img['file_name'],
                'path'     => $img['file_path'],
                'type'     => 'image',
                'size'     => filesize($img['file_path']),
                'modified' => strtotime($img['uploaded_at']),
            ];
        }
    }
} catch (Exception $e) {
    error_log('Database error: ' . $e->getMessage());
}
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Drive | CloudDrive</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap');
        :root {
            --white: #ffffff;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --primary-500: #0ea5e9;
            --primary-600: #0c87c9;
            --success: #10b981;
            --error: #ef4444;
            --border-radius: 0.75rem;
            --max-content-width: 1200px;
            --transition-speed: 0.3s;
            --shadow-light: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-medium: 0 4px 6px rgba(0,0,0,0.1);
            --font-family: 'Poppins', sans-serif;
        }
       
        *, *::before, *::after {
            box-sizing: border-box;
        }
       
        body {
            margin: 0;
            font-family: var(--font-family);
            background: var(--white);
            color: var(--gray-700);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
       
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
       
        header {
            position: sticky;
            top: 0;
            background: var(--white);
            box-shadow: var(--shadow-light);
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--gray-200);
        }
       
        header .logo {
            font-weight: 800;
            font-size: 1.5rem;
            color: var(--primary-500);
            user-select: none;
            letter-spacing: 0.1em;
            cursor: default;
        }
       
        header .user-info {
            font-size: 0.9rem;
            color: var(--gray-500);
            margin-right: auto;
            margin-left: 2rem;
        }
       
        header nav {
            display: flex;
            gap: 1rem;
        }
       
        header nav button {
            font-weight: 600;
            font-size: 1rem;
            background: none;
            border: none;
            color: var(--primary-500);
            cursor: pointer;
            padding: 0.4rem 0.8rem;
            border-radius: var(--border-radius);
            transition: background-color var(--transition-speed);
        }
       
        header nav button:hover,
        header nav button:focus {
            background-color: var(--primary-600);
            color: var(--white);
            outline: none;
        }
       
        main {
            flex-grow: 1;
            max-width: var(--max-content-width);
            margin: 2rem auto 3rem;
            padding: 0 2rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
       
        main h1 {
            font-size: 3rem;
            font-weight: 800;
            margin: 0 0 1.5rem;
            user-select: none;
            color: var(--primary-500);
            text-align: center;
        }
       
        #upload-area {
            border: 3px dashed var(--primary-500);
            border-radius: var(--border-radius);
            background: var(--gray-100);
            padding: 2rem;
            text-align: center;
            font-weight: 600;
            font-size: 1.25rem;
            color: var(--primary-500);
            cursor: pointer;
            transition: all var(--transition-speed);
            user-select: none;
            outline-offset: 4px;
            position: relative;
        }
       
        #upload-area:hover,
        #upload-area:focus {
            background-color: #d9f0ff;
            transform: translateY(-2px);
        }
       
        #upload-area.drag-over {
            background-color: #d9f0ff;
            border-color: var(--primary-600);
            transform: scale(1.02);
        }
       
        #upload-area.uploading {
            pointer-events: none;
            opacity: 0.7;
        }
       
        #file-input {
            display: none;
        }
        /* Document card styling */
        .document-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
            transition: box-shadow var(--transition-speed), transform var(--transition-speed);
        }
        .document-card:hover,
        .document-card:focus-within {
            box-shadow: 0 8px 30px rgba(14, 165, 233, 0.18);
            transform: translateY(-2px);
        }
        .document-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .document-title {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 1rem;
            margin-bottom: 0.2rem;
            word-break: break-all;
        }
        .document-meta {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin-bottom: 0.5rem;
        }
        .document-actions {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        .document-actions a {
            color: var(--primary-500);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: color var(--transition-speed);
        }
        .document-actions a:hover,
        .document-actions a:focus {
            color: var(--primary-600);
            text-decoration: underline;
        }
        .image-card {
            cursor: pointer;
        }
       
        .upload-progress {
            margin-top: 1rem;
            display: none;
        }
       
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }
       
        .progress-fill {
            height: 100%;
            background: var(--primary-500);
            width: 0%;
            transition: width 0.3s ease;
        }
       
        #gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
            min-height: 200px;
        }
       
        .image-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            overflow: hidden;
            cursor: pointer;
            user-select: none;
            display: flex;
            flex-direction: column;
            transition: all var(--transition-speed);
            outline-offset: 2px;
            text-decoration: none;
            color: inherit;
        }
       
        .image-card:hover,
        .image-card:focus {
            box-shadow: 0 8px 30px rgba(14, 165, 233, 0.3);
            transform: translateY(-4px);
            outline: 2px solid var(--primary-500);
        }
       
        .image-card img {
            width: 100%;
            max-width: 100%;
            height: auto;
            max-height: 300px;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            border-bottom: 1px solid var(--gray-200);
            user-select: none;
        }
       
        .image-info {
            padding: 1rem;
            background: var(--white);
        }
       
        .image-card .filename {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--gray-700);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 0.5rem;
        }
       
        .image-meta {
            font-size: 0.75rem;
            color: var(--gray-500);
            display: flex;
            justify-content: space-between;
        }
       
        .upload-status {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary-500);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            z-index: 1001;
            display: none;
            max-width: 300px;
        }
       
        .upload-status.show {
            display: block;
            animation: slideIn 0.3s ease;
        }
       
        .upload-status.error {
            background: var(--error);
        }
       
        .upload-status.success {
            background: var(--success);
        }
       
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
       
        .no-images {
            grid-column: 1 / -1;
            text-align: center;
            color: var(--gray-500);
            font-style: italic;
            padding: 3rem;
            background: var(--gray-100);
            border-radius: var(--border-radius);
        }
       
        @media (max-width: 768px) {
            header {
                padding: 1rem;
                flex-wrap: wrap;
            }
           
            header .user-info {
                margin: 0;
                order: 3;
                width: 100%;
                text-align: center;
                margin-top: 0.5rem;
            }
           
            main {
                padding: 0 1rem;
            }
           
            main h1 {
                font-size: 2rem;
            }
           
            #gallery {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <script>window.currentUser = '<?php echo $userName; ?>';</script>
    <div id="upload-status" class="upload-status"></div>

<header role="banner">
    
    <div class="logo" aria-label="Cloud5" tabindex="0">Cloud5</div>
    <div class="user-info">Welcome, <?php echo $userName; ?></div>
    <nav role="navigation" aria-label="Primary Navigation">
        <button id="saved-images-btn" title="Saved Images" aria-label="Saved Images"
            style="font-weight: 600; font-size: 1rem; background: none; border: none; color: var(--primary-500);
                   cursor: pointer; padding: 0.4rem 0.8rem; border-radius: var(--border-radius);
                   transition: background-color var(--transition-speed);"
            onmouseover="this.style.backgroundColor='var(--primary-600)'; this.style.color='var(--white)';"
            onmouseout="this.style.backgroundColor=''; this.style.color='var(--primary-500)';"
            onclick="window.location.href='saved_image.php';">
            Saved Images
        </button>
        <button id="saved-documents-btn" title="Saved Documents" aria-label="Saved Documents"
    style="font-weight: 600; font-size: 1rem; background: none; border: none; color: var(--primary-500);
           cursor: pointer; padding: 0.4rem 0.8rem; border-radius: var(--border-radius);
           transition: background-color var(--transition-speed);"
    onmouseover="this.style.backgroundColor='var(--primary-600)'; this.style.color='var(--white)';"
    onmouseout="this.style.backgroundColor=''; this.style.color='var(--primary-500)';"
    onclick="window.location.href='saved_documents.php';">
    Saved Documents
</button>

        <button id="logout-btn" title="Log out" aria-label="Log out"
            style="font-weight: 600; font-size: 1rem; background: none; border: none; color: var(--primary-500);
                   cursor: pointer; padding: 0.4rem 0.8rem; border-radius: var(--border-radius);
                   transition: background-color var(--transition-speed);"
            onmouseover="this.style.backgroundColor='var(--primary-600)'; this.style.color='var(--white)';"
            onmouseout="this.style.backgroundColor=''; this.style.color='var(--primary-500)';"
            onclick="window.location.href='logout.php';">
            Log Out
        </button>
    </nav>
</header>

    <main role="main" aria-label="Cloud Drive file management">
        <h1>My Drive</h1>

        <label id="upload-label" for="file-input" tabindex="0" aria-describedby="upload-desc" role="button"
               onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
            <div id="upload-area" aria-labelledby="upload-label upload-desc" aria-live="polite">
    <div id="upload-text">Click or drag files (images & documents) here to upload</div>
    <div class="upload-progress">
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
    </div>
    <input type="file" id="file-input" name="uploaded_files[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.svg,.pdf,.docx,.txt" />
    <span id="upload-desc" class="visually-hidden">File upload area, supports multiple image files up to 5MB each</span>
</div>
        </label>

     <section id="gallery" aria-label="Saved images gallery" aria-live="polite">
    <?php
    // Helper function to safely get file size
    function get_file_size_safe($path) {
        return (is_string($path) && file_exists($path)) ? filesize($path) : 0;
    }

    try {
        $pdo = new PDO("mysql:host=localhost;dbname=user_auth", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("SELECT file_name, file_path, file_type, uploaded_at FROM uploaded_images WHERE user_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$userId]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $images = [];
    }
    ?>

    <?php if (empty($images)): ?>
        <div class="no-images">
            <p>No images uploaded yet.</p>
            <p>Start by uploading some images above!</p>
        </div>
    <?php else: ?>
        <?php foreach ($images as $image): ?>
            <a href="editor.php?image=<?php echo urlencode($image['file_name']); ?>"
               class="image-card" tabindex="0" role="button"
               aria-label="Edit <?php echo htmlspecialchars($image['file_name']); ?>">
                <img src="<?php echo htmlspecialchars($image['file_path']); ?>"
                     alt="<?php echo htmlspecialchars($image['file_name']); ?>"
                     loading="lazy"
                     onerror="this.style.display='none'">
                <div class="image-info">
                    <div class="filename"><?php echo htmlspecialchars($image['file_name']); ?></div>
                    <div class="image-meta">
                        <span>
                            <?php
                            $size = get_file_size_safe($image['file_path']);
                            echo number_format($size / 1024, 1);
                            ?> KB
                        </span>
                        <span><?php echo date('M j, Y', strtotime($image['uploaded_at'])); ?></span>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

    <script>
        (function() {
            const fileInput = document.getElementById('file-input');
            const uploadArea = document.getElementById('upload-area');
            const uploadText = document.getElementById('upload-text');
            const uploadProgress = document.querySelector('.upload-progress');
            const progressFill = document.querySelector('.progress-fill');
            const gallery = document.getElementById('gallery');
            const logoutBtn = document.getElementById('logout-btn');
            const uploadStatus = document.getElementById('upload-status');
            const savedImagesBtn = document.getElementById('saved-images-btn');

savedImagesBtn.addEventListener('click', () => {
    window.location.href = 'saved_image.php';
});

const savedDocumentsBtn = document.getElementById('saved-documents-btn');
savedDocumentsBtn?.addEventListener('click', () => {
    window.location.href = 'saved_documents.php';
});

// Show status message
function showStatus(message, type = 'info', duration = 5000) {
    uploadStatus.innerHTML = message;
    uploadStatus.className = `upload-status show ${type}`;
    setTimeout(() => {
        uploadStatus.classList.remove('show');
    }, duration);
}

            // Handle file upload
            async function uploadFiles(files) {
                if (files.length === 0) return;

                const allowedExtensions = ['jpg','jpeg','png','gif','webp','bmp','svg','pdf','docx','txt'];
                const validFiles = [];
                const errors = [];

                for (let file of files) {
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!allowedExtensions.includes(ext)) {
                        errors.push(`${file.name} invalid file type`);
                        continue;
                    }

                    if (['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext) && file.size > 5*1024*1024) {
                        errors.push(`${file.name} too large (max 5MB)`);
                        continue;
                    }

                    if (['pdf','docx','txt'].includes(ext) && file.size > 10*1024*1024) {
                        errors.push(`${file.name} too large (max 10MB)`);
                        continue;
                    }

                    validFiles.push(file);
                }

                if (errors.length > 0) {
                    showStatus("Validation failed:<br>• " + errors.join("<br>• "), 'error', 7000);
                }

                if (validFiles.length === 0) return;

                uploadArea.classList.add('uploading');
                uploadProgress.style.display = 'block';
                uploadText.textContent = `Uploading ${validFiles.length} file(s)...`;
                progressFill.style.width = '0%';

                const formData = new FormData();
                for (let file of validFiles) {
                    formData.append('uploaded_files[]', file);
                }

                try {
                    const xhr = new XMLHttpRequest();

                    xhr.upload.addEventListener('progress', (e) => {
                        if (e.lengthComputable) {
                            const percentComplete = (e.loaded / e.total) * 100;
                            progressFill.style.width = percentComplete + '%';
                        }
                    });

                    xhr.onload = function() {
                        uploadArea.classList.remove('uploading');
                        uploadProgress.style.display = 'none';
                        uploadText.textContent = 'Click or drag files to upload';

                        if (xhr.status === 200) {
                            const result = JSON.parse(xhr.responseText);
                            if (result.success) {
                                showStatus(result.message, 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                let msg = result.message + "<br>• " + result.errors.join("<br>• ");
                                showStatus(msg, 'error');
                            }
                        } else {
                            showStatus("Server error: " + xhr.statusText, 'error');
                        }
                    };

                    xhr.open('POST', window.location.pathname);
                    xhr.send(formData);

                } catch (err) {
                    showStatus("Upload failed: " + err.message, 'error');
                }
            }

            // File input change handler
            fileInput.addEventListener('change', (e) => {
                const files = Array.from(e.target.files);
                uploadFiles(files);
                e.target.value = ''; // Reset input
            });

            // Click handler for upload area
            uploadArea.addEventListener('click', () => {
                fileInput.click();
            });

            // Drag and drop handlers
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('drag-over');
            });

            uploadArea.addEventListener('dragleave', (e) => {
                e.preventDefault();
                if (!uploadArea.contains(e.relatedTarget)) {
                    uploadArea.classList.remove('drag-over');
                }
            });

            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('drag-over');
               
                if (!e.dataTransfer.files || e.dataTransfer.files.length === 0) {
                    showStatus('No files detected. Please drop image files from your computer.', 'error');
                    return;
                }
               
                const files = Array.from(e.dataTransfer.files);
                uploadFiles(files);
            });

            // Navigation handlers
            logoutBtn.addEventListener('click', () => {
                if (confirm('Are you sure you want to log out?')) {
                    fetch('logout.php', { method: 'POST' })
                        .then(() => {
                            window.location.href = 'logout.php';
                        })
                        .catch(err => {
                            console.error('Logout error:', err);
                            alert('Failed to log out. Please try again.');
                        });
                }
            });

            // Keyboard navigation for image cards
            document.addEventListener('keydown', (e) => {
                if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('image-card')) {
                    e.preventDefault();
                    const href = e.target.getAttribute('href');
                    if (href && href.startsWith('editor.php')) {
                        window.location.href = href;
                    }
                }
            });

        })();
    </script>

<section id="documents-section" style="margin-top:3rem;">

  <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1.2rem;">
    <?php
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=user_auth", "root", "");
        $stmt = $pdo->prepare("SELECT * FROM uploaded_documents WHERE user_id = ?");
        $stmt->execute([$userId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($documents)) {
            echo "<div style='grid-column:1/-1;text-align:center;color:gray;'>No documents uploaded yet.</div>";
        } else {
            foreach ($documents as $doc) {
                $icon = match ($doc['file_type']) {
                    'pdf' => '📰',
                    'docx' => '📝',
                    'txt' => '📃',
                    default => '📄'
                };
            ?>
<div style='background:white; border-radius:12px; padding:1.5rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); min-width:280px;'>
  <div style='font-size:2rem;'><?php echo $icon; ?></div>
  <div style='font-weight:600; color:#333; margin-top:0.5rem;'><?php echo htmlspecialchars($doc['file_name']); ?></div>
  <div style='font-size:0.9rem; color:#666;'><?php echo basename($doc['file_path']); ?></div>

  <div style='margin-top:0.8rem; display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center;'>
    <a href='doc_editor.php?file=<?php echo urlencode($doc['file_name']); ?>' style='color:#0ea5e9;'>Open</a>
    <a href='<?php echo htmlspecialchars($doc['file_path']); ?>' download style='color:#0ea5e9;'>Download</a>

    <!-- Delete Form -->
    <form method='post' action='' style='display:inline;' onsubmit="return confirm('Delete this document?');">
      <input type='hidden' name='delete_document' value='<?php echo htmlspecialchars($doc['id']); ?>'>
      <button type='submit' style='color:#ef4444; background:none; border:none; cursor:pointer; font-weight:600;'>Delete</button>
    </form>

    <!-- Rename Form -->
    <form method='post' action='' style='display:flex; gap:4px; align-items:center;'>
      <input type='hidden' name='original_file_name' value='<?php echo htmlspecialchars($doc['file_name']); ?>'>
      <input type='text' name='new_document_name' value='<?php echo htmlspecialchars(pathinfo($doc['file_name'], PATHINFO_FILENAME)); ?>' style='width:130px; font-size:0.95rem; padding:2px 4px; border:1px solid #e5e7eb; border-radius:4px;'>
      <button type='submit' style='color:#0ea5e9; background:none; border:none; cursor:pointer; font-weight:600;'>Rename</button>
    </form>
  </div>
</div>
            <?php
            }
        }
    } catch (Exception $e) {
        echo "<p style='color:red;'>Database error: " . $e->getMessage() . "</p>";
    }
    ?>
  </div>
</section>

</body>
</html>


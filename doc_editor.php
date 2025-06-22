<?php
ini_set('session.save_path', '/tmp');
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Autoload dependencies (make sure vendor directory exists)
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\Writer\HTML as HtmlWriter;
use Smalot\PdfParser\Parser;

$userId = $_SESSION['user_id'];
$baseStorageDir = './uploads/';
$uploadDir = $baseStorageDir . $userId . '/documents/';

// Validate and locate the file
$fileName = isset($_GET['file']) ? basename(urldecode($_GET['file'])) : null;
$filePath = $uploadDir . $fileName;

if (!$fileName || !file_exists($filePath)) {
    header('Location: drive.php');
    exit();
}

// Determine file type
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$editableExtensions = ['html', 'htm', 'txt', 'docx'];
$viewOnlyExtensions = ['pdf'];

$editable = in_array($extension, $editableExtensions);
$viewOnly = in_array($extension, $viewOnlyExtensions);

// Handle saving or exporting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    $updatedContent = $_POST['content'];

    // Sanitize content for XML compatibility (for DOCX export)
    function sanitizeForXml($string) {
        return preg_replace('/[^\x09\x0A\x0D\x20-\xD7FF\xE000-\xFFFD]/u', '', $string);
    }

    // Save content to file
    if (in_array($extension, ['html', 'htm', 'txt'])) {
        file_put_contents($filePath, $updatedContent);
    }

    // Save a backup as .html
    file_put_contents($filePath . '.html', $updatedContent);

    // Export to DOCX
    if (isset($_POST['export_docx'])) {
        if (!class_exists('ZipArchive')) {
            die('Error: PHP Zip extension not enabled.');
        }

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        Html::addHtml($section, sanitizeForXml($updatedContent), false, false);

        header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
        header("Content-Disposition: attachment; filename=\"" . pathinfo($fileName, PATHINFO_FILENAME) . ".docx\"");
        header("Cache-Control: max-age=0");

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save('php://output');
        exit();
    }

    // Refresh to show updated content
    header("Location: doc_editor.php?file=" . urlencode($fileName));
    exit();
}

// Load file content
$fileContent = '';
if ($editable) {
    if ($extension === 'docx') {
        $phpWord = IOFactory::load($filePath, 'Word2007');
        $htmlWriter = new HtmlWriter($phpWord);
        ob_start();
        $htmlWriter->save('php://output');
        $fileContent = ob_get_clean();
    } else {
        $fileContent = file_get_contents($filePath);
    }
} elseif ($viewOnly && $extension === 'pdf') {
    $parser = new Parser();
    $pdf = $parser->parseFile($filePath);
    $fileContent = nl2br(htmlspecialchars($pdf->getText()));
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Document Editor | CloudDrive</title>
    <script src="https://cdn.tiny.cloud/1/hmtiwuvz47az57dzsic5bj6vp7qe17suvu1jddpfsq7qjp6g/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f5f5;
        }
        .editor-wrapper {
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .editor-header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .editor-header h2 {
            margin: 0;
            font-size: 1.2rem;
        }
        .editor-actions button {
            padding: 10px 16px;
            margin-left: 10px;
            background: #0ea5e9;
            border: none;
            border-radius: 6px;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }
        .editor-actions button:hover {
            background: #0c87c9;
        }
        .editor-body {
            flex: 1;
            padding: 0;
            margin: 0;
        }
        form {
            height: 100%;
        }
        #editor {
            height: calc(100vh - 80px);
        }
    </style>
</head>
<body>
<div class="editor-wrapper">
    <div class="editor-header">
        <h2><?php echo htmlspecialchars($fileName); ?></h2>
        <div class="editor-actions">
            <?php if ($editable): ?>
            <form method="post" id="editorForm">
                <input type="hidden" name="content" id="hiddenContent">
                <button type="submit" name="save_only" onclick="return submitEditor(false)">💾 Save Document</button>
                <button type="submit" name="export_docx" onclick="return submitEditor(true)">⬇ Export to Word</button>
                <button type="button" onclick="window.location.href='drive.php'">⏪ Back to Drive</button>
            </form>
            <?php else: ?>
                <button type="button" onclick="window.location.href='drive.php'">⏪ Back to Drive</button>
            <?php endif; ?>
        </div>
    </div>


    <?php if ($editable): ?>
        <div class="editor-body">
            <textarea id="editor"><?php echo htmlspecialchars($fileContent); ?></textarea>
        </div>
    <?php elseif ($viewOnly): ?>
        <div class="editor-body" style="padding: 2rem; background: white; overflow-y: auto;">
            <?php echo $fileContent; ?>
        </div>
    <?php else: ?>
        <div class="editor-body">
            <p>This file is not editable or viewable.</p>
        </div>
    <?php endif; ?>
</div>

<?php if ($editable): ?>
<script>
    let exportMode = false;

    function submitEditor(isExport) {
        exportMode = isExport;
        const editorContent = tinymce.get("editor").getContent();
        document.getElementById("hiddenContent").value = editorContent;
        return true;
    }

    tinymce.init({
        selector: '#editor',
        height: "100%",
        menubar: false,
        plugins: 'lists link image code table fullscreen',
        toolbar: 'undo redo | formatselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image table | fullscreen code',
        branding: false,
        convert_urls: false
    });
</script>
<?php endif; ?>

</body>
</html>

<?php
ini_set('session.save_path', '/tmp');
session_start();

// Redirect if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// === Supabase PostgreSQL Connection ===
$host = 'aws-0-ap-southeast-1.pooler.supabase.com';  // IPv4 pooler host
$port = '5432';
$db   = 'postgres';
$user = 'postgres.vilzpnkkugfovvlcjwvr';             // Supabase pooler-compatible user
$pass = 'Kyro@supabase!';                 // Your actual Supabase DB password

$dsn = "pgsql:host=$host;port=$port;dbname=$db";

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// === Main Logic ===
$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';

// Rename document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['original_file_name'], $_POST['new_document_name'])) {
    $originalName = $_POST['original_file_name'];
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $safeNewName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $_POST['new_document_name']);
    $newName = $safeNewName . '.' . $ext;

    $stmt = $pdo->prepare("UPDATE uploaded_documents SET file_name = ? WHERE file_name = ? AND user_id = ?");
    $stmt->execute([$newName, $originalName, $userId]);

    // Redirect after renaming
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Search documents
$searchTerm = $_GET['search'] ?? '';
$query = "SELECT * FROM uploaded_documents WHERE user_id = ?";
$params = [$userId];

if (!empty($searchTerm)) {
    $query .= " AND file_name ILIKE ?";
    $params[] = '%' . $searchTerm . '%'; // Use ILIKE for case-insensitive search in PostgreSQL
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Saved Documents</title>
  <style>
    body { font-family: sans-serif; padding: 20px; background: #f9f9f9; }
    h1 { color: #0ea5e9; }
    form.search { margin-bottom: 1rem; }
    input[type="text"] { padding: 6px; width: 250px; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; background: white; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background-color: #e0f4ff; }
    a, button { text-decoration: none; padding: 6px 10px; font-weight: bold; border: none; cursor: pointer; }
    .edit-btn { background-color: #0ea5e9; color: white; }
    .download-btn { background-color: #10b981; color: white; }
    .delete-btn { background-color: #ef4444; color: white; }
    .rename-input { width: 150px; padding: 4px; }
  </style>
</head>
<body>
  <h1>📄 Saved Documents</h1>

  <form class="search" method="GET">
    <input type="text" name="search" placeholder="Search by name..." value="<?php echo htmlspecialchars($searchTerm); ?>">
    <button type="submit">Search</button>
  </form>

  <?php if (empty($documents)): ?>
    <p>No documents found.</p>
  <?php else: ?>
    <table>
      <tr>
        <th>File Name</th>
        <th>Actions</th>
      </tr>
      <?php foreach ($documents as $doc): ?>
      <tr>
        <td><?php echo htmlspecialchars($doc['file_name']); ?></td>
        <td>
          <a class="edit-btn" href="doc_editor.php?file=<?php echo urlencode($doc['file_name']); ?>">Edit</a>
          <a class="download-btn" href="<?php echo htmlspecialchars($doc['file_path']); ?>" download>Download</a>
          <form method="post" style="display:inline;" onsubmit="return confirm('Delete this document?');">
            <input type="hidden" name="delete_document" value="<?php echo $doc['id']; ?>">
            <button class="delete-btn" type="submit">Delete</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="original_file_name" value="<?php echo htmlspecialchars($doc['file_name']); ?>">
            <input class="rename-input" type="text" name="new_document_name" value="<?php echo htmlspecialchars(pathinfo($doc['file_name'], PATHINFO_FILENAME)); ?>">
            <button type="submit">Rename</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <br><a href="drive.php">⬅ Back to Drive</a>
</body>
</html>

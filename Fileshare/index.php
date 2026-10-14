<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// set this to YOUR user id from the users table
// 0 disables uploads until you configure it
const FILE_OWNER_ID = 0;

// outside /var/www/html so PHP files cannot execute
const FILE_STORAGE = '/var/lib/kokersi-files/';
const FILE_MAX_SIZE = 512 * 1024 * 1024; // 512 MB

function escapeHtml($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fileSizeText($bytes) {
    $bytes = (float) $bytes;
    if ($bytes >= 1024 * 1024 * 1024) return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return (int) $bytes . ' B';
}

function automaticType($mime, $name) {
    $names = [
        'application/pdf' => 'PDF',
        'application/zip' => 'ZIP',
        'application/x-7z-compressed' => '7Z',
        'application/vnd.rar' => 'RAR',
        'image/jpeg' => 'JPG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
        'image/gif' => 'GIF',
        'video/mp4' => 'MP4',
        'video/webm' => 'WEBM',
        'audio/mpeg' => 'MP3',
        'text/plain' => 'TXT'
    ];
    if (isset($names[$mime])) return $names[$mime];

    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (preg_match('/^[a-z0-9]{1,12}$/', $extension)) {
        return strtoupper($extension);
    }
    return 'FILE';
}

// everyone can browse and download; only this user may upload
$canUpload = FILE_OWNER_ID > 0
    && isset($_SESSION['user_id'])
    && (int) $_SESSION['user_id'] === FILE_OWNER_ID;

if ($canUpload && empty($_SESSION['shared_files_csrf'])) {
    $_SESSION['shared_files_csrf'] = bin2hex(random_bytes(32));
}

// DOWNLOAD: serve files through PHP, never by their storage path
$downloadId = filter_input(INPUT_GET, 'download', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);

if (isset($_GET['download'])) {
    if (!is_int($downloadId)) {
        http_response_code(400);
        exit('Invalid file ID.');
    }

    $stmt = $mysqli->prepare(
        'SELECT original_name, stored_name, file_size FROM shared_files WHERE id = ?'
    );
    $stmt->bind_param('i', $downloadId);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$file || !preg_match('/^[a-f0-9]{32}$/', $file['stored_name'])) {
        http_response_code(404);
        exit('File not found.');
    }

    $path = FILE_STORAGE . $file['stored_name'];
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('File not available.');
    }

    $name = str_replace(["\r", "\n"], '', $file['original_name']);
    $name = basename(str_replace('\\', '/', $name));

    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

$message = '';

// UPLOAD: owner only
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canUpload) {
        http_response_code(403);
        exit('Only the owner can upload files.');
    }

    if (empty($_POST) && empty($_FILES)) {
        $message = 'Upload too large for PHP. Check post_max_size in php.ini.';

    } else {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['shared_files_csrf'], $token)) {
            http_response_code(403);
            exit('Invalid CSRF token.');
        }

        
        $rawTitle = $_POST['title'] ?? '';
        $rawType = $_POST['type_label'] ?? '';
        $title = is_string($rawTitle) ? trim($rawTitle) : '';
        $customType = is_string($rawType) ? trim($rawType) : '';
        $upload = $_FILES['file'] ?? null;

        if ($title === '' || strlen($title) > 150) {
            $message = 'Enter a title (maximum 150 characters).';
        } elseif (strlen($customType) > 80) {
            $message = 'File type label is too long.';
        } elseif (!is_array($upload) || !isset($upload['error'])) {
            $message = 'Choose a file.';
        } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
            $message = 'Upload failed. Check your PHP size limits.';
        } elseif (!is_string($upload['tmp_name']) || !is_string($upload['name'])
            || !is_uploaded_file($upload['tmp_name'])) {
            $message = 'Invalid upload.';
        } elseif ($upload['size'] <= 0 || $upload['size'] > FILE_MAX_SIZE) {
            $message = 'File must be between 1 byte and 512 MB.';
        } elseif (!is_dir(FILE_STORAGE) || !is_writable(FILE_STORAGE)) {
            $message = 'Storage directory is missing or not writable.';
        } else {
            // recognize real MIME type; custom label is for display only
            $originalName = basename(str_replace('\\', '/', $upload['name']));
            $originalName = str_replace(["\r", "\n"], '', $originalName);
            $originalName = substr($originalName, 0, 255);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            if (!is_string($mime) || $mime === '') $mime = 'application/octet-stream';
            $typeLabel = $customType !== '' ? $customType : automaticType($mime, $originalName);

            // random, extensionless storage name prevents executable uploads
            $storedName = bin2hex(random_bytes(16));
            $path = FILE_STORAGE . $storedName;
            $size = (int) $upload['size'];

            if (!move_uploaded_file($upload['tmp_name'], $path)) {
                $message = 'Could not save the file.';
            } else {
                try {
                    $stmt = $mysqli->prepare(
                        'INSERT INTO shared_files
                         (title, type_label, detected_mime, original_name, stored_name, file_size)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param(
                        'sssssi', $title, $typeLabel, $mime, $originalName, $storedName, $size
                    );
                    $stmt->execute();
                    $stmt->close();

                    // redirect prevents re-upload when browser refreshes
                    header('Location: index.php?uploaded=1', true, 303);
                    exit;
                } catch (Throwable $e) {
                    unlink($path);
                    $message = 'Database error while saving the file.';
                }
            }
        }
    }
}

// sorting is server-side; only fixed column names are accepted
$sortColumns = [
    'date' => 'uploaded_at',
    'title' => 'title',
    'type' => 'type_label',
    'size' => 'file_size'
];
$sort = (string) ($_GET['sort'] ?? 'date');
if (!isset($sortColumns[$sort])) $sort = 'date';
$direction = strtolower((string) ($_GET['dir'] ?? 'desc'));
if (!in_array($direction, ['asc', 'desc'], true)) $direction = 'desc';
$orderBy = $sortColumns[$sort];
$sqlDirection = strtoupper($direction);

$result = $mysqli->query(
    "SELECT id, title, type_label, original_name, file_size, uploaded_at
     FROM shared_files ORDER BY $orderBy $sqlDirection, id DESC"
);

function sortUrl($column, $currentSort, $currentDirection) {
    $next = ($column === $currentSort && $currentDirection === 'asc') ? 'desc' : 'asc';
    return '?sort=' . $column . '&dir=' . $next;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOKERSI FILES</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .files-box { background: #a52a2a; border: 2px solid black; padding: 14px; box-sizing: border-box; }
        .files-box h2 { margin-top: 0; }
        .files-box input { max-width: 100%; box-sizing: border-box; }
        .files-table-wrap { overflow-x: auto; }
        .files-table { width: 100%; border-collapse: collapse; background: #fff; color: #000; }
        .files-table th, .files-table td { border: 1px solid #000; padding: 9px; text-align: left; }
        .files-table th { background: aquamarine; }
        .files-table a { color: #111; }
        .files-button { background: aquamarine; color: #000; border: 2px solid #000; border-radius: 0; padding: 7px 12px; cursor: pointer; }
        .files-input { padding: 6px; border: 2px solid #000; border-radius: 0; }
        .files-label { display: block; margin: 10px 0 4px; font-weight: bold; }
        .files-message { padding: 8px; border: 2px solid black; background: #ffe58f; color: black; margin-bottom: 12px; }
        .files-nav a { display: inline-block; margin: 0 8px 8px 0; padding: 6px; border: 2px solid #000; background: aquamarine; color: #000; }
        .files-table td:nth-child(1) { min-width: 160px; }
    </style>
</head>
<body>
<div class="grid-container">
    <div class="top">
        <h1>KOKERSI FILE SHARING</h1>
        <nav class="files-nav">
            <a href="../index.php">HOME</a>
            <a href="../booru/index.php">BOORU</a>
            <a href="../wiki/index.php">WIKI</a>
            <?php if (empty($_SESSION['user_id'])): ?>
                <a href="../login.php">LOGIN</a>
            <?php endif; ?>
        </nav>
    </div>

    <div class="column1">
        <div class="files-box">
            <h2>SHARED FILES</h2>
            <p>Click a column name to sort. Click DOWNLOAD to save any file.</p>

            <?php if (isset($_GET['uploaded']) && $_GET['uploaded'] === '1'): ?>
                <div class="files-message">File uploaded successfully.</div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="files-message"><?= escapeHtml($message) ?></div>
            <?php endif; ?>

            <div class="files-table-wrap">
                <table class="files-table">
                    <thead>
                        <tr>
                            <th><a href="<?= escapeHtml(sortUrl('title', $sort, $direction)) ?>">TITLE</a></th>
                            <th><a href="<?= escapeHtml(sortUrl('type', $sort, $direction)) ?>">TYPE</a></th>
                            <th><a href="<?= escapeHtml(sortUrl('size', $sort, $direction)) ?>">SIZE</a></th>
                            <th><a href="<?= escapeHtml(sortUrl('date', $sort, $direction)) ?>">DATE</a></th>
                            <th>FILE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows === 0): ?>
                            <tr><td colspan="5">No files uploaded yet.</td></tr>
                        <?php endif; ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= escapeHtml($row['title']) ?></strong></td>
                                <td><?= escapeHtml($row['type_label']) ?></td>
                                <td><?= escapeHtml(fileSizeText($row['file_size'])) ?></td>
                                <td><?= escapeHtml($row['uploaded_at']) ?></td>
                                <td><a href="?download=<?= (int) $row['id'] ?>" download>DOWNLOAD</a></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="column2">
        <div class="files-box">
            <?php if ($canUpload): ?>
                <h2>UPLOAD FILE</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= escapeHtml($_SESSION['shared_files_csrf']) ?>">

                    <label class="files-label" for="file_title">TITLE</label>
                    <input class="files-input" type="text" id="file_title" name="title" maxlength="150" required>

                    <label class="files-label" for="file_type">TYPE (OPTIONAL)</label>
                    <input class="files-input" type="text" id="file_type" name="type_label" maxlength="80" placeholder="Automatically detected">

                    <label class="files-label" for="file_upload">FILE</label>
                    <input type="file" id="file_upload" name="file" required>

                    <p>Any file type. Maximum 512 MB.</p>
                    <button class="files-button" type="submit">UPLOAD</button>
                </form>
            <?php else: ?>
                <h2>DOWNLOADS</h2>
                <p>Files are available to everyone. Uploads are restricted to the owner.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bottom">KOKERSI FILES</div>
</div>
</body>
</html>

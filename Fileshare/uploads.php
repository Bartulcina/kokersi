<?php
// /var/www/html/fileshare/uploads.php
// POST-only handler
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

const FILE_OWNER_USERNAME = 'AFTERJEUKOKERSIN';
const FILE_STORAGE = '/var/lib/kokersi-files/';
const FILE_MAX_SIZE = 512 * 1024 * 1024;
const FILE_COVER_MAX_SIZE = 5 * 1024 * 1024;

function fsUploadError(string $code): void {
    // Only internal fixed error codes are used in the URL.
    header('Location: index.php?error=' . rawurlencode($code) . '#upload-form', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('You must log in to upload.');
}

$userId = (int) $_SESSION['user_id'];
$stmt = $mysqli->prepare('SELECT username FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['username'] !== FILE_OWNER_USERNAME) {
    http_response_code(403);
    exit('Only AFTERJEUKOKERSIN can upload.');
}

// If the entire POST is empty, PHP likely rejected it due to post_max_size.
if (empty($_POST) && empty($_FILES)) {
    fsUploadError('size');
}

$token = $_POST['csrf'] ?? null;
$sessionToken = $_SESSION['shared_files_csrf'] ?? null;
if (!is_string($token) || !is_string($sessionToken) || $sessionToken === ''
    || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$rawTitle = $_POST['title'] ?? null;
$rawType = $_POST['type_label'] ?? null;
$title = is_string($rawTitle) ? trim($rawTitle) : '';
$customType = is_string($rawType) ? trim($rawType) : '';

if ($title === '' || strlen($title) > 150) fsUploadError('title');
if (strlen($customType) > 80) fsUploadError('type');

$file = $_FILES['file'] ?? null;
if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) fsUploadError('file');
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) fsUploadError('size');
if ($file['error'] !== UPLOAD_ERR_OK) fsUploadError('file');
if (!isset($file['tmp_name'], $file['name'], $file['size']) ||
    !is_string($file['tmp_name']) || !is_string($file['name']) ||
    !is_uploaded_file($file['tmp_name'])) fsUploadError('file');
if ($file['size'] < 1 || $file['size'] > FILE_MAX_SIZE) fsUploadError('size');

$cover = $_FILES['cover'] ?? null;
$hasCover = is_array($cover) && isset($cover['error']) && $cover['error'] !== UPLOAD_ERR_NO_FILE;
if ($hasCover) {
    if (is_array($cover['error']) || $cover['error'] !== UPLOAD_ERR_OK ||
        !isset($cover['tmp_name'], $cover['size']) ||
        !is_string($cover['tmp_name']) || !is_uploaded_file($cover['tmp_name']) ||
        $cover['size'] < 1 || $cover['size'] > FILE_COVER_MAX_SIZE) fsUploadError('cover');

    $coverInfo = @getimagesize($cover['tmp_name']);
    $coverMime = (new finfo(FILEINFO_MIME_TYPE))->file($cover['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if ($coverInfo === false || !isset($coverInfo['mime']) ||
        !in_array($coverMime, $allowed, true) || $coverInfo['mime'] !== $coverMime) {
        fsUploadError('cover');
    }
}

if (!is_dir(FILE_STORAGE) || !is_writable(FILE_STORAGE)) fsUploadError('storage');

$originalName = basename(str_replace('\\', '/', $file['name']));
$originalName = substr(str_replace(["\r", "\n"], '', $originalName), 0, 255);
if ($originalName === '') $originalName = 'download';

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!is_string($mime) || $mime === '') $mime = 'application/octet-stream';

$known = [
    'application/pdf' => 'PDF',
    'application/zip' => 'ZIP',
    'application/x-7z-compressed' => '7Z',
    'application/vnd.rar' => 'RAR',
    'image/jpeg' => 'JPG',
    'image/png' => 'PNG',
    'image/gif' => 'GIF',
    'image/webp' => 'WEBP',
    'video/mp4' => 'MP4',
    'video/webm' => 'WEBM',
    'audio/mpeg' => 'MP3',
    'text/plain' => 'TXT'
];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$automaticType = $known[$mime] ??
    (preg_match('/^[a-z0-9]{1,12}$/D', $ext) ? strtoupper($ext) : 'FILE');
$typeLabel = $customType !== '' ? $customType : $automaticType;

$storedName = bin2hex(random_bytes(16));
$path = FILE_STORAGE . $storedName;
$coverPath = $path . '.cover'; // optional sidecar; no database schema change required
$size = (int) $file['size'];

if (!move_uploaded_file($file['tmp_name'], $path)) fsUploadError('save');

if ($hasCover && !move_uploaded_file($cover['tmp_name'], $coverPath)) {
    unlink($path);
    fsUploadError('save');
}

try {
    $stmt = $mysqli->prepare(
        'INSERT INTO shared_files
         (title, type_label, detected_mime, original_name, stored_name, file_size)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssi', $title, $typeLabel, $mime, $originalName, $storedName, $size);
    if (!$stmt->execute()) throw new RuntimeException('Insert failed');
    $stmt->close();
    header('Location: index.php?uploaded=1', true, 303);
    exit;
} catch (Throwable $e) {
    if (is_file($path)) unlink($path);
    if (is_file($coverPath)) unlink($coverPath);
    fsUploadError('database');
}

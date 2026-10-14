<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// file sharing settings
const FILE_OWNER_USERNAME = 'AFTERJEUKOKERSIN';
const FILE_STORAGE = '/var/lib/kokersi-files/';
const FILE_MAX_SIZE = 512 * 1024 * 1024;

// only allow post requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// check login
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('You must log in to upload.');
}

// check username from database
$userId = (int) $_SESSION['user_id'];

$stmt = $mysqli->prepare(
    'SELECT username FROM users WHERE id = ?'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['username'] !== FILE_OWNER_USERNAME) {
    http_response_code(403);
    exit('Only AFTERJEUKOKERSIN can upload.');
}

// check upload size
if (empty($_POST) && empty($_FILES)) {
    http_response_code(413);
    exit('Upload too large. Check PHP limits.');
}

// check csrf token
$token = $_POST['csrf'] ?? '';
$sessionToken = $_SESSION['shared_files_csrf'] ?? '';

if (!is_string($token) || !is_string($sessionToken) ||
    $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

// get form data
$title = trim((string) ($_POST['title'] ?? ''));
$customType = trim((string) ($_POST['type_label'] ?? ''));
$file = $_FILES['file'] ?? null;

// validate title
if ($title === '' || strlen($title) > 150) {
    exit('Invalid title.');
}

if (strlen($customType) > 80) {
    exit('Type label too long.');
}

// validate file
if (!is_array($file) || !isset($file['error'])) {
    exit('No file selected.');
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    exit('Upload failed.');
}

if (!isset($file['tmp_name'], $file['name'], $file['size']) ||
    !is_string($file['tmp_name']) ||
    !is_string($file['name']) ||
    !is_uploaded_file($file['tmp_name'])) {
    exit('Invalid uploaded file.');
}

if ($file['size'] <= 0 || $file['size'] > FILE_MAX_SIZE) {
    exit('File must be between 1 byte and 512 MB.');
}

// check storage directory
if (!is_dir(FILE_STORAGE) || !is_writable(FILE_STORAGE)) {
    exit('Storage directory unavailable.');
}

// get original filename
$originalName = basename(
    str_replace('\\', '/', $file['name'])
);

$originalName = substr(
    str_replace(["\r", "\n"], '', $originalName),
    0,
    255
);

// detect file type
$mime = (new finfo(FILEINFO_MIME_TYPE))
    ->file($file['tmp_name']);

if (!is_string($mime) || $mime === '') {
    $mime = 'application/octet-stream';
}

// automatic type labels
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
    'audio/mpeg' => 'MP3',
    'text/plain' => 'TXT'
];

$ext = pathinfo($originalName, PATHINFO_EXTENSION);

$automaticType = $known[$mime] ??
    (preg_match('/^[a-z0-9]{1,12}$/i', $ext)
        ? strtoupper($ext)
        : 'FILE');

// use custom label if provided
$type = $customType !== ''
    ? $customType
    : $automaticType;

// generate random storage filename
$storedName = bin2hex(random_bytes(16));
$path = FILE_STORAGE . $storedName;
$size = (int) $file['size'];

// save file
if (!move_uploaded_file($file['tmp_name'], $path)) {
    exit('Could not save file.');
}

// save information to database
try {
    $stmt = $mysqli->prepare(
        'INSERT INTO shared_files
         (title, type_label, detected_mime,
          original_name, stored_name, file_size)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $stmt->bind_param(
        'sssssi',
        $title,
        $type,
        $mime,
        $originalName,
        $storedName,
        $size
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Insert failed.');
    }

    $stmt->close();

    // return to file sharing page
    header('Location: index.php?uploaded=1', true, 303);
    exit;

} catch (Throwable $e) {

    // remove file if database insert fails
    if (is_file($path)) {
        unlink($path);
    }

    http_response_code(500);
    exit('Database error while saving file.');
}
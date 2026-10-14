<?php
// start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

// only allow post requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// require login
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('You must log in to comment.');
}

// check csrf token
$token = $_SESSION['booru_comment_csrf'] ?? '';
$givenToken = $_POST['csrf'] ?? '';

if (!is_string($token) || !is_string($givenToken) ||
    $token === '' || !hash_equals($token, $givenToken)) {
    http_response_code(403);
    exit('Invalid form token.');
}

// get post id and comment
$postId = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);

$body = trim((string)($_POST['body'] ?? ''));

// validate comment
if (!is_int($postId) || $body === '' || strlen($body) > 4000) {
    http_response_code(400);
    exit('Invalid comment.');
}

$userId = (int)$_SESSION['user_id'];

// insert comment if post exists
$stmt = $mysqli->prepare(
    'INSERT INTO booru_comments (post_id, user_id, body)
     SELECT id, ?, ? FROM booru_posts WHERE id = ?'
);

$stmt->bind_param('isi', $userId, $body, $postId);
$stmt->execute();

$added = $stmt->affected_rows;
$stmt->close();

// check if comment was added
if ($added !== 1) {
    http_response_code(404);
    exit('Post not found.');
}

// return to post
header('Location: post.php?id=' . $postId . '#comments', true, 303);
exit;
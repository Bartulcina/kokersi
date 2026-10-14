<?php
// include this at the bottom of booru/post.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

$postId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);

if (!is_int($postId)) {
    return;
}

if (empty($_SESSION['booru_comment_csrf'])) {
    $_SESSION['booru_comment_csrf'] = bin2hex(random_bytes(32));
}

$stmt = $mysqli->prepare(
    'SELECT c.body, c.created_at, u.username
     FROM booru_comments c
     LEFT JOIN users u ON u.id = c.user_id
     WHERE c.post_id = ?
     ORDER BY c.id ASC'
);
$stmt->bind_param('i', $postId);
$stmt->execute();
$comments = $stmt->get_result();
?>

<section class="booru-comments" id="comments">
    <h2>Comments</h2>

    <?php if ($comments->num_rows === 0): ?>
        <p>No comments yet.</p>
    <?php endif; ?>

    <?php while ($comment = $comments->fetch_assoc()): ?>
        <div class="booru-comment">
            <div class="booru-comment-meta">
                <strong><?= htmlspecialchars($comment['username'] ?? 'Deleted user', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                — <?= htmlspecialchars($comment['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <div class="booru-comment-body"><?= nl2br(htmlspecialchars($comment['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
        </div>
    <?php endwhile; ?>

    <?php if (!empty($_SESSION['user_id'])): ?>
        <form method="post" action="add_comment.php">
            <input type="hidden" name="post_id" value="<?= $postId ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['booru_comment_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <textarea name="body" rows="4" maxlength="1000" required placeholder="Write a comment..."></textarea>
            <button type="submit">Post comment</button>
        </form>
    <?php else: ?>
        <p><a href="../login.php">Log in</a> to leave a comment.</p>
    <?php endif; ?>
</section>

<?php $stmt->close(); ?>

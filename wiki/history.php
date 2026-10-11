
<?php
require_once 'functions.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit('Invalid article.');
}

$stmt = $mysqli->prepare('SELECT title FROM wiki_articles WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$article = $stmt->get_result()->fetch_assoc();

if (!$article) {
    http_response_code(404);
    exit('Article not found.');
}

$stmt = $mysqli->prepare(
    'SELECT id, content, editor_id, edited_at
     FROM wiki_revisions WHERE article_id = ? ORDER BY id DESC'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$revisions = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>History - <?= wikiEscape($article['title']) ?></title>
    <link rel="stylesheet" href="wiki.css">
</head>
<body>
<header class="wiki-header">
    <h1>KOKERSI WIKI</h1>
    <nav>
        <a href="../index.php">KOKERSI</a>
        <a href="index.php">WIKI HOME</a>
        <a href="article.php?id=<?= $id ?>">ARTICLE</a>
        <a href="edit.php?id=<?= $id ?>">EDIT</a>
    </nav>
</header>

<main class="wiki-content">
    <h1>History: <?= wikiEscape($article['title']) ?></h1>
    <p>Previous article contents. Revisions are saved before each edit.</p>

    <?php if ($revisions->num_rows === 0): ?>
        <p>No previous revisions.</p>
    <?php endif; ?>

    <?php while ($revision = $revisions->fetch_assoc()): ?>
        <details class="wiki-revision">
            <summary>
                Revision #<?= (int)$revision['id'] ?> —
                <?= wikiEscape($revision['edited_at']) ?> —
                editor #<?= (int)$revision['editor_id'] ?>
            </summary>
            <pre><?= wikiEscape($revision['content']) ?></pre>
        </details>
    <?php endwhile; ?>
</main>
</body>
</html>

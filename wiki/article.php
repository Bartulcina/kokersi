
<?php
require_once 'functions.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit('Invalid article.');
}

$stmt = $mysqli->prepare('SELECT * FROM wiki_articles WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$article = $stmt->get_result()->fetch_assoc();

if (!$article) {
    http_response_code(404);
    exit('Article not found.');
}

[$notices, $content] = wikiExtractNotices($article['content'], $mysqli);
[$infobox, $content] = wikiExtractInfobox($content, $mysqli);
$body = wikiRenderBody($content, $mysqli);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= wikiEscape($article['title']) ?> - KOKERSI WIKI</title>
    <link rel="stylesheet" href="wiki.css">
</head>
<body>
<header class="wiki-header">
    <h1>KOKERSI WIKI</h1>
    <nav>
        <a href="../index.php">KOKERSI</a>
        <a href="../booru/index.php">BOORU</a>
        <a href="index.php">WIKI HOME</a>
        <a href="edit.php?id=<?= $id ?>">EDIT</a>
        <a href="history.php?id=<?= $id ?>">HISTORY</a>
    </nav>
</header>

<main class="wiki-content">
    <h1><?= wikiEscape($article['title']) ?></h1>
    <p class="wiki-meta">
        Type: <?= wikiEscape(ucfirst($article['type'])) ?> |
        Updated: <?= wikiEscape($article['updated_at']) ?>
    </p>

    <?= $notices ?>

    <article class="wiki-article">
        <?= $infobox ?>
        <?= $body ?>
    </article>
</main>
</body>
</html>

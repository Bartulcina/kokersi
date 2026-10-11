
<?php
require_once 'functions.php';
require_once __DIR__ . "/../db.php";

$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? 'all';
if (!in_array($type, ['all', 'normal', 'character', 'event'], true)) $type = 'all';

$like = '%' . $search . '%';
$stmt = $mysqli->prepare(
    "SELECT id, title, type, updated_at FROM wiki_articles
     WHERE (title LIKE ? OR content LIKE ?)
       AND (? = 'all' OR type = ?)
     ORDER BY title"
);
$stmt->bind_param('ssss', $like, $like, $type, $type);
$stmt->execute();
$articles = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KOKERSI WIKI</title>
    <link rel="stylesheet" href="wiki.css">
</head>
<body>
<header class="wiki-header">
    <h1>KOKERSI WIKI</h1>
    <nav>
        <a href="../index.php">KOKERSI</a>
        <a href="../booru/index.php">BOORU</a>
        <a href="index.php">WIKI</a>
        <a href="edit.php">CREATE ARTICLE</a>
    </nav>
</header>

<main class="wiki-content">
    <h1>All Articles</h1>

    <form method="GET" class="wiki-search">
        <input type="text" name="search" placeholder="Search articles..."
               value="<?= wikiEscape($search) ?>">
        <select name="type">
            <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
            <option value="normal" <?= $type === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="character" <?= $type === 'character' ? 'selected' : '' ?>>Characters</option>
            <option value="event" <?= $type === 'event' ? 'selected' : '' ?>>Events</option>
        </select>
        <button type="submit">Search</button>
    </form>

    <div class="wiki-links">
        <a href="index.php">All</a>
        <a href="index.php?type=character">Characters</a>
        <a href="index.php?type=event">Events</a>
    </div>

    <?php if ($articles->num_rows === 0): ?>
        <p>No articles found.</p>
    <?php else: ?>
        <ul class="wiki-list">
            <?php while ($row = $articles->fetch_assoc()): ?>
                <li>
                    <a href="article.php?id=<?= (int)$row['id'] ?>">
                        <?= wikiEscape($row['title']) ?>
                    </a>
                    <span class="wiki-meta">
                        (<?= wikiEscape($row['type']) ?>;
                        updated <?= wikiEscape($row['updated_at']) ?>)
                    </span>
                </li>
            <?php endwhile; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>

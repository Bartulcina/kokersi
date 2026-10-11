
<?php
require_once 'functions.php';

if (!isset($_SESSION['wiki_csrf'])) {
    $_SESSION['wiki_csrf'] = bin2hex(random_bytes(32));
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$title = '';
$content = '';
$type = 'normal';
$error = '';

if ($id > 0) {
    $stmt = $mysqli->prepare('SELECT title, content, type FROM wiki_articles WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $article = $stmt->get_result()->fetch_assoc();

    if (!$article) {
        http_response_code(404);
        exit('Article not found.');
    }

    $title = $article['title'];
    $content = $article['content'];
    $type = $article['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['wiki_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $type = (string)($_POST['type'] ?? 'normal');
    $userId = (int)$_SESSION['user_id'];

    if (!in_array($type, ['normal', 'character', 'event'], true)) {
        $type = 'normal';
    }

    if ($title === '' || $content === '') {
        $error = 'Enter a title and content.';
    } elseif (strlen($title) > 200) {
        $error = 'Title cannot exceed 200 bytes.';
    } elseif (strlen($content) > 250000) {
        $error = 'Article content is too long.';
    } else {
        try {
            $mysqli->begin_transaction();

            if ($id > 0) {
                // lock the article and save its previous content
                $stmt = $mysqli->prepare(
                    'SELECT content FROM wiki_articles WHERE id = ? FOR UPDATE'
                );
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                if (!$old) throw new Exception('Article not found.');

                if ($old['content'] !== $content) {
                    $stmt = $mysqli->prepare(
                        'INSERT INTO wiki_revisions (article_id, content, editor_id)
                         VALUES (?, ?, ?)'
                    );
                    $stmt->bind_param('isi', $id, $old['content'], $userId);
                    $stmt->execute();
                }

                $stmt = $mysqli->prepare(
                    'UPDATE wiki_articles SET title = ?, content = ?, type = ? WHERE id = ?'
                );
                $stmt->bind_param('sssi', $title, $content, $type, $id);
                $stmt->execute();
            } else {
                $stmt = $mysqli->prepare(
                    'INSERT INTO wiki_articles (title, content, type, author_id)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->bind_param('sssi', $title, $content, $type, $userId);
                $stmt->execute();
                $id = $mysqli->insert_id;
            }

            $mysqli->commit();
            header('Location: article.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $mysqli->rollback();
            $error = 'Could not save. The title may already be used.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $id ? 'Edit Article' : 'Create Article' ?> - KOKERSI WIKI</title>
    <link rel="stylesheet" href="wiki.css">
</head>
<body>
<header class="wiki-header">
    <h1>KOKERSI WIKI</h1>
    <nav>
        <a href="../index.php">KOKERSI</a>
        <a href="index.php">WIKI HOME</a>
        <?php if ($id): ?>
            <a href="article.php?id=<?= $id ?>">BACK TO ARTICLE</a>
        <?php endif; ?>
    </nav>
</header>

<main class="wiki-content">
    <h1><?= $id ? 'Edit Article' : 'Create Article' ?></h1>

    <?php if ($error): ?>
        <p class="wiki-error"><?= wikiEscape($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf" value="<?= wikiEscape($_SESSION['wiki_csrf']) ?>">

        <label for="article-type">Article type</label>
        <select id="article-type" name="type">
            <option value="normal" <?= $type === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="character" <?= $type === 'character' ? 'selected' : '' ?>>Character</option>
            <option value="event" <?= $type === 'event' ? 'selected' : '' ?>>Event</option>
        </select>

        <label for="article-title">Title</label>
        <input id="article-title" name="title" maxlength="200" required
               value="<?= wikiEscape($title) ?>">

        <label for="wiki-text">Content</label>
        <div class="wiki-toolbar">
            <button type="button" onclick="wrapSelection(&quot;'''&quot;, &quot;'''&quot;)">Bold</button>
            <button type="button" onclick="wrapSelection(&quot;''&quot;, &quot;''&quot;)">Italic</button>
            <button type="button" onclick="insertWiki('== Heading ==\n')">Heading</button>
            <button type="button" onclick="insertInfobox()">Insert Infobox</button>
        </div>

        <div class="wiki-toolbar">
            <select id="notice-type">
                <option value="spoiler">Spoiler</option>
                <option value="nsfw">NSFW</option>
                <option value="warning">Warning</option>
                <option value="info">Information</option>
            </select>
            <button type="button" onclick="insertNotice()">Insert Notice</button>

            <input type="number" min="1" id="booru-id" placeholder="Booru post ID">
            <button type="button" onclick="insertBooru()">Insert Booru Image</button>
        </div>

        <textarea id="wiki-text" name="content" required><?= wikiEscape($content) ?></textarea>

        <div class="wiki-actions">
            <button type="submit">Save Article</button>
            <a class="wiki-button" href="<?= $id ? 'article.php?id=' . $id : 'index.php' ?>">Cancel</a>
        </div>
    </form>

    <h2>Wiki syntax</h2>
    <pre class="wiki-help">'''bold'''     ''italic''     == Heading ==
[[Other Article]]     [[Other Article|Link label]]
[[booru:15|Image caption]]
{{notice|spoiler|This article contains spoilers.}}
{{notice|nsfw|This article contains NSFW content.|15}}
{{notice|custom|Important|Your custom message.|15}}

{{character
name=Character A
image=15
caption=Character portrait
born=2001
affiliation=KOKERSI
status=Active
}}</pre>
</main>

<script>
function insertWiki(text) {
    let area = document.querySelector('#wiki-text');
    let start = area.selectionStart;
    area.setRangeText(text, start, area.selectionEnd, 'end');
    area.focus();
}

function wrapSelection(before, after) {
    let area = document.querySelector('#wiki-text');
    let start = area.selectionStart;
    let end = area.selectionEnd;
    let text = area.value.substring(start, end) || 'text';
    area.setRangeText(before + text + after, start, end, 'select');
    area.focus();
}

function insertBooru() {
    let id = document.querySelector('#booru-id').value;
    if (!/^[1-9]\d*$/.test(id)) return;
    insertWiki('\n[[booru:' + id + '|Image caption]]\n');
}

function insertNotice() {
    let type = document.querySelector('#notice-type').value;
    insertWiki('{{notice|' + type + '|Write your message here.}}\n');
}

function insertInfobox() {
    let type = document.querySelector('#article-type').value;
    if (type !== 'event') type = 'character';
    let text = type === 'event'
        ? '{{event\nname=Event name\nimage=15\ncaption=Image caption\ndate=Unknown\nlocation=Unknown\nresult=Unknown\n}}\n\n'
        : '{{character\nname=Character name\nimage=15\ncaption=Image caption\nborn=Unknown\naffiliation=Unknown\nstatus=Unknown\n}}\n\n';
    let area = document.querySelector('#wiki-text');
    area.setRangeText(text, 0, 0, 'end');
    area.focus();
}
</script>
</body>
</html>

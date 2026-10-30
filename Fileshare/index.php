<?php
// /var/www/html/fileshare/index.php
// Public file browser + image previews + streamed downloads.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Intentionally do not include auth.php: guests may browse and download.
require_once __DIR__ . '/../db.php';

const FILE_OWNER_USERNAME = 'AFTERJEUKOKERSIN';
const FILE_STORAGE = '/var/lib/kokersi-files/';
const FILE_COVER_MAX_SIZE = 5 * 1024 * 1024;
const FILE_IMAGE_PREVIEW_MAX_SIZE = 12 * 1024 * 1024;

function fsEscape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fsSize($bytes): string {
    $bytes = (float) $bytes;
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return (int) $bytes . ' B';
}

function fsIsImageMime($mime): bool {
    return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
}

// Check the actual bytes, not just the MIME value in the database.
function fsValidatedImageMime(string $path, int $maxBytes): ?string {
    if (!is_file($path) || !is_readable($path)) return null;
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > $maxBytes) return null;

    $info = @getimagesize($path);
    if ($info === false || !isset($info['mime']) || !fsIsImageMime($info['mime'])) return null;

    $actualMime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($actualMime !== $info['mime']) return null;

    return $actualMime;
}

function fsStoredNameIsValid($name): bool {
    return is_string($name) && preg_match('/^[a-f0-9]{32}$/D', $name) === 1;
}

// A picture is displayed if a custom cover exists, or if the file itself is a small image.
function fsHasPreview(array $row): bool {
    if (!fsStoredNameIsValid($row['stored_name'])) return false;
    if (fsValidatedImageMime(FILE_STORAGE . $row['stored_name'] . '.cover', FILE_COVER_MAX_SIZE) !== null) return true;
    return fsIsImageMime($row['detected_mime'])
        && (int) $row['file_size'] <= FILE_IMAGE_PREVIEW_MAX_SIZE;
}

// Keep uploaded files out of Apache's document root. Never expose storage filenames.
if (isset($_GET['download']) || isset($_GET['preview'])) {
    $isPreview = isset($_GET['preview']);
    $param = $isPreview ? 'preview' : 'download';
    $id = filter_var($_GET[$param], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        http_response_code(400);
        exit('Invalid file ID.');
    }

    $stmt = $mysqli->prepare(
        'SELECT original_name, stored_name, detected_mime, file_size
         FROM shared_files WHERE id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$file || !fsStoredNameIsValid($file['stored_name'])) {
        http_response_code(404);
        exit('File not found.');
    }

    $path = FILE_STORAGE . $file['stored_name'];
    if ($isPreview) {
        $coverPath = $path . '.cover';
        $previewMime = fsValidatedImageMime($coverPath, FILE_COVER_MAX_SIZE);
        if ($previewMime !== null) {
            $path = $coverPath;
        } elseif (fsIsImageMime($file['detected_mime'])) {
            $previewMime = fsValidatedImageMime($path, FILE_IMAGE_PREVIEW_MAX_SIZE);
        }
        if ($previewMime === null) {
            http_response_code(404);
            exit('Preview not available.');
        }

        header('Content-Type: ' . $previewMime);
        header('Content-Disposition: inline');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: public, max-age=86400');
    } else {
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            exit('File not available.');
        }
        $name = basename(str_replace('\\', '/', str_replace(["\r", "\n"], '', $file['original_name'])));
        if ($name === '') $name = 'download';

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, no-store');
    }

    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(0);
    $handle = fopen($path, 'rb');
    if ($handle !== false) {
        while (!feof($handle)) {
            echo fread($handle, 1024 * 1024);
            if (connection_aborted()) break;
        }
        fclose($handle);
    }
    exit;
}

// Upload permission matches uploads.php: user name, not a hardcoded numeric ID.
$canUpload = false;
if (!empty($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    $stmt = $mysqli->prepare('SELECT username FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $canUpload = $user && $user['username'] === FILE_OWNER_USERNAME;
}
if ($canUpload && empty($_SESSION['shared_files_csrf'])) {
    $_SESSION['shared_files_csrf'] = bin2hex(random_bytes(32));
}

$uploadErrors = [
    'size' => 'The file was too large. Check the 512 MB limit and your PHP upload settings.',
    'title' => 'Enter a title (maximum 150 bytes).',
    'type' => 'Type label is too long.',
    'file' => 'Select a valid file.',
    'cover' => 'Cover image must be JPG, PNG, GIF or WEBP and no larger than 5 MB.',
    'storage' => 'Upload storage is unavailable. Check server permissions.',
    'save' => 'Could not save your file.',
    'database' => 'Database error while saving the file.'
];
$error = (string) ($_GET['error'] ?? '');

$sortColumns = [
    'date' => 'uploaded_at',
    'title' => 'title',
    'type' => 'type_label',
    'size' => 'file_size'
];
$sort = (string) ($_GET['sort'] ?? 'date');
if (!isset($sortColumns[$sort])) $sort = 'date';
$direction = (string) ($_GET['dir'] ?? 'desc');
if (!in_array($direction, ['asc', 'desc'], true)) $direction = 'desc';

// Whitelisted SQL column and direction; no user-controlled SQL identifiers.
$orderBy = $sortColumns[$sort];
$sqlDirection = strtoupper($direction);
$result = $mysqli->query(
    "SELECT id, title, type_label, detected_mime, original_name, stored_name,
            file_size, uploaded_at
     FROM shared_files ORDER BY $orderBy $sqlDirection, id DESC"
);
$totalFiles = $result->num_rows;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOKERSI FILES</title>
    <!-- Same shared stylesheet as the rest of Kokersi. -->
    <link rel="stylesheet" href="../style.css">
    <style>
        /* Fileshare-specific styling only. The site's .top, .bottom,
           .grid-container, .column1 and .column2 remain in ../style.css. */
        .files-page .column1,
        .files-page .column2 { min-width: 0; }

        .files-page .files-box {
            box-sizing: border-box;
            min-width: 0;
            padding: 14px;
            border: 2px solid black;
            background: brown;
            color: aliceblue;
        }
        .files-page .files-box h2 { margin-top: 0; }
        .files-page .files-box *,
        .files-page .files-box *::before,
        .files-page .files-box *::after { box-sizing: border-box; }

        /* Original header structure preserved; only its page-specific links styled. */
        .files-page .files-nav a {
            display: inline-block;
            margin: 0 8px 8px 0;
            padding: 6px;
            border: 2px solid black;
            border-radius: 0;
            background: aquamarine;
            color: black;
            font-weight: bold;
            text-decoration: none;
        }
        .files-page .files-nav .files-upload-link { background: #336600; color: aliceblue; }

        .files-page .files-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }
        .files-page .files-heading h2 { margin: 0; }
        .files-page .files-count {
            padding: 6px 9px;
            border: 2px solid black;
            background: aquamarine;
            color: black;
            font-weight: bold;
            white-space: nowrap;
        }
        .files-page .files-help { margin: 0 0 12px; }
        .files-page .files-message {
            padding: 8px;
            border: 2px solid black;
            background: aquamarine;
            color: black;
            margin-bottom: 12px;
        }
        .files-page .files-message-error { background: #ffe58f; }

        /* Compact search/sort controls matching the site's angular buttons. */
        .files-page .files-toolbar {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 9px;
            padding: 11px;
            margin-bottom: 12px;
            border: 2px solid black;
            background: #336600;
        }
        .files-page .files-search-field { flex: 1 1 190px; }
        .files-page .files-sort-controls { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; }
        .files-page .files-sort-field { flex: 1 1 110px; }
        .files-page .files-label {
            display: block;
            margin: 0 0 5px;
            font-weight: bold;
        }
        .files-page .files-input {
            display: block;
            max-width: 100%;
            width: 100%;
            padding: 7px;
            border: 2px solid black;
            border-radius: 0;
            background: aliceblue;
            color: black;
        }
        .files-page .files-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-height: 37px;
            padding: 7px 12px;
            border: 2px solid black;
            border-radius: 0;
            background: aquamarine;
            color: black;
            font: inherit;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }

        /* Stacked file bars, one per file: image / title & metadata / download. */
        .files-page .files-list { display: flex; flex-direction: column; gap: 10px; }
        .files-page .files-item {
            display: grid;
            grid-template-columns: 120px minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            min-height: 108px;
            padding: 9px;
            border: 2px solid black;
            background: #141414;
            color: aliceblue;
        }
        .files-page .files-item[hidden],
        .files-page .files-empty[hidden] { display: none; }
        .files-page .files-cover {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 120px;
            height: 88px;
            overflow: hidden;
            border: 2px solid black;
            background: #336600;
        }
        .files-page .files-cover img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .files-page .files-placeholder {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 3px;
            width: 100%;
            height: 100%;
            color: aliceblue;
            font-weight: bold;
        }
        .files-page .files-placeholder svg { width: 33px; height: 33px; }
        .files-page .files-placeholder span {
            display: block;
            overflow: hidden;
            max-width: 100px;
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .files-page .files-description { min-width: 0; }
        .files-page .files-title {
            margin: 0 0 7px;
            color: aliceblue;
            font-size: 19px;
            overflow-wrap: anywhere;
        }
        .files-page .files-original {
            margin: 0 0 9px;
            color: #c8d2d8;
            font-size: 13px;
            overflow-wrap: anywhere;
        }
        .files-page .files-metadata { display: flex; flex-wrap: wrap; gap: 5px; }
        .files-page .files-metadata span {
            border: 1px solid black;
            padding: 4px 6px;
            background: #336600;
            color: aliceblue;
            font-size: 12px;
        }
        .files-page .files-download {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 5px;
            width: 90px;
            min-height: 68px;
            padding: 8px;
            border: 2px solid black;
            border-radius: 0;
            background: aquamarine;
            color: black;
            font-weight: bold;
            font-size: 12px;
            text-align: center;
            text-decoration: none;
        }
        .files-page .files-download svg { width: 28px; height: 28px; }
        .files-page .files-empty { padding: 15px; border: 2px solid black; background: #141414; color: aliceblue; }

        .files-page .files-side-box { margin-bottom: 12px; }
        .files-page .files-side-box p { margin: 9px 0; }
        .files-page .files-field { margin: 12px 0; }
        .files-page .files-field .files-input[type=file] { padding: 5px; font-size: 13px; }
        .files-page .files-upload-button { width: 100%; }
        .files-page .files-small { font-size: 12px; }

        /* Narrow screens: upload is near the top and bars stay readable. */
        @media (max-width: 800px) {
            .files-page .grid-container {
                grid-template-columns: minmax(0, 1fr);
                grid-template-areas: "top" "column2" "column1" "bottom";
            }
            .files-page .files-toolbar { display: block; }
            .files-page .files-search-field { margin-bottom: 9px; }
            .files-page .files-sort-controls { gap: 7px; }
        }
        @media (max-width: 520px) {
            .files-page .files-item { grid-template-columns: 82px minmax(0,1fr); gap: 8px; }
            .files-page .files-cover { width: 82px; height: 82px; }
            .files-page .files-placeholder svg { width: 25px; height: 25px; }
            .files-page .files-placeholder span { max-width: 70px; }
            .files-page .files-title { font-size: 16px; }
            .files-page .files-download { grid-column: 1 / -1; flex-direction: row; width: 100%; min-height: 38px; }
            .files-page .files-download svg { width: 19px; height: 19px; }
            .files-page .files-box { padding: 10px; }
        }
    </style>
</head>
<body class="files-page">
<div class="grid-container">
    <!-- Original Kokersi header; no custom replacement header. -->
    <div class="top">
        <h1>KOKERSI FILE SHARING</h1>
        <nav class="files-nav">
            <a href="../index.php">HOME</a>
            <a href="../booru/index.php">BOORU</a>
            <a href="../wiki/index.php">WIKI</a>
            <?php if ($canUpload): ?>
                <a class="files-upload-link" href="#upload-form">UPLOAD FILE</a>
            <?php elseif (empty($_SESSION['user_id'])): ?>
                <a href="../login.php">LOGIN</a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Main file browser stays in the site's column1. -->
    <div class="column1">
        <section class="files-box">
            <div class="files-heading">
                <h2>SHARED FILES</h2>
                <span class="files-count" id="files-count"><?= (int) $totalFiles ?> FILES</span>
            </div>
            <p class="files-help">Scroll to browse, or search for a file below.</p>

            <?php if (($_GET['uploaded'] ?? '') === '1'): ?>
                <div class="files-message" role="status">File uploaded successfully.</div>
            <?php endif; ?>
            <?php if (isset($uploadErrors[$error])): ?>
                <div class="files-message files-message-error" role="alert"><?= fsEscape($uploadErrors[$error]) ?></div>
            <?php endif; ?>

            <div class="files-toolbar">
                <div class="files-search-field">
                    <label class="files-label" for="files-search">SEARCH FILES</label>
                    <input class="files-input" type="search" id="files-search" placeholder="Title, filename, type..." autocomplete="off">
                </div>
                <form method="get" class="files-sort-controls">
                    <div class="files-sort-field">
                        <label class="files-label" for="files-sort">SORT BY</label>
                        <select class="files-input" name="sort" id="files-sort">
                            <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Date</option>
                            <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title</option>
                            <option value="type" <?= $sort === 'type' ? 'selected' : '' ?>>Type</option>
                            <option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>Size</option>
                        </select>
                    </div>
                    <div class="files-sort-field">
                        <label class="files-label" for="files-dir">ORDER</label>
                        <select class="files-input" name="dir" id="files-dir">
                            <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descending</option>
                            <option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div>
                    <button class="files-button" type="submit">SORT</button>
                </form>
            </div>

            <div class="files-list" id="files-list">
                <?php if ($totalFiles === 0): ?>
                    <p class="files-empty">No files uploaded yet.</p>
                <?php endif; ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <article class="files-item">
                        <div class="files-cover">
                            <?php if (fsHasPreview($row)): ?>
                                <img src="?preview=<?= (int) $row['id'] ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="files-placeholder" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <path d="M14 2v6h6"/><path d="M8 13h8M8 17h8"/>
                                    </svg>
                                    <span><?= fsEscape($row['type_label']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="files-description">
                            <h3 class="files-title"><?= fsEscape($row['title']) ?></h3>
                            <p class="files-original"><?= fsEscape($row['original_name']) ?></p>
                            <div class="files-metadata">
                                <span><?= fsEscape($row['type_label']) ?></span>
                                <span><?= fsEscape(fsSize($row['file_size'])) ?></span>
                                <span><?= fsEscape($row['uploaded_at']) ?></span>
                            </div>
                        </div>
                        <a class="files-download" href="?download=<?= (int) $row['id'] ?>" aria-label="Download <?= fsEscape($row['title']) ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square" aria-hidden="true">
                                <path d="M12 3v12m-5-5 5 5 5-5"/><path d="M4 17v4h16v-4"/>
                            </svg>
                            <span>DOWNLOAD</span>
                        </a>
                    </article>
                <?php endwhile; ?>
                <p class="files-empty" id="files-no-results" hidden>No matching files found.</p>
            </div>
        </section>
    </div>

    <!-- Original right-hand sidebar; uploading lives here. -->
    <div class="column2">
        <section class="files-box files-side-box" id="upload-form">
            <?php if ($canUpload): ?>
                <h2>UPLOAD FILE</h2>
                <p>Upload a file and optionally give it a cover image.</p>
                <form action="uploads.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= fsEscape($_SESSION['shared_files_csrf']) ?>">

                    <div class="files-field">
                        <label class="files-label" for="file-title">TITLE</label>
                        <input class="files-input" id="file-title" name="title" type="text" maxlength="150" required>
                    </div>
                    <div class="files-field">
                        <label class="files-label" for="file-type">TYPE (OPTIONAL)</label>
                        <input class="files-input" id="file-type" name="type_label" type="text" maxlength="80" placeholder="Auto-detected">
                    </div>
                    <div class="files-field">
                        <label class="files-label" for="file-upload">FILE</label>
                        <input class="files-input" id="file-upload" name="file" type="file" required>
                    </div>
                    <div class="files-field">
                        <label class="files-label" for="file-cover">IMAGE (OPTIONAL)</label>
                        <input class="files-input" id="file-cover" name="cover" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                    </div>
                    <p class="files-small">Any file type, up to 512 MB. Cover images: up to 5 MB.</p>
                    <button class="files-button files-upload-button" type="submit">UPLOAD</button>
                </form>
            <?php else: ?>
                <h2>DOWNLOADS</h2>
                <p>Anyone can browse and download files.</p>
                <p>Uploads are restricted to the owner.</p>
            <?php endif; ?>
        </section>
    </div>

    <!-- Original Kokersi footer. -->
    <div class="bottom">KOKERSI FILES</div>
</div>
<script>
(() => {
    const input = document.getElementById('files-search');
    const cards = Array.from(document.querySelectorAll('.files-item'));
    const counter = document.getElementById('files-count');
    const empty = document.getElementById('files-no-results');
    const searchIndex = cards.map(card => ({
        element: card,
        text: card.querySelector('.files-description').textContent.toLocaleLowerCase()
    }));
    input.addEventListener('input', () => {
        const query = input.value.trim().toLocaleLowerCase();
        let count = 0;
        for (const item of searchIndex) {
            const matches = item.text.includes(query);
            item.element.hidden = !matches;
            if (matches) count++;
        }
        counter.textContent = count + ' FILES';
        empty.hidden = count > 0 || cards.length === 0;
    });
})();
</script>
</body>
</html>

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
    if (is_file(FILE_STORAGE . $row['stored_name'] . '.cover')) return true;
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
    <title>KOKERSI FILE SHARING</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .fs-body { margin: 0; background: #111116; color: #f4f4f4; font-family: Arial, Helvetica, sans-serif; }
        .fs-body *, .fs-body *::before, .fs-body *::after { box-sizing: border-box; }
        .fs-body a { text-decoration: none; }
        .fs-header { background: blueviolet; border-bottom: 3px solid #000; padding: 18px max(18px, calc((100% - 1120px) / 2)); }
        .fs-header-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
        .fs-brand { font-size: clamp(23px, 3vw, 35px); line-height: 1.1; color: #fff; margin: 0; }
        .fs-nav { display: flex; flex-wrap: wrap; gap: 8px; }
        .fs-nav a, .fs-top-upload { display: inline-flex; align-items: center; padding: 8px 11px; border: 2px solid #111; background: aquamarine; color: #111; font-weight: bold; }
        .fs-nav .fs-top-upload { background: #ffe88c; }
        .fs-main { max-width: 1120px; padding: 22px 18px 48px; margin: 0 auto; }
        .fs-intro { display: flex; align-items: end; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; }
        .fs-intro h2 { font-size: 26px; color: #fff; margin: 0 0 6px; }
        .fs-subtitle { margin: 0; color: #ccc; }
        .fs-count { color: #151515; background: aquamarine; padding: 7px 11px; border: 2px solid #000; font-weight: bold; }
        .fs-notice { padding: 12px; color: #111; border: 2px solid #000; background: #ffe58f; margin-bottom: 16px; }
        .fs-notice.ok { background: #a7f5bf; }
        .fs-upload-panel { border: 2px solid #000; background: #7b312c; padding: 17px; margin-bottom: 22px; }
        .fs-upload-panel h3 { margin: 0 0 8px; color: #fff; font-size: 21px; }
        .fs-upload-panel p { margin: 0 0 12px; color: #f3e9e8; }
        .fs-upload-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 13px; }
        .fs-field label { display: block; font-weight: bold; margin-bottom: 5px; }
        .fs-field input { display: block; width: 100%; max-width: 100%; padding: 9px; border: 2px solid #000; border-radius: 0; background: #fff; color: #111; }
        .fs-field input[type=file] { background: #f0f0f0; padding: 8px; }
        .fs-upload-actions { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-top: 14px; }
        .fs-primary { border: 2px solid #000; background: aquamarine; color: #111; padding: 10px 16px; cursor: pointer; font-weight: bold; border-radius: 0; }
        .fs-help { font-size: 13px; color: #f4e9e9; }
        .fs-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; padding: 13px; border: 2px solid #000; background: #292734; margin: 0 0 16px; }
        .fs-toolbar label { display: block; font-size: 12px; font-weight: bold; margin-bottom: 4px; }
        .fs-toolbar input, .fs-toolbar select { height: 40px; width: 100%; border: 2px solid #111; background: #fff; color: #111; padding: 7px; border-radius: 0; }
        .fs-search-box { flex: 1 1 280px; }
        .fs-sort-box { flex: 0 1 160px; }
        .fs-apply { height: 40px; }
        .fs-list { display: flex; flex-direction: column; gap: 11px; }
        .fs-item[hidden], .fs-empty[hidden] { display: none; }
        .fs-item { display: grid; grid-template-columns: 144px minmax(0, 1fr) auto; align-items: center; gap: 16px; border: 2px solid #000; background: #ededec; color: #111; padding: 11px; min-height: 126px; }
        .fs-cover { display: flex; width: 144px; height: 100px; overflow: hidden; align-items: center; justify-content: center; border: 2px solid #191919; background: #252638; }
        .fs-cover img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .fs-placeholder { width: 100%; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 5px; color: aquamarine; font-weight: bold; }
        .fs-placeholder svg { width: 37px; height: 37px; }
        .fs-placeholder span { font-size: 11px; max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fs-item-info { min-width: 0; }
        .fs-file-title { font-size: clamp(17px, 2vw, 21px); font-weight: bold; line-height: 1.2; margin: 0 0 8px; overflow-wrap: anywhere; }
        .fs-original { font-size: 13px; color: #555; overflow-wrap: anywhere; margin-bottom: 11px; }
        .fs-metadata { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; color: #333; font-size: 12px; }
        .fs-metadata span { padding: 4px 7px; background: #d5d5d5; border: 1px solid #8a8a8a; }
        .fs-download { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; width: 112px; min-height: 81px; padding: 8px; background: aquamarine; color: #111; border: 2px solid #000; font-weight: bold; font-size: 12px; }
        .fs-download svg { height: 30px; width: 30px; }
        .fs-download:hover, .fs-primary:hover, .fs-nav a:hover { background: #d3ffe9; }
        .fs-empty { border: 2px dashed #777; padding: 24px; color: #ddd; }
        .fs-footer { padding: 15px; text-align: center; background: #282331; border-top: 2px solid #000; color: #ccc; }
        @media (max-width: 650px) {
            .fs-upload-grid { grid-template-columns: 1fr; }
            .fs-item { grid-template-columns: 92px minmax(0, 1fr); gap: 10px; padding: 9px; }
            .fs-cover { width: 92px; height: 92px; }
            .fs-download { grid-column: 1 / -1; width: 100%; min-height: 42px; flex-direction: row; }
            .fs-download svg { width: 22px; height: 22px; }
            .fs-sort-box { flex: 1 1 120px; }
        }
    </style>
</head>
<body class="fs-body">
<header class="fs-header">
    <div class="fs-header-row">
        <h1 class="fs-brand">KOKERSI FILE SHARING</h1>
        <nav class="fs-nav" aria-label="Main navigation">
            <a href="../index.php">HOME</a>
            <a href="../booru/index.php">BOORU</a>
            <a href="../wiki/index.php">WIKI</a>
            <?php if ($canUpload): ?>
                <a class="fs-top-upload" href="#upload-form">+ UPLOAD FILE</a>
            <?php elseif (empty($_SESSION['user_id'])): ?>
                <a href="../login.php">LOGIN</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="fs-main">
    <div class="fs-intro">
        <div>
            <h2>SHARED FILES</h2>
            <p class="fs-subtitle">Browse, search and download shared files.</p>
        </div>
        <span class="fs-count" id="fs-count"><?= (int) $totalFiles ?> FILES</span>
    </div>

    <?php if (($_GET['uploaded'] ?? '') === '1'): ?>
        <div class="fs-notice ok" role="status">File uploaded successfully.</div>
    <?php endif; ?>
    <?php if (isset($uploadErrors[$error])): ?>
        <div class="fs-notice" role="alert"><?= fsEscape($uploadErrors[$error]) ?></div>
    <?php endif; ?>

    <?php if ($canUpload): ?>
    <section class="fs-upload-panel" id="upload-form" aria-labelledby="fs-upload-heading">
        <h3 id="fs-upload-heading">UPLOAD NEW FILE</h3>
        <p>Only AFTERJEUKOKERSIN can upload. Everyone can download.</p>
        <form action="uploads.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= fsEscape($_SESSION['shared_files_csrf']) ?>">
            <div class="fs-upload-grid">
                <div class="fs-field">
                    <label for="fs-title">NAZIV</label>
                    <input type="text" name="title" id="fs-title" maxlength="150" required placeholder="Enter a title">
                </div>
                <div class="fs-field">
                    <label for="fs-type">LABEL</label>
                    <input type="text" name="type_label" id="fs-type" maxlength="80" placeholder="Auto-detected: PDF, ZIP, MP4...">
                </div>
                <div class="fs-field">
                    <label for="fs-file">IME DATOTEKA</label>
                    <input type="file" name="file" id="fs-file" required>
                </div>
                <div class="fs-field">
                    <label for="fs-cover">THAMBARA</label>
                    <input type="file" name="cover" id="fs-cover" accept="image/png,image/jpeg,image/webp,image/gif">
                </div>
            </div>
            <div class="fs-upload-actions">
                <button class="fs-primary" type="submit">UPLOAD</button>
                <span class="fs-help">SVI FORMATI DO 512MB DO 5 MB</span>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <div class="fs-toolbar">
        <div class="fs-search-box">
            <label for="fs-search">NADI DATOTEKU</label>
            <input id="fs-search" type="search" placeholder="Search title, filename, type..." autocomplete="off">
        </div>
        <form method="get" id="fs-sort-form" style="display:contents">
            <div class="fs-sort-box">
                <label for="fs-sort">SORTIRAJ</label>
                <select name="sort" id="fs-sort">
                    <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>datum</option>
                    <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>naziv</option>
                    <option value="type" <?= $sort === 'type' ? 'selected' : '' ?>>tip</option>
                    <option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>veličina</option>
                </select>
            </div>
            <div class="fs-sort-box">
                <label for="fs-dir">ORDER</label>
                <select name="dir" id="fs-dir">
                    <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descending</option>
                    <option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>Ascending</option>
                </select>
            </div>
            <button class="fs-primary fs-apply" type="submit">PRETRAZI</button>
        </form>
    </div>

    <section class="fs-list" id="fs-list" aria-label="Files available to download">
        <?php if ($totalFiles === 0): ?>
            <p class="fs-empty">jos nema nicega</p>
        <?php endif; ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <article class="fs-item">
                <div class="fs-cover">
                    <?php if (fsHasPreview($row)): ?>
                        <img src="?preview=<?= (int) $row['id'] ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="fs-placeholder" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8"/></svg>
                            <span><?= fsEscape($row['type_label']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="fs-item-info">
                    <h3 class="fs-file-title"><?= fsEscape($row['title']) ?></h3>
                    <div class="fs-original"><?= fsEscape($row['original_name']) ?></div>
                    <div class="fs-metadata">
                        <span><?= fsEscape($row['type_label']) ?></span>
                        <span><?= fsEscape(fsSize($row['file_size'])) ?></span>
                        <span><?= fsEscape($row['uploaded_at']) ?></span>
                    </div>
                </div>
                <a class="fs-download" href="?download=<?= (int) $row['id'] ?>" aria-label="Download <?= fsEscape($row['title']) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5"/><path d="M4 17v4h16v-4"/></svg>
                    <span>DOWNLOAD</span>
                </a>
            </article>
        <?php endwhile; ?>
        <p id="fs-no-results" class="fs-empty" hidden>nema nijedna datoteka</p>
    </section>
</main>
<footer class="fs-footer">KOKERSI FILES</footer>
<script>
(() => {
    const search = document.getElementById('fs-search');
    const cards = Array.from(document.querySelectorAll('.fs-item'));
    const counter = document.getElementById('fs-count');
    const noResults = document.getElementById('fs-no-results');
    const searchable = cards.map(card => ({
        card,
        text: card.querySelector('.fs-item-info').textContent.toLocaleLowerCase()
    }));
    search.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase();
        let shown = 0;
        searchable.forEach(({ card, text }) => {
            const match = text.includes(query);
            card.hidden = !match;
            if (match) shown++;
        });
        counter.textContent = shown + ' FILES';
        noResults.hidden = shown > 0 || cards.length === 0;
    });
})();
</script>
</body>
</html>

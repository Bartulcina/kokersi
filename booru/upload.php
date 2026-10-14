
<?php
require_once __DIR__ . '/../auth.php';
requireLogin();
require_once __DIR__ . '/../db.php';

// create csrf token
if (empty($_SESSION['booru_csrf'])) {
    $_SESSION['booru_csrf'] = bin2hex(random_bytes(32));
}

// create image thumbnails
function createThumbnail($sourcePath, $thumbPath, $mime, $width, $height)
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    switch ($mime) {
        case 'image/jpeg':
            $source = function_exists('imagecreatefromjpeg')
                ? @imagecreatefromjpeg($sourcePath) : false;
            break;
        case 'image/png':
            $source = function_exists('imagecreatefrompng')
                ? @imagecreatefrompng($sourcePath) : false;
            break;
        case 'image/webp':
            $source = function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($sourcePath) : false;
            break;
        case 'image/gif':
            $source = function_exists('imagecreatefromgif')
                ? @imagecreatefromgif($sourcePath) : false;
            break;
        default:
            return false;
    }

    if (!$source) {
        return false;
    }

    $ratio = min(300 / $width, 300 / $height, 1);
    $thumbWidth = max(1, (int) round($width * $ratio));
    $thumbHeight = max(1, (int) round($height * $ratio));
    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);

    if (!$thumb) {
        imagedestroy($source);
        return false;
    }

    // preserve transparent backgrounds
    if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $clear = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefill($thumb, 0, 0, $clear);
    }

    imagecopyresampled(
        $thumb, $source, 0, 0, 0, 0,
        $thumbWidth, $thumbHeight, $width, $height
    );

    switch ($mime) {
        case 'image/jpeg':
            $saved = function_exists('imagejpeg')
                && imagejpeg($thumb, $thumbPath, 85);
            break;
        case 'image/png':
            $saved = function_exists('imagepng')
                && imagepng($thumb, $thumbPath, 6);
            break;
        case 'image/webp':
            $saved = function_exists('imagewebp')
                && imagewebp($thumb, $thumbPath, 82);
            break;
        case 'image/gif':
            $saved = function_exists('imagegif')
                && imagegif($thumb, $thumbPath);
            break;
        default:
            $saved = false;
    }

    imagedestroy($source);
    imagedestroy($thumb);
    return $saved;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // check csrf token
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['booru_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        $message = 'No file uploaded.';

    } else {
        $file = $_FILES['image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Upload failed. Check the PHP upload limits.';

        } elseif ($file['size'] <= 0 || !is_uploaded_file($file['tmp_name'])) {
            $message = 'Invalid upload.';

        } else {
            // check real file type, not the filename extension
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif'
            ];

            $maxSize = 5 * 1024 * 1024;

            if (!isset($allowed[$mime])) {
                $message = 'Only JPG, PNG, WEBP and GIF are allowed.';

            } elseif ($file['size'] > $maxSize) {
                $message = 'Image limit is 5 MB.';

            } else {
                // validate real image and dimensions
                $imageInfo = @getimagesize($file['tmp_name']);
                $width = (int) ($imageInfo[0] ?? 0);
                $height = (int) ($imageInfo[1] ?? 0);

                if (!$imageInfo || $width <= 0 || $height <= 0) {
                    $message = 'Invalid image.';

                } else {
                    // create storage directories
                    $uploadDir = __DIR__ . '/uploads/original/';
                    $thumbDir = __DIR__ . '/uploads/thumbs/';

                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }
                    if (!is_dir($thumbDir)) {
                        @mkdir($thumbDir, 0755, true);
                    }

                    if (!is_dir($uploadDir) || !is_dir($thumbDir)) {
                        $message = 'Could not create upload directories.';

                    } else {
                        // random filename
                        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                        $destination = $uploadDir . $filename;
                        $thumbDestination = $thumbDir . $filename;

                        if (!move_uploaded_file($file['tmp_name'], $destination)) {
                            $message = 'Could not save uploaded file.';

                        } else {
                            // generate thumbnail
                            $thumbnailCreated = createThumbnail(
                                $destination, $thumbDestination, $mime, $width, $height
                            );

                            if (!$thumbnailCreated) {
                                unlink($destination);
                                $message = 'Could not create thumbnail. Check PHP GD.';

                            } else {
                                // save post and tags in one transaction
                                $transactionStarted = false;
                                try {
                                    $mysqli->begin_transaction();
                                    $transactionStarted = true;

                                    $userId = (int) $_SESSION['user_id'];
                                    $fileSize = (int) $file['size'];
                                    $originalFilename = basename(str_replace('\\', '/', $file['name']));

                                    $stmt = $mysqli->prepare(
                                        'INSERT INTO booru_posts
                                         (user_id, filename, original_filename, mime_type,
                                          file_size, width, height)
                                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                                    );
                                    $stmt->bind_param(
                                        'isssiii', $userId, $filename, $originalFilename,
                                        $mime, $fileSize, $width, $height
                                    );
                                    $stmt->execute();
                                    $postId = $mysqli->insert_id;
                                    $stmt->close();

                                    // separate tags with spaces
                                    $tagsText = strtolower(trim((string) ($_POST['tags'] ?? '')));
                                    $tags = preg_split('/\s+/', $tagsText, -1, PREG_SPLIT_NO_EMPTY);
                                    $cleanTags = [];

                                    foreach ($tags as $tag) {
                                        // keep the same tag character rules
                                        $tag = preg_replace('/[^a-z0-9_-]/', '', $tag);
                                        $tag = substr($tag, 0, 100);
                                        if ($tag !== '') {
                                            $cleanTags[$tag] = true;
                                        }
                                    }

                                    $tags = array_slice(array_keys($cleanTags), 0, 50);

                                    $insertTag = $mysqli->prepare(
                                        'INSERT IGNORE INTO booru_tags (name) VALUES (?)'
                                    );
                                    $findTag = $mysqli->prepare(
                                        'SELECT id FROM booru_tags WHERE name = ?'
                                    );
                                    $linkTag = $mysqli->prepare(
                                        'INSERT IGNORE INTO booru_post_tags (post_id, tag_id)
                                         VALUES (?, ?)'
                                    );

                                    foreach ($tags as $tag) {
                                        $insertTag->bind_param('s', $tag);
                                        $insertTag->execute();

                                        $findTag->bind_param('s', $tag);
                                        $findTag->execute();
                                        $tagRow = $findTag->get_result()->fetch_assoc();

                                        if ($tagRow) {
                                            $tagId = (int) $tagRow['id'];
                                            $linkTag->bind_param('ii', $postId, $tagId);
                                            $linkTag->execute();
                                        }
                                    }

                                    $insertTag->close();
                                    $findTag->close();
                                    $linkTag->close();

                                    $mysqli->commit();
                                    $transactionStarted = false;

                                    // reset token and open new post
                                    $_SESSION['booru_csrf'] = bin2hex(random_bytes(32));
                                    header('Location: post.php?id=' . $postId);
                                    exit;

                                } catch (Throwable $e) {
                                    if ($transactionStarted) {
                                        $mysqli->rollback();
                                    }

                                    // remove uploaded files if database save fails
                                    if (is_file($destination)) {
                                        unlink($destination);
                                    }
                                    if (is_file($thumbDestination)) {
                                        unlink($thumbDestination);
                                    }

                                    $message = 'Database error while creating post.';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload - Kokersi Booru</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="grid-container">

    <div class="top">
        <h1>UPLOAD POST</h1>

        <nav>
            <ul class="navel">
                <li><a href="index.php">BOORU</a></li>
                <li><a href="../index.php">HOME</a></li>
                <li><a href="../logout.php">LOGOUT</a></li>
            </ul>
        </nav>
    </div>

    <div class="column1">

        <?php if ($message !== ''): ?>
            <div class="message error">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form class="upload-form"
              action="upload.php"
              method="POST"
              enctype="multipart/form-data">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['booru_csrf'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="full-width">
                <strong>IMAGE</strong>
                <br><br>

                <input type="file"
                       name="image"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       required>
            </div>

            <div class="full-width">
                <strong>TAGS</strong>
                <br><br>

                <input type="text"
                       name="tags"
                       placeholder="kokersi funny outside old_server"
                       maxlength="500"
                       style="width: 100%;">

                <p class="small-text">
                    Separate tags with spaces.<br>
                    Use underscores for tags containing multiple words.
                </p>
            </div>

            <div class="full-width">
                <button type="submit">POST IMAGE</button>
            </div>

        </form>
    </div>

    <div class="column2">
        <div class="full-width">
            <h2>UPLOAD INFO</h2>

            <p>Maximum file size: <strong>5 MB</strong></p>
            <p>Allowed: JPG, PNG, WEBP, GIF</p>
            <p>Thumbnail: maximum 300 x 300</p>

            <hr>

            <p>
                Logged in as user
                #<?= (int) $_SESSION['user_id'] ?>
            </p>
        </div>
    </div>

    <div class="bottom">
        KOKERSI BOORU
    </div>

</div>
</body>
</html>

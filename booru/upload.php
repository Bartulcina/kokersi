<?php

session_start();

include "../db.php";


// --------------------------------------------------
// LOGIN REQUIRED
// --------------------------------------------------

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");

    exit;
}


// --------------------------------------------------
// CSRF TOKEN
// --------------------------------------------------

if (!isset($_SESSION["booru_csrf"])) {

    $_SESSION["booru_csrf"] =
        bin2hex(random_bytes(32));
}


// --------------------------------------------------
// THUMBNAIL FUNCTION
// --------------------------------------------------

function createThumbnail(
    $sourcePath,
    $thumbPath,
    $mime,
    $sourceWidth,
    $sourceHeight,
    $maxWidth = 300,
    $maxHeight = 300
) {

    // load original

    switch ($mime) {

        case "image/jpeg":

            if (!function_exists("imagecreatefromjpeg")) {
                return false;
            }

            $source =
                imagecreatefromjpeg($sourcePath);

            break;


        case "image/png":

            if (!function_exists("imagecreatefrompng")) {
                return false;
            }

            $source =
                imagecreatefrompng($sourcePath);

            break;


        case "image/webp":

            if (!function_exists("imagecreatefromwebp")) {
                return false;
            }

            $source =
                imagecreatefromwebp($sourcePath);

            break;


        case "image/gif":

            if (!function_exists("imagecreatefromgif")) {
                return false;
            }

            $source =
                imagecreatefromgif($sourcePath);

            break;


        default:

            return false;
    }


    if (!$source) {
        return false;
    }


    // calculate thumbnail size
    // while keeping aspect ratio

    $ratio = min(
        $maxWidth / $sourceWidth,
        $maxHeight / $sourceHeight,
        1
    );


    $thumbWidth =
        max(
            1,
            (int) round(
                $sourceWidth * $ratio
            )
        );


    $thumbHeight =
        max(
            1,
            (int) round(
                $sourceHeight * $ratio
            )
        );


    // create thumbnail canvas

    $thumb =
        imagecreatetruecolor(
            $thumbWidth,
            $thumbHeight
        );


    if (!$thumb) {

        imagedestroy($source);

        return false;
    }


    // preserve transparency

    if (
        $mime === "image/png" ||
        $mime === "image/webp" ||
        $mime === "image/gif"
    ) {

        imagealphablending(
            $thumb,
            false
        );

        imagesavealpha(
            $thumb,
            true
        );


        $transparent =
            imagecolorallocatealpha(
                $thumb,
                0,
                0,
                0,
                127
            );


        imagefill(
            $thumb,
            0,
            0,
            $transparent
        );
    }


    // resize image

    imagecopyresampled(
        $thumb,
        $source,

        0,
        0,

        0,
        0,

        $thumbWidth,
        $thumbHeight,

        $sourceWidth,
        $sourceHeight
    );


    // save thumbnail

    switch ($mime) {

        case "image/jpeg":

            $saved =
                imagejpeg(
                    $thumb,
                    $thumbPath,
                    85
                );

            break;


        case "image/png":

            $saved =
                imagepng(
                    $thumb,
                    $thumbPath,
                    6
                );

            break;


        case "image/webp":

            $saved =
                imagewebp(
                    $thumb,
                    $thumbPath,
                    82
                );

            break;


        case "image/gif":

            $saved =
                imagegif(
                    $thumb,
                    $thumbPath
                );

            break;


        default:

            $saved = false;
    }


    imagedestroy($source);

    imagedestroy($thumb);


    return $saved;
}


// --------------------------------------------------
// DEFAULT MESSAGE
// --------------------------------------------------

$message = "";


// --------------------------------------------------
// HANDLE UPLOAD
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    // ----------------------------------------------
    // CSRF CHECK
    // ----------------------------------------------

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals(
            $_SESSION["booru_csrf"],
            $_POST["csrf_token"]
        )
    ) {

        http_response_code(403);

        exit("Invalid CSRF token");
    }


    // ----------------------------------------------
    // FILE EXISTS
    // ----------------------------------------------

    if (!isset($_FILES["image"])) {

        $message = "No image uploaded.";

    } else {


        $file = $_FILES["image"];


        // ------------------------------------------
        // UPLOAD ERROR
        // ------------------------------------------

        if ($file["error"] !== UPLOAD_ERR_OK) {

            $message = "Image upload failed.";

        } else {


            // --------------------------------------
            // FILE SIZE
            // --------------------------------------

            $maxSize =
                5 * 1024 * 1024;


            if (
                $file["size"] <= 0 ||
                $file["size"] > $maxSize
            ) {

                $message =
                    "Image must be between 1 byte and 5 MB.";

            } else {


                // ----------------------------------
                // MIME TYPE
                // ----------------------------------

                $finfo =
                    new finfo(
                        FILEINFO_MIME_TYPE
                    );


                $mime =
                    $finfo->file(
                        $file["tmp_name"]
                    );


                $allowed = [

                    "image/jpeg" => "jpg",

                    "image/png" => "png",

                    "image/webp" => "webp",

                    "image/gif" => "gif"

                ];


                if (!isset($allowed[$mime])) {

                    $message =
                        "Only JPG, PNG, WEBP and GIF images are allowed.";

                } else {


                    // ------------------------------
                    // CHECK ACTUAL IMAGE
                    // ------------------------------

                    $imageInfo =
                        getimagesize(
                            $file["tmp_name"]
                        );


                    if ($imageInfo === false) {

                        $message =
                            "The uploaded file is not a valid image.";

                    } else {


                        $width =
                            (int) $imageInfo[0];

                        $height =
                            (int) $imageInfo[1];


                        if (
                            $width <= 0 ||
                            $height <= 0
                        ) {

                            $message =
                                "Invalid image dimensions.";

                        } else {


                            // ----------------------
                            // RANDOM FILENAME
                            // ----------------------

                            $extension =
                                $allowed[$mime];


                            $filename =
                                bin2hex(
                                    random_bytes(16)
                                )
                                . "."
                                . $extension;


                            // ----------------------
                            // DIRECTORIES
                            // ----------------------

                            $uploadDir =
                                __DIR__
                                . "/uploads/original/";


                            $thumbDir =
                                __DIR__
                                . "/uploads/thumbs/";


                            if (!is_dir($uploadDir)) {

                                mkdir(
                                    $uploadDir,
                                    0755,
                                    true
                                );
                            }


                            if (!is_dir($thumbDir)) {

                                mkdir(
                                    $thumbDir,
                                    0755,
                                    true
                                );
                            }


                            if (
                                !is_dir($uploadDir) ||
                                !is_dir($thumbDir)
                            ) {

                                $message =
                                    "Could not create upload directories.";

                            } else {


                                // ------------------
                                // FILE PATHS
                                // ------------------

                                $destination =
                                    $uploadDir
                                    . $filename;


                                $thumbDestination =
                                    $thumbDir
                                    . $filename;


                                // ------------------
                                // SAVE ORIGINAL
                                // ------------------

                                if (
                                    !move_uploaded_file(
                                        $file["tmp_name"],
                                        $destination
                                    )
                                ) {

                                    $message =
                                        "Could not save the uploaded image.";

                                } else {


                                    // --------------
                                    // THUMBNAIL
                                    // --------------

                                    $thumbnailCreated =
                                        createThumbnail(
                                            $destination,
                                            $thumbDestination,
                                            $mime,
                                            $width,
                                            $height
                                        );


                                    if (!$thumbnailCreated) {


                                        // remove original
                                        // if thumbnail failed

                                        if (
                                            is_file(
                                                $destination
                                            )
                                        ) {

                                            unlink(
                                                $destination
                                            );
                                        }


                                        $message =
                                            "Could not create thumbnail. Make sure PHP GD is enabled.";

                                    } else {


                                        // ----------
                                        // DATABASE
                                        // ----------

                                        try {


                                            $mysqli
                                                ->begin_transaction();


                                            // ------
                                            // POST
                                            // ------

                                            $originalFilename =
                                                basename(
                                                    $file["name"]
                                                );


                                            $stmt =
                                                $mysqli->prepare("
                                                    INSERT INTO booru_posts
                                                    (
                                                        user_id,
                                                        filename,
                                                        original_filename,
                                                        mime_type,
                                                        file_size,
                                                        width,
                                                        height
                                                    )
                                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                                ");


                                            $stmt->bind_param(
                                                "isssiii",

                                                $_SESSION["user_id"],

                                                $filename,

                                                $originalFilename,

                                                $mime,

                                                $file["size"],

                                                $width,

                                                $height
                                            );


                                            $stmt->execute();


                                            $postId =
                                                $mysqli
                                                ->insert_id;


                                            // ------
                                            // TAGS
                                            // ------

                                            $tagsText =
                                                strtolower(
                                                    trim(
                                                        $_POST["tags"]
                                                        ?? ""
                                                    )
                                                );


                                            $tags =
                                                preg_split(
                                                    '/\s+/',
                                                    $tagsText,
                                                    -1,
                                                    PREG_SPLIT_NO_EMPTY
                                                );


                                            // remove duplicates

                                            $tags =
                                                array_unique(
                                                    $tags
                                                );


                                            // max 50 tags

                                            $tags =
                                                array_slice(
                                                    $tags,
                                                    0,
                                                    50
                                                );


                                            foreach (
                                                $tags as $tag
                                            ) {


                                                $tag =
                                                    trim(
                                                        $tag
                                                    );


                                                if ($tag === "") {
                                                    continue;
                                                }


                                                // remove unwanted chars

                                                $tag =
                                                    preg_replace(
                                                        '/[^a-z0-9_-]/',
                                                        '',
                                                        $tag
                                                    );


                                                if ($tag === "") {
                                                    continue;
                                                }


                                                // max 100 chars

                                                $tag =
                                                    substr(
                                                        $tag,
                                                        0,
                                                        100
                                                    );


                                                // ------------------
                                                // INSERT TAG
                                                // ------------------

                                                $stmt =
                                                    $mysqli->prepare("
                                                        INSERT IGNORE INTO booru_tags
                                                        (name)
                                                        VALUES (?)
                                                    ");


                                                $stmt->bind_param(
                                                    "s",
                                                    $tag
                                                );


                                                $stmt
                                                    ->execute();


                                                // ------------------
                                                // FIND TAG ID
                                                // ------------------

                                                $stmt =
                                                    $mysqli->prepare("
                                                        SELECT id
                                                        FROM booru_tags
                                                        WHERE name = ?
                                                    ");


                                                $stmt->bind_param(
                                                    "s",
                                                    $tag
                                                );


                                                $stmt
                                                    ->execute();


                                                $tagRow =
                                                    $stmt
                                                    ->get_result()
                                                    ->fetch_assoc();


                                                if (!$tagRow) {
                                                    continue;
                                                }


                                                $tagId =
                                                    (int)
                                                    $tagRow["id"];


                                                // ------------------
                                                // CONNECT TAG + POST
                                                // ------------------

                                                $stmt =
                                                    $mysqli->prepare("
                                                        INSERT IGNORE
                                                        INTO booru_post_tags
                                                        (
                                                            post_id,
                                                            tag_id
                                                        )
                                                        VALUES (?, ?)
                                                    ");


                                                $stmt->bind_param(
                                                    "ii",

                                                    $postId,

                                                    $tagId
                                                );


                                                $stmt
                                                    ->execute();

                                            }


                                            // ----------------------
                                            // FINISH DATABASE
                                            // ----------------------

                                            $mysqli
                                                ->commit();


                                            // new token after success

                                            $_SESSION["booru_csrf"] =
                                                bin2hex(
                                                    random_bytes(32)
                                                );


                                            // go to post

                                            header(
                                                "Location: post.php?id="
                                                . $postId
                                            );


                                            exit;


                                        } catch (Throwable $e) {


                                            // ----------------------
                                            // DATABASE FAILED
                                            // ----------------------

                                            $mysqli
                                                ->rollback();


                                            // remove original

                                            if (
                                                is_file(
                                                    $destination
                                                )
                                            ) {

                                                unlink(
                                                    $destination
                                                );
                                            }


                                            // remove thumbnail

                                            if (
                                                is_file(
                                                    $thumbDestination
                                                )
                                            ) {

                                                unlink(
                                                    $thumbDestination
                                                );
                                            }


                                            $message =
                                                "Database error while creating post.";

                                        }

                                    }

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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Upload - Kokersi Booru
    </title>

    <link
        rel="stylesheet"
        href="../style.css"
    >

</head>


<body>
    <?php include "navbar.php"; ?>


<div class="grid-container">


    <!-- HEADER -->

    <div class="top">

        <h1>UPLOAD POST</h1>


        <nav>

            <ul class="navel">

                <li>

                    <a href="index.php">
                        BOORU
                    </a>

                </li>


                <li>

                    <a href="../index.php">
                        HOME
                    </a>

                </li>


                <li>

                    <a href="../logout.php">
                        LOGOUT
                    </a>

                </li>

            </ul>

        </nav>

    </div>



    <!-- MAIN -->

    <div class="column1">


        <?php if ($message !== ""): ?>

            <div class="message error">

                <?php
                echo htmlspecialchars(
                    $message
                );
                ?>

            </div>

        <?php endif; ?>



        <form
            class="upload-form"
            action="upload.php"
            method="POST"
            enctype="multipart/form-data"
        >


            <!-- CSRF -->

            <input
                type="hidden"
                name="csrf_token"
                value="<?php
                echo htmlspecialchars(
                    $_SESSION["booru_csrf"]
                );
                ?>"
            >



            <!-- IMAGE -->

            <div class="full-width">

                <strong>
                    IMAGE
                </strong>

                <br><br>


                <input
                    type="file"
                    name="image"
                    accept="
                        image/jpeg,
                        image/png,
                        image/webp,
                        image/gif
                    "
                    required
                >

            </div>



            <!-- TAGS -->

            <div class="full-width">

                <strong>
                    TAGS
                </strong>

                <br><br>


                <input
                    type="text"
                    name="tags"
                    placeholder="kokersi funny outside old_server"
                    maxlength="500"
                    style="width: 100%;"
                >


                <p class="small-text">

                    Separate tags with spaces.

                    <br>

                    Use underscores for tags
                    containing multiple words.

                </p>

            </div>



            <!-- BUTTON -->

            <div class="full-width">

                <button type="submit">

                    POST IMAGE

                </button>

            </div>


        </form>


    </div>



    <!-- SIDEBAR -->

    <div class="column2">


        <div class="full-width">


            <h2>
                UPLOAD INFO
            </h2>


            <p>
                Maximum file size:
                <strong>5 MB</strong>
            </p>


            <p>
                Allowed:
            </p>


            <ul>

                <li>JPG / JPEG</li>

                <li>PNG</li>

                <li>WEBP</li>

                <li>GIF</li>

            </ul>


            <p>
                Thumbnail:
                maximum 300 × 300
            </p>


            <hr>


            <p>

                Logged in as user

                #<?php
                echo (int)
                $_SESSION["user_id"];
                ?>

            </p>


        </div>


    </div>



    <!-- FOOTER -->

    <div class="bottom">

        KOKERSI BOORU

    </div>


</div>


</body>

</html>
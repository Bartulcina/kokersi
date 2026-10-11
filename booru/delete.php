<?php
require_once __DIR__ . "/../auth.php";
requireLogin();


include "../db.php";


// must be logged in

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}


// check id

if (!isset($_GET["id"]) || !ctype_digit($_GET["id"])) {
    http_response_code(400);
    exit("Invalid post ID");
}

$postId = (int) $_GET["id"];


// get post

$stmt = $mysqli->prepare("
    SELECT
        id,
        user_id,
        filename,
        original_filename
    FROM booru_posts
    WHERE id = ?
");

$stmt->bind_param("i", $postId);

$stmt->execute();

$post = $stmt
    ->get_result()
    ->fetch_assoc();


if (!$post) {
    http_response_code(404);
    exit("Post not found");
}


// ownership check

if ((int)$post["user_id"] !== (int)$_SESSION["user_id"]) {
    http_response_code(403);
    exit("You do not own this post");
}


// csrf

if (!isset($_SESSION["booru_delete_csrf"])) {

    $_SESSION["booru_delete_csrf"] =
        bin2hex(random_bytes(32));
}


// delete

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    // csrf check

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals(
            $_SESSION["booru_delete_csrf"],
            $_POST["csrf_token"]
        )
    ) {
        exit("Invalid CSRF token");
    }


    // check ownership again

    $stmt = $mysqli->prepare("
        SELECT user_id, filename
        FROM booru_posts
        WHERE id = ?
    ");

    $stmt->bind_param("i", $postId);

    $stmt->execute();

    $deletePost = $stmt
        ->get_result()
        ->fetch_assoc();


    if (!$deletePost) {
        http_response_code(404);
        exit("Post not found");
    }


    if (
        (int)$deletePost["user_id"] !==
        (int)$_SESSION["user_id"]
    ) {
        http_response_code(403);
        exit("You do not own this post");
    }


    $filename = $deletePost["filename"];


    // delete database row
    // booru_post_tags should disappear through on delete cascade

    $stmt = $mysqli->prepare("
        DELETE FROM booru_posts
        WHERE id = ?
        AND user_id = ?
    ");

    $stmt->bind_param(
        "ii",
        $postId,
        $_SESSION["user_id"]
    );

    $stmt->execute();


    // delete image file

    $filePath =
        __DIR__
        . "/uploads/original/"
        . $filename;


    if (is_file($filePath)) {
        unlink($filePath);
    }


    // if you later add thumbnails,
    // delete thumbnail here too

    $thumbPath =
        __DIR__
        . "/uploads/thumbs/"
        . $filename;

    if (is_file($thumbPath)) {
        unlink($thumbPath);
    }


    // clean unused tags

    $mysqli->query("
        DELETE FROM booru_tags
        WHERE id NOT IN (
            SELECT DISTINCT tag_id
            FROM booru_post_tags
        )
    ");


    $_SESSION["booru_delete_csrf"] =
        bin2hex(random_bytes(32));


    header("Location: index.php");

    exit;
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

    <title>Delete post</title>

    <link rel="stylesheet" href="../style.css">

</head>


<body>


<div class="grid-container">


    <div class="top">

        <h1>
            DELETE POST #<?php echo $postId; ?>
        </h1>
        <?php include "navbar.php"; ?>


    </div>


    <div class="column1">


        <div class="message warning">

            <strong>
                Are you sure you want to delete this post?
            </strong>

            <p>
                This cannot be undone.
            </p>

        </div>


        <div class="panel">

            <img
                src="uploads/original/<?php
                echo htmlspecialchars($post["filename"]);
                ?>"
                alt=""
            >

        </div>


        <form
            method="POST"
            action="delete.php?id=<?php echo $postId; ?>"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?php
                echo htmlspecialchars(
                    $_SESSION["booru_delete_csrf"]
                );
                ?>"
            >

            <button type="submit">
                YES, DELETE POST
            </button>

        </form>


    </div>


    <div class="column2">

        <div class="full-width">

            <h2>POST INFO</h2>

            <p>
                ID:
                <?php echo $post["id"]; ?>
            </p>

            <p>
                Original filename:
            </p>

            <p class="break-text">
                <?php
                echo htmlspecialchars(
                    $post["original_filename"]
                );
                ?>
            </p>

        </div>

    </div>


    <div class="bottom">

        KOKERSI BOORU

    </div>


</div>


</body>

</html>
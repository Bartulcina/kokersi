<?php

session_start();

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
    SELECT id, user_id, filename
    FROM booru_posts
    WHERE id = ?
");

$stmt->bind_param("i", $postId);

$stmt->execute();

$post = $stmt->get_result()->fetch_assoc();


if (!$post) {
    http_response_code(404);
    exit("Post not found");
}


// ownership check

if ((int)$post["user_id"] !== (int)$_SESSION["user_id"]) {
    http_response_code(403);
    exit("You do not own this post");
}


// csrf token

if (!isset($_SESSION["booru_edit_csrf"])) {
    $_SESSION["booru_edit_csrf"] = bin2hex(random_bytes(32));
}


// get current tags

$stmt = $mysqli->prepare("
    SELECT booru_tags.name
    FROM booru_tags

    INNER JOIN booru_post_tags
        ON booru_tags.id = booru_post_tags.tag_id

    WHERE booru_post_tags.post_id = ?

    ORDER BY booru_tags.name
");

$stmt->bind_param("i", $postId);

$stmt->execute();

$result = $stmt->get_result();

$currentTags = [];

while ($tag = $result->fetch_assoc()) {
    $currentTags[] = $tag["name"];
}

$tagString = implode(" ", $currentTags);

$message = "";


// update tags

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    // csrf check

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals(
            $_SESSION["booru_edit_csrf"],
            $_POST["csrf_token"]
        )
    ) {
        exit("Invalid CSRF token");
    }


    // ownership check again

    $stmt = $mysqli->prepare("
        SELECT user_id
        FROM booru_posts
        WHERE id = ?
    ");

    $stmt->bind_param("i", $postId);

    $stmt->execute();

    $checkPost = $stmt->get_result()->fetch_assoc();


    if (
        !$checkPost ||
        (int)$checkPost["user_id"] !== (int)$_SESSION["user_id"]
    ) {
        http_response_code(403);
        exit("You do not own this post");
    }


    $tagsText = strtolower(
        trim($_POST["tags"] ?? "")
    );


    $tags = preg_split('/\s+/', $tagsText);


    // remove existing tag connections

    $stmt = $mysqli->prepare("
        DELETE FROM booru_post_tags
        WHERE post_id = ?
    ");

    $stmt->bind_param("i", $postId);

    $stmt->execute();


    // add new tags

    foreach ($tags as $tag) {

        $tag = trim($tag);

        if ($tag === "") {
            continue;
        }


        // clean tag

        $tag = preg_replace(
            '/[^a-z0-9_-]/',
            '',
            $tag
        );


        if ($tag === "") {
            continue;
        }


        $tag = substr($tag, 0, 100);


        // create tag if it does not exist

        $stmt = $mysqli->prepare("
            INSERT IGNORE INTO booru_tags (name)
            VALUES (?)
        ");

        $stmt->bind_param("s", $tag);

        $stmt->execute();


        // find tag id

        $stmt = $mysqli->prepare("
            SELECT id
            FROM booru_tags
            WHERE name = ?
        ");

        $stmt->bind_param("s", $tag);

        $stmt->execute();

        $tagRow = $stmt
            ->get_result()
            ->fetch_assoc();


        if (!$tagRow) {
            continue;
        }


        $tagId = $tagRow["id"];


        // connect tag to post

        $stmt = $mysqli->prepare("
            INSERT IGNORE INTO booru_post_tags
            (post_id, tag_id)
            VALUES (?, ?)
        ");

        $stmt->bind_param(
            "ii",
            $postId,
            $tagId
        );

        $stmt->execute();
    }


    // remove tags that are no longer used anywhere

    $mysqli->query("
        DELETE FROM booru_tags
        WHERE id NOT IN (
            SELECT DISTINCT tag_id
            FROM booru_post_tags
        )
    ");


    $_SESSION["booru_edit_csrf"] =
        bin2hex(random_bytes(32));


    header(
        "Location: post.php?id=" . $postId
    );

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

    <title>Edit post</title>

    <link rel="stylesheet" href="../style.css">

</head>

<body>


<div class="grid-container">


    <div class="top">

        <h1>EDIT POST #<?php echo $postId; ?></h1>
        <?php include "navbar.php"; ?>

    </div>


    <div class="column1">


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
            action="edit.php?id=<?php echo $postId; ?>"
            class="upload-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?php
                echo htmlspecialchars(
                    $_SESSION["booru_edit_csrf"]
                );
                ?>"
            >


            <div class="full-width">

                <strong>TAGS</strong>

                <br>

                <input
                    type="text"
                    name="tags"
                    value="<?php
                    echo htmlspecialchars($tagString);
                    ?>"
                    maxlength="500"
                    style="width: 100%;"
                >

                <p class="small-text">
                    Separate tags with spaces.
                    Use underscores for multi-word tags.
                </p>

            </div>


            <button type="submit">
                SAVE CHANGES
            </button>

        </form>


    </div>


    <div class="column2">

        <div class="full-width">

            <h2>POST MANAGEMENT</h2>

            <p>
                Post #<?php echo $postId; ?>
            </p>

            <p>
                You can edit this post because
                you are its uploader.
            </p>

            <p>
                <a href="delete.php?id=<?php echo $postId; ?>">
                    DELETE POST
                </a>
            </p>

        </div>

    </div>


    <div class="bottom">

        KOKERSI BOORU

    </div>


</div>


</body>

</html>
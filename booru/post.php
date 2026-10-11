<?php

session_start();

include "../db.php";


if (!isset($_GET["id"]) || !ctype_digit($_GET["id"])) {
    http_response_code(400);
    exit("Invalid post ID");
}


$postId = (int) $_GET["id"];


/* get post */

$stmt = $mysqli->prepare("
    SELECT
        booru_posts.id,
        booru_posts.user_id,
        booru_posts.filename,
        booru_posts.original_filename,
        booru_posts.mime_type,
        booru_posts.file_size,
        booru_posts.width,
        booru_posts.height,
        booru_posts.created_at,
        users.username
    FROM booru_posts
    LEFT JOIN users
        ON booru_posts.user_id = users.id
    WHERE booru_posts.id = ?
");

$stmt->bind_param("i", $postId);

$stmt->execute();

$result = $stmt->get_result();

$post = $result->fetch_assoc();


if (!$post) {
    http_response_code(404);
    exit("Post not found");
}


/* get tags */

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

$tagResult = $stmt->get_result();

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
        Post #<?php echo $post["id"]; ?>
    </title>

    <link rel="stylesheet" href="../style.css">

</head>


<body>

<div class="grid-container">


    <div class="top">

        <h1>
            POST #<?php echo $post["id"]; ?>
        </h1>
        <?php include "navbar.php"; ?>
    </div>


    <div class="column1">

        <div class="panel">

            <a
                href="uploads/original/<?php
                echo htmlspecialchars($post["filename"]);
                ?>"
            >

                <img
                    src="uploads/original/<?php
                    echo htmlspecialchars($post["filename"]);
                    ?>"
                    alt=""
                >

            </a>

        </div>

    </div>


    <div class="column2">

        <div class="full-width">

            <h2>TAGS</h2>

            <ul class="tag-list">

                <?php while ($tag = $tagResult->fetch_assoc()): ?>

                    <li>

                        <a
                            href="index.php?tags=<?php
                            echo urlencode($tag["name"]);
                            ?>"
                        >
                            <?php
                            echo htmlspecialchars($tag["name"]);
                            ?>
                        </a>

                    </li>

                <?php endwhile; ?>

            </ul>


            <hr>


            <h2>INFO</h2>

            <p>
                ID:
                <?php echo $post["id"]; ?>
            </p>

            <p>
                Posted by:
                <?php
                echo htmlspecialchars(
                    $post["username"] ?? "unknown"
                );
                ?>
            </p>

            <p>
                Size:
                <?php echo $post["width"]; ?>
                ×
                <?php echo $post["height"]; ?>
            </p>

            <p>
                File size:
                <?php
                echo round(
                    $post["file_size"] / 1024,
                    1
                );
                ?>
                KB
            </p>

            <p>
                Uploaded:
                <?php
                echo htmlspecialchars($post["created_at"]);
                ?>
            </p>


            <?php

            $ownsPost =
                isset($_SESSION["user_id"]) &&
                $_SESSION["user_id"] == $post["user_id"];

            ?>


            <?php if ($ownsPost): ?>

                <hr>

                <p>
                    <a href="edit.php?id=<?php echo $post["id"]; ?>">
                        EDIT POST
                    </a>
                </p>

                <p>
                    <a href="delete.php?id=<?php echo $post["id"]; ?>">
                        DELETE POST
                    </a>
                </p>

            <?php endif; ?>

        </div>

    </div>


    <div class="bottom">

        Post #<?php echo $post["id"]; ?>

    </div>


</div>

</body>

</html>
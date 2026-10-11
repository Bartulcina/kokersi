<?php
require_once __DIR__ . "/../auth.php";
require_once __DIR__ . "/../db.php";


/* -------------------------
   read search tags
   ------------------------- */

$searchText = trim($_GET["tags"] ?? "");

$searchTags = [];

if ($searchText !== "") {

    $pieces = preg_split(
        '/\s+/',
        strtolower($searchText),
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    foreach ($pieces as $tag) {

        $tag = preg_replace(
            '/[^a-z0-9_-]/',
            '',
            $tag
        );

        if ($tag !== "") {
            $searchTags[] = $tag;
        }
    }

    // don't search same tag twice
    $searchTags = array_values(
        array_unique($searchTags)
    );
}


/* -------------------------
   get posts
   ------------------------- */

if (count($searchTags) === 0) {

    // normal browsing

    $stmt = $mysqli->prepare("
        SELECT
            booru_posts.id,
            booru_posts.filename,
            booru_posts.width,
            booru_posts.height,
            booru_posts.created_at,
            users.username

        FROM booru_posts

        LEFT JOIN users
            ON booru_posts.user_id = users.id

        ORDER BY booru_posts.created_at DESC

        LIMIT 100
    ");

    $stmt->execute();

} else {

    /*
     * create:
     *
     * ?, ?, ?
     *
     * depending on number of tags
     */

    $placeholders = implode(
        ",",
        array_fill(
            0,
            count($searchTags),
            "?"
        )
    );


    $sql = "
        SELECT
            booru_posts.id,
            booru_posts.filename,
            booru_posts.width,
            booru_posts.height,
            booru_posts.created_at,
            users.username

        FROM booru_posts

        INNER JOIN booru_post_tags
            ON booru_posts.id =
               booru_post_tags.post_id

        INNER JOIN booru_tags
            ON booru_post_tags.tag_id =
               booru_tags.id

        LEFT JOIN users
            ON booru_posts.user_id =
               users.id

        WHERE booru_tags.name
            IN ($placeholders)

        GROUP BY booru_posts.id

        HAVING COUNT(DISTINCT booru_tags.name) = ?

        ORDER BY booru_posts.created_at DESC

        LIMIT 100
    ";


    $stmt = $mysqli->prepare($sql);


    /*
     * parameters:
     *
     * tag1
     * tag2
     * tag3
     * number of tags
     */

    $params = $searchTags;

    $params[] = count($searchTags);


    /*
     * example:
     *
     * cat outside
     *
     * types = "ssi"
     */

    $types =
        str_repeat(
            "s",
            count($searchTags)
        )
        . "i";


    /*
     * bind_param needs references
     */

    $bindParams = [];

    $bindParams[] = $types;


    foreach ($params as $key => $value) {

        $bindParams[] =
            &$params[$key];
    }


    call_user_func_array(
        [$stmt, "bind_param"],
        $bindParams
    );


    $stmt->execute();
}


$result = $stmt->get_result();


/* -------------------------
   get popular tags
   ------------------------- */

$tagStmt = $mysqli->prepare("
    SELECT
        booru_tags.name,
        COUNT(booru_post_tags.post_id) AS post_count

    FROM booru_tags

    LEFT JOIN booru_post_tags
        ON booru_tags.id =
           booru_post_tags.tag_id

    GROUP BY booru_tags.id

    ORDER BY post_count DESC,
             booru_tags.name ASC

    LIMIT 50
");

$tagStmt->execute();

$tagResult =
    $tagStmt->get_result();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>KOKERSI BOORU</title>

    <link
        rel="stylesheet"
        href="../style.css"
    >

</head>


<body>


<div class="grid-container">


    <!-- HEADER -->

    <div class="top">

        <h1>IMAL PROBLEMA U GRUPI?</h1>

        <?php include "navbar.php"; ?>

    </div>


    <!-- POSTS -->

    <div class="column1">


        <?php if (count($searchTags) > 0): ?>

    <div class="panel">

        <strong>SEARCHING:</strong>

        <div style="margin-top: 8px;">

            <?php foreach ($searchTags as $tag): ?>

                <a
                    class="search-current-tag"
                    href="index.php?tags=<?php echo urlencode($tag); ?>"
                >
                    <?php echo htmlspecialchars($tag); ?>
                </a>

            <?php endforeach; ?>

        </div>

        <a class="clear-link" href="index.php">
            CLEAR SEARCH
        </a>

    </div>

<?php endif; ?>




        <h2>POSTS</h2>


        <?php if ($result->num_rows === 0): ?>

            <div class="message warning">

                No posts found.

            </div>

        <?php else: ?>


            <div class="booru-gallery">


                <?php while ($post = $result->fetch_assoc()): ?>


                   <div class="booru-post">

    <a href="post.php?id=<?php echo $post["id"]; ?>">

        <div class="booru-post-thumb-wrap">

            <img
                src="uploads/thumbs/<?php
                echo htmlspecialchars($post["filename"]);
                ?>"
                alt="post <?php echo $post["id"]; ?>"
                loading="lazy"
            >

        </div>

    </a>

    <div class="booru-post-info">

        <div class="post-id">
            #<?php echo $post["id"]; ?>
        </div>

        <div class="post-meta">
            <?php echo $post["width"]; ?> × <?php echo $post["height"]; ?>
        </div>

        <div class="post-user">
            by
            <?php
            echo htmlspecialchars($post["username"] ?? "unknown");
            ?>
        </div>

    </div>

</div>


                <?php endwhile; ?>


            </div>


        <?php endif; ?>


    </div>


    <!-- SIDEBAR -->

    <div class="column2">

    <div class="sidebar-box">

        <h2>SEARCH</h2>

        <form
            class="search-form"
            action="index.php"
            method="GET"
        >
            <input
                type="text"
                name="tags"
                placeholder="cat outside funny"
                value="<?php echo htmlspecialchars($searchText); ?>"
            >

            <button class="search-btn" type="submit">
                SEARCH
            </button>
        </form>

        <p class="small-text">
            Multiple tags = post must contain all of them.
        </p>

    </div>


    <div class="sidebar-box">

        <h2>TAGS</h2>

        <ul class="tag-list">

            <?php while ($tag = $tagResult->fetch_assoc()): ?>

                <li>
                    <a
                        class="tag-link"
                        href="index.php?tags=<?php echo urlencode($tag["name"]); ?>"
                    >
                        <?php echo htmlspecialchars($tag["name"]); ?>
                    </a>

                    <span class="tag-count">
                        (<?php echo $tag["post_count"]; ?>)
                    </span>
                </li>

            <?php endwhile; ?>

        </ul>

    </div>


    <?php if (!isset($_SESSION["user_id"])): ?>

        <div class="sidebar-box">

            <h2>INFO</h2>

            <p>You can browse anonymously.</p>

            <a class="nav-btn btn-login" href="../login.php">
                LOGIN TO POST
            </a>

        </div>

    <?php endif; ?>

</div>


    <!-- FOOTER -->

    <div class="bottom">

        KOKERSI BOORU

    </div>


</div>


</body>

</html>
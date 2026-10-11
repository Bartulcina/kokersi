
<?php

require_once __DIR__ . "/../auth.php";

$currentPage = basename($_SERVER["PHP_SELF"]);

?>

<nav class="booru-nav">

    <ul class="navel">

        <li>
            <a class="nav-btn btn-home" href="../index.php">
                HOME
            </a>
        </li>

        <li>
            <a class="nav-btn btn-booru" href="index.php">
                BOORU
            </a>
        </li>

        <li>
            <a class="nav-btn btn-wiki" href="../wiki/index.php">
                WIKI
            </a>
        </li>

        <?php if (loggedIn()): ?>

            <li>
                <a class="nav-btn btn-upload" href="upload.php">
                    UPLOAD
                </a>
            </li>

            <li>
                <a class="nav-btn btn-logout" href="../logout.php">
                    LOGOUT
                </a>
            </li>

        <?php else: ?>

            <li>
                <a class="nav-btn btn-login" href="../login.php">
                    LOGIN
                </a>
            </li>

        <?php endif; ?>

    </ul>

</nav>

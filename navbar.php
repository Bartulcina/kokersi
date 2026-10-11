<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

?>

<nav>

    <ul class="navel">

        <li>
            <a class="nav-btn btn-home" href="index.php">
                HOME
            </a>
        </li>

        <li>
            <a class="nav-btn btn-booru" href="booru/index.php">
                BOORU
            </a>
        </li>

        <li>
            <a class="nav-btn btn-wiki" href="wiki/index.php">
                WIKI
            </a></li>

        <?php if (isset($_SESSION["user_id"])): ?>

            <li>
                <a class="nav-btn btn-upload" href="booru/upload.php">
                    UPLOAD
                </a>
            </li>

            <li>
                <a class="nav-btn btn-logout" href="logout.php">
                    LOGOUT
                </a>
            </li>

        <?php else: ?>

            <li>
                <a class="nav-btn btn-login" href="login.php">
                    LOGIN
                </a>
            </li>

        <?php endif; ?>

    </ul>

</nav>
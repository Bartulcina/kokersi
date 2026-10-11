<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = basename($_SERVER["PHP_SELF"]);

?>

<nav class="booru-nav">

    <ul class="navel">

        <li>
            <a
                class="nav-btn btn-home"
                href="../index.php"
            >
                HOME
            </a>
        </li>


        <li>
            <a
                class="nav-btn btn-booru <?php
                if ($currentPage === "index.php") {
                    echo "active-nav";
                }
                ?>"
                href="index.php"
            >
                BOORU
            </a>
        </li>


        <?php if (isset($_SESSION["user_id"])): ?>

            <li>
                <a
                    class="nav-btn btn-upload <?php
                    if ($currentPage === "upload.php") {
                        echo "active-nav";
                    }
                    ?>"
                    href="upload.php"
                >
                    UPLOAD
                </a>
            </li>

            <li>
                 <a href="../wiki/index.php">WIKI</a>
            </li>


            <li>
                <a
                    class="nav-btn btn-logout"
                    href="../logout.php"
                >
                    LOGOUT
                </a>
            </li>

        <?php else: ?>

            <li>
                <a
                    class="nav-btn btn-login"
                    href="../login.php"
                >
                    LOGIN
                </a>
            </li>

        <?php endif; ?>


    </ul>

</nav>
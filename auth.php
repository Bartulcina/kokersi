<?php
// shared authentication

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function loggedIn() {
    return isset($_SESSION["user_id"]);
}

function requireLogin($redirect = true) {

    if (!loggedIn()) {

        if ($redirect) {
            header("Location: /login.php");
        } else {
            http_response_code(401);
            echo "You must be logged in.";
        }

        exit;
    }
}
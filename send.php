<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in");
}


$name = $_SESSION["username"];

$message = trim(
    $_POST["message"] ?? ""
);


if ($message === "") {

    http_response_code(400);
    exit("Message is required");

}


$fullMessage = $name . ": " . $message;


$socket = socket_create(
    AF_INET,
    SOCK_DGRAM,
    SOL_UDP
);


socket_sendto(
    $socket,
    $fullMessage,
    strlen($fullMessage),
    0,
    "127.0.0.1",
    30001
);


socket_close($socket);
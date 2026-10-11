
<?php

require_once __DIR__ . "/auth.php";
requireLogin(false);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method not allowed");
}

$name = $_SESSION["username"] ?? "Unknown";

$message = trim($_POST["message"] ?? "");

if ($message === "") {
    http_response_code(400);
    exit("Message is required");
}

if (strlen($message) > 1000) {
    http_response_code(400);
    exit("Message too long");
}

$fullMessage = $name . ": " . $message;

$socket = socket_create(
    AF_INET,
    SOCK_DGRAM,
    SOL_UDP
);

if ($socket === false) {
    http_response_code(500);
    exit("Socket error");
}

$result = socket_sendto(
    $socket,
    $fullMessage,
    strlen($fullMessage),
    0,
    "127.0.0.1",
    30001
);

socket_close($socket);

if ($result === false) {
    http_response_code(500);
    exit("Failed to send message");
}

echo "Message sent";

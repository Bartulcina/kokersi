<?php

$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

socket_bind($socket, "0.0.0.0", 30001);

echo "UDP chat server running on port 30001\n";

while (true) {

    socket_recvfrom(
        $socket,
        $message,
        4096,
        0,
        $ip,
        $port
    );

    $message = trim($message);

    if ($message === "") {
        continue;
    }

    echo "$ip:$port -> $message\n";

    file_put_contents(
        __DIR__ . "/chat.log",
        $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
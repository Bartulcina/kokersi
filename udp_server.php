<?php

$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

if ($socket === false) {
    exit("Could not create socket\n");
}

// only accept messages from this machine
if (!socket_bind($socket, "127.0.0.1", 30001)) {
    exit("Could not bind UDP socket\n");
}

echo "UDP chat server running on port 30001\n";

while (true) {

    $result = socket_recvfrom(
        $socket,
        $message,
        4096,
        0,
        $ip,
        $port
    );

    if ($result === false) {
        continue;
    }

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

<?php
$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

socket_bind($socket, "0.0.0.0", 30001);

echo "Chat server running on UDP port 30001\n";

while (true) {

    socket_recvfrom(
        $socket,
        $message,
        1024,
        0,
        $clientIp,
        $clientPort
    );

    echo "$clientIp:$clientPort -> $message\n";
}
?>
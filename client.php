<?php

$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

$serverIp = "192.168.100.32";
$serverPort = 30001;

$message = readline("Message: ");

socket_sendto(
    $socket,
    $message,
    strlen($message),
    0,
    $serverIp,
    $serverPort
);
?>
<?php

header("Content-Type: text/plain");

$file = __DIR__ . "/chat.log";

if (file_exists($file)) {
    readfile($file);
}?>
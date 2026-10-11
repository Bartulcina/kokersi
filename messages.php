
<?php

header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store");

$file = __DIR__ . "/chat.log";

if (is_file($file) && is_readable($file)) {
    readfile($file);
}

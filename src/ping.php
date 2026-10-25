<?php
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message']) && $_POST['message'] === 'ping') {
    echo "pong\n";
    exit;
}

echo "Send message=ping via POST to receive pong.\n";

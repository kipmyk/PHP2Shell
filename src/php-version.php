<?php
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo "PHP " . PHP_VERSION . "\n";
echo "UTC: " . gmdate("Y-m-d\TH:i:s\Z") . "\n\n";
echo "POST ping here: /ping.php\n";
echo "Send message=ping to receive pong.\n";
echo "Example: curl -X POST -d 'message=ping' http://localhost:8888/ping.php\n";

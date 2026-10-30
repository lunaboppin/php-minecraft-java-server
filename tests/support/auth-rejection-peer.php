<?php

declare(strict_types=1);

require_once __DIR__ . '/AuthProbe.php';

$probe = new AuthProbe((int) $argv[1]);
$status = 0;
try {
    $probe->challenge();
    $payload = $probe->until('login_disconnect');
    $message = json_decode(
        (new \PhpMinecraftJavaServer\Protocol\Binary($payload))->string(),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    // A text-only reason is sent as a bare JSON string.
    $message = is_string($message) ? ['text' => $message] : $message;
    $unavailable = ($message['text'] ?? null) === 'Authentication servers are unavailable. Please try again later.'
        || ($message['translate'] ?? null) === 'multiplayer.disconnect.authservers_down';
    if ($unavailable) {
        $status = 75;
        echo "TEMPORARY: explicit authentication service-unavailable rejection\n";
    } else {
        authNetworkCheck(
            ($message['text'] ?? null) === 'Failed to verify username!'
                || ($message['translate'] ?? null) === 'multiplayer.disconnect.unverified_username',
            'Expected explicit unverified-session rejection, got ' . json_encode($message)
        );
        echo "PASS: encrypted rejection of an unverified session\n";
    }
} finally {
    // Keep the challenge and actual disconnect even when its reason differs.
    file_put_contents($argv[2], json_encode($probe->capture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $probe->close();
}

exit($status);

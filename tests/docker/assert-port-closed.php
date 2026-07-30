<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php assert-port-closed.php <host> <port>\n");

    exit(2);
}

$host = $argv[1];
$port = filter_var($argv[2], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);

if ($host === '' || $port === false) {
    fwrite(STDERR, "Host and port must identify a valid TCP endpoint.\n");

    exit(2);
}

$resolvedHost = gethostbyname($host);
if ($resolvedHost === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
    fwrite(STDERR, "Unable to resolve TCP probe host: {$host}\n");

    exit(2);
}

$errorCode = 0;
$errorMessage = '';
$connection = @fsockopen($resolvedHost, $port, $errorCode, $errorMessage, 1.0);

if (is_resource($connection)) {
    fclose($connection);
    fwrite(STDERR, "Unexpected TCP listener on {$host}:{$port}\n");

    exit(1);
}

exit(0);

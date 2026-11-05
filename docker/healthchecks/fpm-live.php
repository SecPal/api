<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

// Default FPM role: real private FastCGI ping. CLI services use their existing
// role-specific heartbeat healthchecks rather than the HTTP/FPM probe.
$socket = $argv[1] ?? '/run/secpal-fpm/api.sock';
$stream = @stream_socket_client('unix://'.$socket, $errorCode, $errorMessage, 2);
if ($stream === false) {
    exit(1);
}
stream_set_timeout($stream, 2);
$record = static fn (int $type, string $body): string => pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
$params = '';
foreach ([
    'REQUEST_METHOD' => 'GET',
    'SCRIPT_NAME' => '/__secpal_fpm_ping',
    'SCRIPT_FILENAME' => '/__secpal_fpm_ping',
    'REQUEST_URI' => '/__secpal_fpm_ping',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
] as $name => $value) {
    $params .= chr(strlen($name)).chr(strlen($value)).$name.$value;
}
$request = $record(1, pack('nCxxxxx', 1, 0)).$record(4, $params).$record(4, '').$record(5, '');
if (fwrite($stream, $request) !== strlen($request)) {
    fclose($stream);
    exit(1);
}
$read = static function (int $length) use ($stream): string|false {
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $data .= $chunk;
    }

    return $data;
};
$output = '';
for ($count = 0; $count < 32; $count++) {
    $header = $read(8);
    if ($header === false) {
        break;
    }
    $fields = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $header);
    if ($fields === false || $fields['version'] !== 1 || $fields['id'] !== 1 || $fields['length'] > 8192) {
        break;
    }
    $body = $read($fields['length']);
    $padding = $read($fields['padding']);
    if ($body === false || $padding === false) {
        break;
    }
    if ($fields['type'] === 6) {
        $output .= $body;
        if (strlen($output) > 8192) {
            break;
        }
    } elseif ($fields['type'] === 3) {
        $end = strlen($body) === 8 ? unpack('Napp/Cprotocol', $body) : false;
        fclose($stream);
        exit($end !== false && $end['app'] === 0 && $end['protocol'] === 0
            && preg_match('/\r?\n\r?\npong\s*\z/D', $output) === 1 ? 0 : 1);
    }
}
fclose($stream);
exit(1);

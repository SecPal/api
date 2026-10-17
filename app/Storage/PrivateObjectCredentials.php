<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Storage;

use Aws\Credentials\Credentials;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use RuntimeException;

/** Explicit protected standard AWS profile, never the ambient SDK credential chain. */
final class PrivateObjectCredentials
{
    public static function load(): PromiseInterface
    {
        $path = config('private_objects.credential_file');
        $purpose = config('private_objects.purpose');
        if (! is_string($path) || ! str_starts_with($path, '/')
            || ! in_array($purpose, ['api', 'worker'], true) || is_link($path)) {
            throw new RuntimeException('Private Object credential authority is unavailable');
        }
        $stat = @stat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0077) !== 0 || $stat['nlink'] !== 1 || $stat['size'] > 8192) {
            throw new RuntimeException('Private Object credential authority is not protected');
        }
        $profiles = @parse_ini_file($path, true, INI_SCANNER_RAW);
        $profile = is_array($profiles) ? ($profiles[$purpose] ?? null) : null;
        if (! is_array($profiles) || array_keys($profiles) !== [$purpose] || ! is_array($profile) || ! isset($profile['aws_access_key_id'], $profile['aws_secret_access_key'])
            || array_diff(array_keys($profile), ['aws_access_key_id', 'aws_secret_access_key', 'aws_session_token']) !== []) {
            throw new RuntimeException('Private Object credential purpose is unavailable');
        }
        foreach ($profile as $value) {
            if (! is_string($value) || $value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new RuntimeException('Private Object credential representation is invalid');
            }
        }

        return Create::promiseFor(new Credentials(
            $profile['aws_access_key_id'], $profile['aws_secret_access_key'], $profile['aws_session_token'] ?? null,
        ));
    }
}

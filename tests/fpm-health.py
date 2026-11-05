#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Exercise the real PHP health probe against bounded Unix FastCGI fixtures."""
from pathlib import Path
import socket
import struct
import subprocess
import tempfile
import threading
import unittest

PROBE = Path(__file__).resolve().parents[1] / 'docker/healthchecks/fpm-live.php'


def record(kind, body, version=1, ident=1):
    return struct.pack('!BBHHBB', version, kind, ident, len(body), 0, 0) + body


class FpmHealthTests(unittest.TestCase):
    def probe(self, response, fragmented=False):
        with tempfile.TemporaryDirectory(prefix='fpm-ping-') as temp:
            path = str(Path(temp) / 'ping.sock')
            listener = socket.socket(socket.AF_UNIX)
            listener.bind(path)
            listener.listen(1)
            listener.settimeout(4)
            failures = []

            def server():
                try:
                    with listener.accept()[0] as connection:
                        connection.settimeout(2)
                        request = connection.recv(4096)
                        if b'/__secpal_fpm_ping' not in request:
                            failures.append('wrong ping target')
                            return
                        if fragmented:
                            for byte in response:
                                connection.sendall(bytes([byte]))
                        else:
                            connection.sendall(response)
                except (OSError, BrokenPipeError):
                    # Malformed response tests may make the client close early.
                    pass
                finally:
                    listener.close()

            thread = threading.Thread(target=server)
            thread.start()
            result = subprocess.run(['php', str(PROBE), path], capture_output=True, timeout=5)
            thread.join(timeout=5)
            self.assertFalse(thread.is_alive())
            self.assertEqual(failures, [])
            return result.returncode

    def test_private_ping_requires_complete_successful_fastcgi_response(self):
        data = record(6, b'Content-Type: text/plain\r\n\r\npong') + record(3, b'\0' * 8)
        self.assertEqual(self.probe(data, fragmented=True), 0)

    def test_wrong_pong_protocol_status_or_request_id_is_rejected(self):
        for data in [
            record(6, b'Content-Type: text/plain\r\n\r\nnot-pong') + record(3, b'\0' * 8),
            record(6, b'Content-Type: text/plain\r\n\r\npong') + record(3, b'\0\0\0\0\1\0\0\0'),
            record(6, b'Content-Type: text/plain\r\n\r\npong', ident=2) + record(3, b'\0' * 8),
            record(6, b'Content-Type: text/plain\r\n\r\npong', version=2) + record(3, b'\0' * 8),
            record(6, b'Content-Type: text/plain\r\n\r\npong'),
        ]:
            with self.subTest(data=data):
                self.assertNotEqual(self.probe(data), 0)

    def test_unreachable_socket_is_not_healthy(self):
        with tempfile.TemporaryDirectory(prefix='fpm-down-') as temp:
            result = subprocess.run(['php', str(PROBE), str(Path(temp) / 'absent.sock')],
                                    capture_output=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()

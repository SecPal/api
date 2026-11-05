#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Exercise the actual Nginx health command without a PHP interpreter."""
from http.server import BaseHTTPRequestHandler, HTTPServer
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]


class NginxHealthTests(unittest.TestCase):
    def test_exact_successful_laravel_liveness_without_php(self):
        recipe = (ROOT/'docker/nginx/Containerfile').read_text()
        source = re.search(r'COPY --chown=root:root --chmod=0555 (\S+) /usr/local/bin/secpal-http-live', recipe)[1]
        for status, body, accepted in (
            (200, b'{"status":"alive"}', True),
            (200, b'{"status":"dead"}', False),
            (201, b'{"status":"alive"}', False),
            (503, b'{"status":"alive"}', False),
            (200, b'', False),
        ):
            with self.subTest(status=status, body=body), tempfile.TemporaryDirectory() as directory:
                tools = Path(directory)
                (tools/'curl').symlink_to(shutil.which('curl'))
                class Handler(BaseHTTPRequestHandler):
                    def do_GET(self):
                        self.send_response(status)
                        self.end_headers()
                        self.wfile.write(body)
                    def log_message(self, *args):
                        pass
                server = HTTPServer(('127.0.0.1', 0), Handler)
                worker = threading.Thread(target=server.serve_forever, daemon=True)
                worker.start()
                try:
                    env = dict(os.environ, PATH=str(tools), SECPAL_HTTP_PORT=str(server.server_port),
                               SECPAL_HEALTH_TIMEOUT='1')
                    result = subprocess.run(['/bin/sh', str(ROOT/source)], env=env,
                                            capture_output=True, timeout=3)
                    self.assertEqual(result.returncode == 0, accepted, result.stderr)
                finally:
                    server.shutdown()
                    server.server_close()
                    worker.join()


if __name__ == '__main__':
    unittest.main()

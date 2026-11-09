#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Exercise the actual Nginx health command without a PHP interpreter."""
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
import re
import subprocess
import threading
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]


class NginxHealthTests(unittest.TestCase):
    def test_exact_successful_laravel_liveness_without_php(self):
        recipe = (ROOT/'docker/nginx/Containerfile').read_text()
        source = re.search(r'COPY --chown=root:root --chmod=0555 (\S+) /usr/local/bin/secpal-http-live', recipe)[1]
        image = re.search(r'^ARG NGINX_IMAGE=(\S+)$', recipe, re.M)[1]
        subprocess.run(['podman', 'pull', '--quiet', image], check=True, timeout=120)
        for status, body, accepted in (
            (200, b'{"status":"alive"}', True),
            (200, b'{"status":"dead"}', False),
            (201, b'{"status":"alive"}', False),
            (503, b'{"status":"alive"}', False),
            (200, b'', False),
            (302, b'{"status":"alive"}', False),
            (200, b'{"status":"alive","slow":true}', False),
        ):
            with self.subTest(status=status, body=body):
                class Handler(BaseHTTPRequestHandler):
                    def do_GET(self):
                        self.send_response(200 if self.path == '/redirected' else status)
                        if status == 302 and self.path != '/redirected':
                            self.send_header('Location', '/redirected')
                        self.end_headers()
                        try:
                            if b'"slow"' in body:
                                for offset in range(0, len(body), 4):
                                    self.wfile.write(body[offset:offset+4])
                                    self.wfile.flush()
                                    time.sleep(0.3)
                            else:
                                self.wfile.write(body)
                        except BrokenPipeError:
                            pass
                    def log_message(self, *args):
                        pass
                server = HTTPServer(('127.0.0.1', 0), Handler)
                worker = threading.Thread(target=server.serve_forever, daemon=True)
                worker.start()
                try:
                    result = subprocess.run([
                        'podman', 'run', '--rm', '-i', '--read-only', '--network=host',
                        '--cap-drop=all', '--security-opt=no-new-privileges',
                        '--user=10001:10001', '--entrypoint=/bin/sh',
                        '--env', 'SECPAL_HTTP_PORT='+str(server.server_port),
                        '--env', 'SECPAL_HEALTH_TIMEOUT=1',
                        '--env', 'http_proxy=http://127.0.0.1:1', image, '-s',
                    ], input=(ROOT/source).read_bytes(), capture_output=True, timeout=6)
                    self.assertEqual(result.returncode == 0, accepted, result.stderr)
                finally:
                    server.shutdown()
                    server.server_close()
                    worker.join()


if __name__ == '__main__':
    unittest.main()

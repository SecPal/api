#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Check the installed SQLite package, loaded library and Python ABI."""
import ctypes
import hashlib
import json
from pathlib import Path
import sqlite3
import subprocess
import platform

assert sqlite3.sqlite_version == "3.54.0", sqlite3.sqlite_version
proof = json.loads(Path("/usr/share/secpal/sqlite-build.json").read_text())
assert proof["version"] == "3.54.0"
assert proof["source_id"] == "2026-10-09 15:46:58 be8d059e9a49089ab2dce5ed26dd87aaf598fdc5fbe0b107c0dd758464bcd5e3"
assert proof["rebuild_identical"] is True
assert proof["soname"] == "libsqlite3.so.0"
assert proof["architecture"] == platform.machine()
assert sqlite3.sqlite_version == proof["version"]
library = Path("/usr/lib/libsqlite3.so.0").resolve(strict=True)
assert hashlib.sha256(library.read_bytes()).hexdigest() == proof["library_sha256"]
assert subprocess.run(["apk", "info", "-e", "sqlite-libs="+proof["package_version"]],
                      capture_output=True).returncode == 0
owner = subprocess.check_output(["apk", "info", "--who-owns", str(library)], text=True)
assert "sqlite-libs-"+proof["package_version"] in owner
loaded = ctypes.CDLL("libsqlite3.so.0")
loaded.sqlite3_sourceid.restype = ctypes.c_char_p
assert loaded.sqlite3_sourceid().decode() == proof["source_id"]
with sqlite3.connect(":memory:") as db:
    assert db.execute("SELECT sqlite_source_id()").fetchone()[0] == proof["source_id"]
    options = {row[0] for row in db.execute("PRAGMA compile_options")}
    assert {"ENABLE_FTS5", "ENABLE_SESSION"}.issubset(options)
    db.executescript("CREATE VIRTUAL TABLE f USING fts5(value, detail=none, prefix='1 2');"
                     "INSERT INTO f(rowid,value) VALUES(-1,'alpha'),(1,'alphabet');")
    assert db.execute("SELECT rowid FROM f WHERE f MATCH 'al*' ORDER BY rowid").fetchall() == [(-1,), (1,)]
    db.execute("INSERT INTO f(f,rank) VALUES('secure-delete',1)")
    db.execute("DELETE FROM f WHERE rowid=-1")
    assert db.execute("PRAGMA integrity_check").fetchone()[0] == "ok"
assert not Path("/usr/bin/sqlite3").exists()
assert not Path("/usr/bin/sqlite").exists()
print("SQLite package/source/library/Python ABI proof: "+json.dumps(proof, sort_keys=True))

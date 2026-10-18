#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Temporary GHCR handoff for the pinned attestation reader on isolated hosted jobs.

Not a sandbox against code already executing as the job UID. All filesystem
authority stays with that UID; directory descriptors and no-follow opens reject
untrusted paths. The private journal precedes publication and enables always()
cleanup. Abrupt VM termination is not evidence that cleanup executed.
"""
import base64
from contextlib import contextmanager
import hashlib
import json
import os
import re
from pathlib import Path
import secrets
import stat
import sys

MARKER = "attestation-handoff.json"
FLAGS = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC
LIMIT = 65536


class Rejected(ValueError):
    pass


def require(condition):
    if not condition:
        raise Rejected("ATTESTATION_CREDENTIAL_STATE_REJECTED")


def file_id(info):
    require(info is not None)
    return [info.st_dev, info.st_ino]


@contextmanager
def directory(path):
    path = Path(path)
    require(path.is_absolute() and ".." not in path.parts)
    fd = os.open("/", FLAGS | os.O_DIRECTORY)
    try:
        for name in path.parts[1:]:
            next_fd = os.open(name, FLAGS | os.O_DIRECTORY, dir_fd=fd)
            os.close(fd)
            fd = next_fd
            info = os.fstat(fd)
            require(info.st_uid in (0, os.geteuid()) and not info.st_mode & 0o022)
        yield fd
    finally:
        os.close(fd)


def read_file(fd, name):
    handle = os.open(name, FLAGS, dir_fd=fd)
    try:
        info = os.fstat(handle)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid()
                and info.st_nlink == 1 and info.st_size <= LIMIT)
        require(stat.S_IMODE(info.st_mode) in (0o400, 0o600))
        value = os.read(handle, LIMIT + 1)
        require(len(value) <= LIMIT)
        return value, info
    finally:
        os.close(handle)


def metadata(fd, name):
    try:
        info = os.stat(name, dir_fd=fd, follow_symlinks=False)
    except FileNotFoundError:
        return None
    require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) in (0o400, 0o600) and info.st_size <= LIMIT)
    return info


def create_file(fd, name, value):
    handle = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=fd)
    try:
        info = os.fstat(handle)
        view = memoryview(value)
        while view:
            count = os.write(handle, view)
            require(count > 0)
            view = view[count:]
        os.fsync(handle)
        return file_id(info)
    finally:
        os.close(handle)


def save(fd, state):
    create_file(fd, MARKER + ".next", json.dumps(state, sort_keys=True).encode())
    os.replace(MARKER + ".next", MARKER, src_dir_fd=fd, dst_dir_fd=fd)
    os.fsync(fd)


def validate_state(raw, fd, identity):
    state = json.loads(raw)
    require(state["identity"] == identity and state["private"] == file_id(os.fstat(fd)))
    require(state["phase"] in ("prepared", "creating", "armed", "removing"))
    if state["phase"] in ("armed", "removing"):
        require(state["phase"] != "removing" or state["created"] is True)
        require(re.fullmatch(r"\.secpal-attestation-[0-9a-f]{32}\.candidate", state["candidate"]))
        require(state["backup"] == state["candidate"].removesuffix(".candidate") + ".previous")
        require(re.fullmatch(r"[0-9a-f]{64}", state["digest"]))
        require(isinstance(state["created"], bool) and isinstance(state["mode"], int)
                and 0 <= state["mode"] <= 0o777 and not state["mode"] & 0o022)
        for name in ("home", "docker", "original", "candidate_id"):
            value = state[name]
            require((value is None and name in ("original", "candidate_id")) or
                    (isinstance(value, list) and len(value) == 2
                     and all(isinstance(v, int) and v >= 0 for v in value)))
    return state


def load(fd, identity):
    raw, _ = read_file(fd, MARKER)
    state = validate_state(raw, fd, identity)
    if metadata(fd, MARKER + ".next"):
        # A fully written intent may have lost its atomic-replace acknowledgment.
        # Validate the exact private/job identity before recovering that intent.
        pending, _ = read_file(fd, MARKER + ".next")
        state = validate_state(pending, fd, identity)
        os.replace(MARKER + ".next", MARKER, src_dir_fd=fd, dst_dir_fd=fd)
        os.fsync(fd)
    return state


def prepare(private, identity):
    private = Path(private)
    with directory(private.parent) as parent:
        os.mkdir(private.name, 0o700, dir_fd=parent)
    with directory(private) as fd:
        create_file(fd, MARKER, json.dumps({
            "identity": identity, "private": file_id(os.fstat(fd)), "phase": "prepared"
        }).encode())
        os.fsync(fd)


def credential(fd, actor):
    raw, _ = read_file(fd, "config.json")
    def unique(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result)
            result[key] = value
        return result
    config = json.loads(raw, object_pairs_hook=unique)
    entry = config["auths"]["ghcr.io"]
    require(isinstance(entry, dict) and set(entry) == {"auth"})
    value = base64.b64decode(entry["auth"], validate=True).decode("utf-8")
    user, token = value.split(":", 1)
    require(user == actor and token and not any(ord(c) < 32 for c in value))
    return json.dumps({"auths": {"ghcr.io": entry}}, separators=(",", ":")).encode()


def install(home, private, identity, actor):
    with directory(private) as source:
        state = load(source, identity)
        require(state["phase"] == "prepared" and stat.S_IMODE(os.fstat(source).st_mode) == 0o700)
        payload = credential(source, actor)
        with directory(home) as home_fd:
            require(file_id(os.fstat(home_fd)) != file_id(os.fstat(source)))
            try:
                docker_fd = os.open(".docker", FLAGS | os.O_DIRECTORY, dir_fd=home_fd)
                created = False
            except FileNotFoundError:
                # Record intent before creating a default-home path. If killed
                # before its inode is recorded, cleanup reports uncertainty.
                state.update(phase="creating", home=file_id(os.fstat(home_fd)))
                save(source, state)
                os.mkdir(".docker", 0o700, dir_fd=home_fd)
                docker_fd = os.open(".docker", FLAGS | os.O_DIRECTORY, dir_fd=home_fd)
                created = True
            try:
                info = os.fstat(docker_fd)
                require(info.st_uid == os.geteuid() and not info.st_mode & 0o022
                        and stat.S_IMODE(info.st_mode) <= 0o777
                        and file_id(info) != file_id(os.fstat(source)))
                original = metadata(docker_fd, "config.json")
                if original:
                    require(original.st_nlink == 1)
                stem = ".secpal-attestation-" + secrets.token_hex(16)
                state.update(phase="armed", home=file_id(os.fstat(home_fd)), docker=file_id(info),
                             created=created, mode=stat.S_IMODE(info.st_mode),
                             original=file_id(original) if original else None,
                             original_size=original.st_size if original else None,
                             original_mtime=original.st_mtime_ns if original else None,
                             original_mode=stat.S_IMODE(original.st_mode) if original else None,
                             candidate=stem + ".candidate", backup=stem + ".previous",
                             candidate_id=None, digest=hashlib.sha256(payload).hexdigest())
                save(source, state)
                os.fchmod(docker_fd, 0o700)
                # Persist candidate inode before writing secret bytes or publishing it.
                state["candidate_id"] = create_file(docker_fd, state["candidate"], b"")
                save(source, state)
                handle = os.open(state["candidate"], os.O_WRONLY | os.O_NOFOLLOW, dir_fd=docker_fd)
                try:
                    require(file_id(os.fstat(handle)) == state["candidate_id"])
                    view = memoryview(payload)
                    while view:
                        count = os.write(handle, view)
                        require(count > 0)
                        view = view[count:]
                    os.fsync(handle)
                finally:
                    os.close(handle)
                if original:
                    os.link("config.json", state["backup"], src_dir_fd=docker_fd,
                            dst_dir_fd=docker_fd, follow_symlinks=False)
                    require(file_id(metadata(docker_fd, state["backup"])) == state["original"])
                current = metadata(docker_fd, "config.json")
                require((file_id(current) if current else None) == state["original"])
                if current:
                    require(current.st_size == state["original_size"]
                            and current.st_mtime_ns == state["original_mtime"]
                            and stat.S_IMODE(current.st_mode) == state["original_mode"])
                os.replace(state["candidate"], "config.json", src_dir_fd=docker_fd, dst_dir_fd=docker_fd)
                os.fsync(docker_fd)
            finally:
                os.close(docker_fd)


def restore(home, private, identity):
    private = Path(private)
    if not private.exists():
        # Still walk the existing parent; a symlink is never a successful no-op.
        with directory(private.parent) as parent:
            require(not os.path.lexists(private))
        return
    with directory(private) as source:
        state = load(source, identity)
        if state["phase"] == "prepared":
            return
        with directory(home) as home_fd:
            require(file_id(os.fstat(home_fd)) == state["home"])
            if state["phase"] == "creating":
                require(not os.path.lexists(Path(home) / ".docker"))
                save(source, {"identity": identity, "private": state["private"], "phase": "prepared"})
                return
            if state["phase"] == "removing":
                require(state["created"])
                try:
                    info = os.stat(".docker", dir_fd=home_fd, follow_symlinks=False)
                except FileNotFoundError:
                    info = None
                if info is not None:
                    require(file_id(info) == state["docker"])
                    with directory(Path(home) / ".docker") as fd:
                        require(file_id(os.fstat(fd)) == state["docker"] and not os.listdir(fd))
                    os.rmdir(".docker", dir_fd=home_fd)
                save(source, {"identity": identity, "private": state["private"], "phase": "prepared"})
                return
            with directory(Path(home) / ".docker") as fd:
                require(file_id(os.fstat(fd)) == state["docker"] and os.fstat(fd).st_uid == os.geteuid())
                current = metadata(fd, "config.json")
                current_id = file_id(current) if current else None
                require(current_id in (state["original"], state["candidate_id"]))
                backup = metadata(fd, state["backup"])
                if state["original"]:
                    original = backup if backup else current
                    require(original is not None and file_id(original) == state["original"]
                            and original.st_size == state["original_size"]
                            and original.st_mtime_ns == state["original_mtime"]
                            and stat.S_IMODE(original.st_mode) == state["original_mode"])
                if state["candidate_id"] is not None and current_id == state["candidate_id"]:
                    raw, _ = read_file(fd, "config.json")
                    require(hashlib.sha256(raw).hexdigest() == state["digest"])
                    if state["original"]:
                        require(backup is not None)
                        os.replace(state["backup"], "config.json", src_dir_fd=fd, dst_dir_fd=fd)
                    else:
                        os.unlink("config.json", dir_fd=fd)
                elif backup:
                    require(file_id(backup) == state["original"])
                    os.unlink(state["backup"], dir_fd=fd)
                candidate = metadata(fd, state["candidate"])
                if candidate:
                    require(file_id(candidate) == state["candidate_id"])
                    os.unlink(state["candidate"], dir_fd=fd)
                if state["created"]:
                    require(not os.listdir(fd))
                    # Removal intent makes a lost successful rmdir acknowledgment recoverable.
                    state["phase"] = "removing"
                    save(source, state)
                else:
                    os.fchmod(fd, state["mode"])
                os.fsync(fd)
            if state["created"]:
                require(file_id(os.stat(".docker", dir_fd=home_fd, follow_symlinks=False)) == state["docker"])
                os.rmdir(".docker", dir_fd=home_fd)
            state = {"identity": identity, "private": state["private"], "phase": "prepared"}
            save(source, state)


def cleanup(home, private, identity):
    restore(home, private, identity)
    private = Path(private)
    if not private.exists():
        return
    with directory(private) as fd:
        load(fd, identity)
        require(set(os.listdir(fd)) <= {MARKER, "config.json"})
        if metadata(fd, "config.json"):
            read_file(fd, "config.json")
            os.unlink("config.json", dir_fd=fd)
        os.unlink(MARKER, dir_fd=fd)
        expected = file_id(os.fstat(fd))
    with directory(private.parent) as parent:
        require(file_id(os.stat(private.name, dir_fd=parent, follow_symlinks=False)) == expected)
        os.rmdir(private.name, dir_fd=parent)


def main():
    require(len(sys.argv) == 2)
    require(os.environ.get("GITHUB_REPOSITORY") == "SecPal/api"
            and os.environ.get("GITHUB_EVENT_NAME") == "push"
            and os.environ.get("GITHUB_REF") == "refs/heads/main"
            and os.environ.get("RUNNER_ENVIRONMENT") == "github-hosted"
            and os.environ.get("GITHUB_WORKFLOW_REF") ==
            "SecPal/api/.github/workflows/publish-container.yml@refs/heads/main")
    job = os.environ["GITHUB_JOB"]
    require(job in ("attest", "accept"))
    identity = {"run": os.environ["GITHUB_RUN_ID"], "attempt": os.environ["GITHUB_RUN_ATTEMPT"], "job": job}
    require(identity["run"].isdigit() and identity["attempt"].isdigit())
    name = "secpal-attest-auth" if job == "attest" else "secpal-final-attest-auth"
    private = Path(os.environ["RUNNER_TEMP"]) / name
    require(os.environ.get("DOCKER_CONFIG", str(private)) == str(private))
    action = sys.argv[1]
    require(action in ("prepare", "install", "cleanup"))
    if action == "prepare":
        prepare(private, identity)
    elif action == "install":
        install(Path.home(), private, identity, os.environ["GITHUB_ACTOR"])
    else:
        cleanup(Path.home(), private, identity)
    print("Attestation credential " + action + ": PASS")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, IndexError):
        print("ATTESTATION_CREDENTIAL_OPERATION_FAILED; cleanup may be incomplete", file=sys.stderr)
        raise SystemExit(1) from None

#!/usr/bin/env python3
"""Smoke-test the local fake OLT TCP boundaries without touching a real device."""
from __future__ import annotations

import socket
import subprocess
import sys
import time
from pathlib import Path


ROOT = Path(__file__).resolve().parent


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def wait_for_port(port: int, process: subprocess.Popen[str]) -> None:
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError(f"fake device exited with status {process.returncode}")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.25):
                return
        except OSError:
            time.sleep(0.05)
    raise TimeoutError(f"fake device did not listen on port {port}")


def exchange(port: int, commands: list[str], marker: str) -> str:
    with socket.create_connection(("127.0.0.1", port), timeout=3) as sock:
        sock.settimeout(3)
        all_received = bytearray()
        for command in commands:
            received = bytearray()
            sock.sendall((command + "\n").encode("utf-8"))
            while marker.encode("utf-8") not in received:
                chunk = sock.recv(4096)
                if not chunk:
                    break
                received.extend(chunk)
            all_received.extend(received)
    return all_received.decode("utf-8", errors="replace")


def assert_response(port: int, commands: list[str], wait_marker: str, expected: str) -> None:
    response = exchange(port, commands, wait_marker)
    if expected not in response:
        raise AssertionError(f"expected {expected!r} in fake response: {response!r}")


def run_fake(script: str, safe_commands: list[str], attack: str, marker: str) -> None:
    port = free_port()
    process = subprocess.Popen(
        [sys.executable, str(ROOT / script), "--host", "127.0.0.1", "--port", str(port)],
        cwd=ROOT.parent.parent,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    try:
        wait_for_port(port, process)
        assert_response(port, safe_commands, marker, "[OK]")
        assert_response(port, [attack], "[REJECT]", "[REJECT]")
    finally:
        process.terminate()
        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=3)


def main() -> int:
    run_fake(
        "fake_zte_c300.py",
        ["show gpon onu state 0 0 1", "no onu 1"],
        "no --state=on",
        "[OK]",
    )
    run_fake(
        "fake_fiberhome.py",
        ["show onu state 1 1 1 1"],
        "no --state=on",
        "[OK]",
    )
    print("fake-device-smoke: ZTE and FiberHome safe/rejected command paths passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

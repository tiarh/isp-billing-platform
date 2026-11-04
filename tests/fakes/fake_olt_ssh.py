#!/usr/bin/env python3
"""Test-only SSH server for the OLT provisioning integration boundary.

This server is deliberately not a vendor emulator. It provides only the SSH
authentication, interactive shell, prompts, and small deterministic output
surface needed by the FiberHome-on-C300 controller test. It binds inside an
isolated CI container and never connects to a real OLT.
"""
from __future__ import annotations

import json
import os
import re
import signal
import socket
import threading
from pathlib import Path

import paramiko


HOST = os.environ.get("FAKE_OLT_SSH_BIND", "0.0.0.0")
PORT = int(os.environ.get("FAKE_OLT_SSH_PORT", "2222"))
USERNAME = os.environ.get("FAKE_OLT_SSH_USERNAME", "fake-olt")
PASSWORD = os.environ.get("FAKE_OLT_SSH_PASSWORD", "fake-password")
CAPTURE = Path(os.environ.get("FAKE_OLT_SSH_CAPTURE", "/tmp/fake-olt-ssh.jsonl"))
CAPTURE.parent.mkdir(parents=True, exist_ok=True)

UNSAFE_RE = re.compile(r"[\x00-\x1f\x7f`$()]|(^|\s)-")
PORT_RE = re.compile(r"gpon-olt_(\d+/\d+/\d+)", re.IGNORECASE)
ONU_RE = re.compile(r"\bonu\s+(\d+)\s+type\s+(\S+)\s+sn\s+(\S+)", re.IGNORECASE)
ONU_IF_RE = re.compile(r"gpon-onu_(\d+/\d+/\d+):(\d+)", re.IGNORECASE)


class DeviceState:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._onus: dict[str, dict[int, dict[str, str]]] = {}

    def command(self, raw: str, mode: str) -> tuple[str, str]:
        command = raw.strip()
        if command == "":
            return "", mode

        if len(command) > 256 or UNSAFE_RE.search(command):
            self.capture(command, "rejected")
            return "%Error: unsafe command rejected\r\n", mode

        self.capture(command, "accepted")
        lower = command.lower()

        if lower == "enable":
            return "", "enable"
        if lower in {"end", "logout", "quit"}:
            return "", "enable"
        if lower == "exit":
            return "", self.parent_mode(mode)
        if lower.startswith("configure terminal"):
            return "", "config"

        if lower.startswith("interface gpon-olt_"):
            return "", "gpon-olt"
        if lower.startswith("interface gpon-onu_"):
            return "", "config-if"
        if lower.startswith("pon-onu-mng "):
            return "", "mng"

        port_match = PORT_RE.search(command)
        port = port_match.group(1) if port_match else "1/3/9"
        if lower.startswith("show running-config interface gpon-olt"):
            return self.render_olt_config(port), mode
        if lower.startswith("show running-config interface gpon-onu"):
            return "interface gpon-onu\r\n description fake-test-onu\r\n", mode
        if lower.startswith("show gpon onu detail-info"):
            return (
                "Operational State: working\r\n"
                "Rx power: -23.5 dBm\r\n"
                "Tx power: 2.1 dBm\r\n"
                "Distance: 1240 m\r\n",
                mode,
            )
        if lower.startswith("show gpon onu state"):
            return "Operational State: working\r\n", mode
        if lower.startswith("show gpon onu uncfg"):
            return "No unconfigured ONU\r\n", mode
        if lower.startswith("show version"):
            return "FAKE-C300-SSH VERSION TEST\r\n", mode

        register = ONU_RE.search(command)
        if register and lower.startswith("onu "):
            onu_id, onu_type, serial = register.groups()
            with self._lock:
                self._onus.setdefault(port, {})[int(onu_id)] = {
                    "type": onu_type,
                    "sn": serial,
                }
            return "ONU registered\r\n", mode

        if lower.startswith("no onu "):
            parts = command.split()
            if len(parts) >= 3 and parts[2].isdigit():
                with self._lock:
                    self._onus.setdefault(port, {}).pop(int(parts[2]), None)
            return "ONU removed\r\n", mode

        # All remaining commands in the test fixture are accepted as command
        # acknowledgements. No vendor-specific semantics are inferred here.
        return "OK\r\n", mode

    def parent_mode(self, mode: str) -> str:
        return {
            "config-if": "config",
            "gpon-olt": "config",
            "mng": "config-if",
            "config": "enable",
        }.get(mode, "enable")

    def render_olt_config(self, port: str) -> str:
        with self._lock:
            entries = self._onus.get(port, {})
            lines = [f"interface gpon-olt_{port}"]
            for onu_id, values in sorted(entries.items()):
                lines.append(
                    f" onu {onu_id} type {values['type']} sn {values['sn']}"
                )
            return "\r\n".join(lines) + "\r\n"

    def capture(self, command: str, outcome: str) -> None:
        # The fixture never writes a credential-bearing command to its log.
        safe = re.sub(r"(?i)(password\s+)\S+", r"\1[REDACTED]", command)
        record = {"command": safe, "outcome": outcome}
        with self._lock:
            with CAPTURE.open("a", encoding="utf-8") as handle:
                handle.write(json.dumps(record) + "\n")


class FakeServer(paramiko.ServerInterface):
    def __init__(self) -> None:
        self.ready = threading.Event()
        self.shell = False
        self.exec_command: str | None = None

    def check_auth_password(self, username: str, password: str) -> int:
        if username == USERNAME and password == PASSWORD:
            return paramiko.AUTH_SUCCESSFUL
        return paramiko.AUTH_FAILED

    def get_allowed_auths(self, username: str) -> str:
        return "password"

    def check_channel_request(self, kind: str, chanid: int) -> int:
        if kind == "session":
            return paramiko.OPEN_SUCCEEDED
        return paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED

    def check_channel_pty_request(self, channel, term, width, height, pixelwidth, pixelheight, modes) -> bool:
        return True

    def check_channel_shell_request(self, channel) -> bool:
        self.shell = True
        self.ready.set()
        return True

    def check_channel_exec_request(self, channel, command: bytes) -> bool:
        self.exec_command = command.decode("utf-8", errors="replace")
        self.ready.set()
        return True


STATE = DeviceState()
STOP = threading.Event()


def prompt(mode: str) -> str:
    return {
        "enable": "FAKE-OLT# ",
        "config": "FAKE-OLT(config)# ",
        "gpon-olt": "FAKE-OLT(config-if)# ",
        "config-if": "FAKE-OLT(config-if)# ",
        "mng": "FAKE-OLT(config-mng)# ",
    }[mode]


def serve_channel(channel, server: FakeServer) -> None:
    mode = "enable"
    channel.settimeout(1.0)
    try:
        if server.exec_command is not None:
            output, mode = STATE.command(server.exec_command, mode)
            channel.send(output + prompt(mode))
            return

        channel.send("FAKE-OLT SSH test fixture\r\n" + prompt(mode))
        buffer = b""
        while not STOP.is_set() and not channel.closed:
            try:
                data = channel.recv(4096)
            except socket.timeout:
                continue
            if not data:
                return
            buffer += data
            while b"\n" in buffer:
                line, buffer = buffer.split(b"\n", 1)
                command = line.decode("utf-8", errors="replace").rstrip("\r")
                output, mode = STATE.command(command, mode)
                if command:
                    channel.send(command + "\r\n")
                channel.send(output + prompt(mode))
    finally:
        channel.close()


def serve_client(client: socket.socket, host_key: paramiko.PKey) -> None:
    transport = paramiko.Transport(client)
    transport.add_server_key(host_key)
    server = FakeServer()
    try:
        transport.start_server(server=server)
        channel = transport.accept(10)
        if channel is None or not server.ready.wait(10):
            return
        serve_channel(channel, server)
    finally:
        transport.close()
        client.close()


def stop(*_args) -> None:
    STOP.set()


def main() -> int:
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    CAPTURE.write_text("", encoding="utf-8")
    host_key = paramiko.RSAKey.generate(2048)
    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    listener.bind((HOST, PORT))
    listener.listen(16)
    listener.settimeout(1.0)
    print(f"fake-olt-ssh listening on {HOST}:{PORT}", flush=True)
    try:
        while not STOP.is_set():
            try:
                client, _address = listener.accept()
            except socket.timeout:
                continue
            threading.Thread(
                target=serve_client,
                args=(client, host_key),
                daemon=True,
            ).start()
    finally:
        listener.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

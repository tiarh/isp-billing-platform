#!/usr/bin/env python3
"""
fake_zte_c300.py — Phase C fake device for ZTE C300 OLT CLI surface.

Test-only fixture: accepts incoming TCP connections on a local port,
emulates a subset of the ZTE C300 CLI commands used by the ebilling
provisioning paths. Validates incoming argument shapes against the
universal dangerous-pattern ruleset defined in Phase B-Skeleton
(App\\Support\\Olt\\VendorCommandEncoder::DANGEROUS_CHAR_RE).

Vendor-specific positional limits are intentionally NOT enforced here:
those belong to the real vendor encoder and are [VENDOR-RULE-TBD] until
the operator sources official CLI specs.

The fake is intentionally conservative — it accepts only argument shapes
that the real OltZteC300Service::collectSnapshotViaPexpect would attempt,
and rejects everything else with a deterministic `[REJECT]` line so test
assertions can pin exact behavior. Accepted commands are appended to a
JSONL log under tests/fakes/logs/captured_zte_cmds.jsonl.

Run: python3 tests/fakes/fake_zte_c300.py [--host 127.0.0.1] [--port 16261]
"""
from __future__ import annotations

import argparse
import json
import os
import re
import socket
import socketserver
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import List, Optional, Tuple

LOG_DIR = Path(__file__).resolve().parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
CAPTURE_LOG = LOG_DIR / "captured_zte_cmds.jsonl"

# Universal dangerous patterns — kept in sync with the Phase B-Skeleton
# VendorCommandEncoder::DANGEROUS_CHAR_RE. Vendor-specific positional
# limits are intentionally NOT included here.
DANGEROUS_CHAR_RE = re.compile(
    r"[\\\r\n\x00-\x1f\x7f`$()]|--?[A-Za-z_]|^(?=-)|^\s*\"|^\s*'"
)
MAX_ARG_LEN = 256

ALLOWED_VERBS: Tuple[str, ...] = (
    "show", "configure", "interface", "gpon", "exit", "end",
    "write", "no", "set", "clear", "enable",
)


def _row(onu_ref: str, slot: str, pon: str, **fields) -> str:
    base = f"gpon-onu {slot}/{pon}/{onu_ref}"
    extra = "\n".join(f" {k}: {v}" for k, v in fields.items())
    return f"{base}\n{extra}\n[OK]\n"


def _render_state(args: List[str]) -> str:
    slot = args[0] if len(args) > 0 else "0"
    pon = args[1] if len(args) > 1 else "0"
    onu = args[2] if len(args) > 2 else "1"
    return _row(onu, slot, pon,
                state="working",
                phase_state="O5",
                onu_id=onu,
                run_state="online")


def _render_baseinfo(args: List[str]) -> str:
    slot = args[0] if len(args) > 0 else "0"
    pon = args[1] if len(args) > 1 else "0"
    onu = args[2] if len(args) > 2 else "1"
    return _row(onu, slot, pon,
                SerialNumber="ZTEG-FAKE0001",
                OnuType="F601",
                Description=f"fake-device-onu-{onu}")


def _render_detail(args: List[str]) -> str:
    slot = args[0] if len(args) > 0 else "0"
    pon = args[1] if len(args) > 1 else "0"
    onu = args[2] if len(args) > 2 else "1"
    return _row(onu, slot, pon,
                RX_POWER_dbm="-23.5",
                TX_POWER_dbm="2.1",
                DISTANCE_km="1.4",
                ONT_VENDOR="ZTEG-FAKE")


def _render_pon_power(args: List[str]) -> str:
    return (
        "pon power attenuation summary:\n"
        " 0/0: -2.1 dBm (TX), -23.5 dBm (RX_avg), 4 ONUs\n"
        " 0/1: -1.9 dBm (TX), -24.1 dBm (RX_avg), 3 ONUs\n"
        "[OK]\n"
    )


def _render_running_cfg(args: List[str]) -> str:
    return (
        "interface gpon-onu_0/0/1\n"
        "  description fake-test-onu\n"
        "  service-profile skynet-1g\n"
        "  tcont 1\n"
        "  gemport 1\n"
        "[OK]\n"
    )


def validate_arg(arg: str) -> Tuple[bool, str]:
    if not arg:
        return False, "empty"
    if len(arg) > MAX_ARG_LEN:
        return False, f"arg_too_long:{len(arg)}"
    if DANGEROUS_CHAR_RE.search(arg):
        return False, "dangerous_pattern"
    return True, "ok"


def parse_command(buf: str) -> Optional[List[str]]:
    stripped = buf.strip()
    if not stripped:
        return None
    if stripped.endswith("\r"):
        stripped = stripped[:-1]
    return stripped.split(" ")


def dispatch(buf: str) -> str:
    tokens = parse_command(buf)
    if tokens is None:
        return ""
    verb = tokens[0]
    if verb not in ALLOWED_VERBS:
        return "[REJECT] verb_not_allowed\n"
    args = tokens[1:]
    for arg in args:
        ok, why = validate_arg(arg)
        if not ok:
            return f"[REJECT] {why}: {arg[:32]!r}\n"
    if "show gpon onu state" in buf:
        return _render_state(args)
    if "show gpon onu baseinfo" in buf:
        return _render_baseinfo(args)
    if "show gpon onu detail-info" in buf:
        return _render_detail(args)
    if "show pon power attenuation" in buf:
        return _render_pon_power(args)
    if "show running-config interface gpon-onu" in buf:
        return _render_running_cfg(args)
    if verb == "show" and "version" in tokens:
        return "ZTE-C300-SoftwareVersion: V1.2.5P3\n[OK]\n"
    if verb in ("end", "write", "exit", "no"):
        return "[OK]\n"
    if verb == "configure":
        return "Enter configuration commands, one per line.  End with CNTL/Z.\n"
    if verb == "interface":
        return "[OK]\n"
    if verb == "set":
        return "[OK]\n"
    return "[REJECT] command_not_handled\n"


def log_accept(buf: str) -> None:
    rec = {
        "ts": datetime.now(timezone.utc).isoformat(),
        "raw": buf.rstrip("\n"),
        "len": len(buf),
    }
    try:
        line = json.dumps(rec) + "\n"
        with CAPTURE_LOG.open("a", encoding="utf-8") as fh:
            fh.write(line)
    except Exception as exc:
        sys.stderr.write(f"log_write_failed: {exc}\n")


class FakeOltHandler(socketserver.StreamRequestHandler):
    allow_reuse_address = True

    def handle(self) -> None:
        peer = self.client_address
        sys.stdout.write(f"client_connected: {peer}\n")
        sys.stdout.flush()
        banner = "ZTE-C300 (fake) — test device, NOT a production OLT\n"
        try:
            self.wfile.write(banner.encode("utf-8"))
            self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError):
            return
        while True:
            try:
                line = self.rfile.readline()
            except (ConnectionResetError, ValueError):
                return
            if not line:
                return
            try:
                text = line.decode("utf-8", errors="replace")
            except Exception:
                text = repr(line)
            response = dispatch(text)
            log_accept(text)
            try:
                self.wfile.write(response.encode("utf-8"))
                self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError):
                return
            stripped = text.strip()
            if stripped in ("exit", "end", "logout", "quit"):
                return


class ThreadedServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    daemon_threads = True
    allow_reuse_address = True


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="fake_zte_c300 test fixture")
    parser.add_argument("--host", default=os.environ.get("FAKE_OLT_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(os.environ.get("FAKE_OLT_PORT", "16261")))
    args = parser.parse_args(argv)
    CAPTURE_LOG.write_text("", encoding="utf-8")
    server = ThreadedServer((args.host, args.port), FakeOltHandler)
    sys.stdout.write(f"fake_zte_c300 listening on {args.host}:{args.port}\n")
    sys.stdout.write(f"capture_log={CAPTURE_LOG}\n")
    sys.stdout.flush()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        sys.stdout.write("shutting down\n")
        server.shutdown()
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

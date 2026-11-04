#!/usr/bin/env python3
"""
fake_fiberhome.py — Phase C fake device for FiberHome AN/SFU OLT CLI surface.

The FiberHome OLT CLI differs from ZTE C300 in:
  * prompt format ("AN5516-04>")
  * show command names ("show interface gpon onu" rather than
    "show gpon onu-X/Y/Z-N detail-info")
  * authorization model (`enable` then `config` block, not
    `configure terminal`)

We emulate the surface subset that the real OLT provisioning path would
probe. Universal dangerous-pattern check from Phase B-Skeleton is reused.

Run: python3 tests/fakes/fake_fiberhome.py [--host 127.0.0.1] [--port 16262]
"""
from __future__ import annotations

import argparse
import json
import os
import re
import socketserver
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import List, Optional, Tuple

LOG_DIR = Path(__file__).resolve().parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
CAPTURE_LOG = LOG_DIR / "captured_fiberhome_cmds.jsonl"

DANGEROUS_CHAR_RE = re.compile(
    r"[\\\r\n\x00-\x1f\x7f`$()]|--?[A-Za-z_]|^(?=-)|^\s*\"|^\s*'"
)
MAX_ARG_LEN = 256
ALLOWED_VERBS = (
    "show", "enable", "config", "interface", "gpon", "exit", "end",
    "write", "no", "set", "clear",
)
PROMPT_USER = "AN5516-04> "
PROMPT_PRIV = "AN5516-04# "
PROMPT_CFG = "AN5516-04(config)# "


def _prompt_for_level(level: str) -> str:
    if level == "config":
        return PROMPT_CFG
    if level == "priv":
        return PROMPT_PRIV
    return PROMPT_USER


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


def _render_state(args: List[str]) -> str:
    slot = args[2] if len(args) > 2 else "1"
    pon = args[3] if len(args) > 3 else "1"
    onu = args[4] if len(args) > 4 else "1"
    return (
        f"ONU state f/s/p/onu : {slot}/{pon}/{onu}\n"
        "  Phase: P5-Working\n"
        "  ONT distance (m): 1240\n"
        "  Rx optical power (dBm): -23.91\n"
        "[OK]\n"
    )


def _render_detail(args: List[str]) -> str:
    slot = args[2] if len(args) > 2 else "1"
    pon = args[3] if len(args) > 3 else "1"
    onu = args[4] if len(args) > 4 else "1"
    return (
        f"ONU detail f/{slot}/{pon}/{onu}:\n"
        "  Description         : fake-fh-onu\n"
        "  SerialNumber        : FH-FT-FAKE-0001\n"
        "  ONT-Type            : HG6245F\n"
        "  Rx Optical (dBm)    : -23.91\n"
        "  Distance (m)        : 1240\n"
        "[OK]\n"
    )


def _render_running(args: List[str]) -> str:
    return (
        "interface gpon onu 1/1/1\n"
        "  description fake-fh-onu\n"
        "  service-port 1 vlan 100\n"
        "[OK]\n"
    )


def dispatch(buf: str, level: str) -> Tuple[str, str]:
    tokens = parse_command(buf)
    if tokens is None:
        return "", level
    verb = tokens[0]
    if verb not in ALLOWED_VERBS:
        return "[REJECT] verb_not_allowed\n", level
    args = tokens[1:]
    for arg in args:
        ok, why = validate_arg(arg)
        if not ok:
            return f"[REJECT] {why}: {arg[:32]!r}\n", level
    if verb == "enable":
        if level == "user":
            return "Password: \n", "priv"
        return "[OK]\n", "priv"
    if verb == "config":
        return "", "config"
    if verb == "end":
        return "[OK]\n", "priv"
    if verb == "exit":
        if level == "config":
            return "[OK]\n", "priv"
        if level == "priv":
            return "[OK]\n", "user"
        return "[OK]\n", "user"
    if verb == "write":
        return "[OK]\n", level
    if "show onu state" in buf or "show interface gpon onu state" in buf:
        return _render_state(args), level
    if "onu detail" in buf or "show onu detail" in buf:
        return _render_detail(args), level
    if "show running-config" in buf:
        return _render_running(args), level
    if verb == "interface" or verb == "gpon":
        return "[OK]\n", level
    if verb in ("set", "no", "clear"):
        return "[OK]\n", level
    return "[REJECT] command_not_handled\n", level


def log_accept(buf: str, level: str) -> None:
    rec = {
        "ts": datetime.now(timezone.utc).isoformat(),
        "raw": buf.rstrip("\n"),
        "level": level,
        "len": len(buf),
    }
    try:
        line = json.dumps(rec) + "\n"
        with CAPTURE_LOG.open("a", encoding="utf-8") as fh:
            fh.write(line)
    except Exception as exc:
        sys.stderr.write(f"log_write_failed: {exc}\n")


class FakeFhHandler(socketserver.StreamRequestHandler):
    allow_reuse_address = True

    def handle(self) -> None:
        level = "user"
        sys.stdout.write(f"client_connected: {self.client_address}\n")
        sys.stdout.flush()
        try:
            self.wfile.write(PROMPT_USER.encode("utf-8"))
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
            response, level = dispatch(text, level)
            log_accept(text, level)
            out = response + _prompt_for_level(level)
            try:
                self.wfile.write(out.encode("utf-8"))
                self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError):
                return
            stripped = text.strip()
            if stripped in ("exit", "logout", "quit") and level == "user":
                return


class ThreadedServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    daemon_threads = True
    allow_reuse_address = True


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="fake_fiberhome test fixture")
    parser.add_argument("--host", default=os.environ.get("FAKE_OLT_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(os.environ.get("FAKE_OLT_PORT", "16262")))
    args = parser.parse_args(argv)
    CAPTURE_LOG.write_text("", encoding="utf-8")
    server = ThreadedServer((args.host, args.port), FakeFhHandler)
    sys.stdout.write(f"fake_fiberhome listening on {args.host}:{args.port}\n")
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

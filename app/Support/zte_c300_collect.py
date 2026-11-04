#!/usr/bin/env python3
"""GPON OLT collector using pexpect - compatible with old SSH servers (OpenSSH 7.x era OLTs)."""
import json
import os
import sys

import pexpect


def run_command(child, command, prompt):
    """Run a command on the OLT and return output.

    pexpect keeps unmatched data buffered across TIMEOUT/EOF: `child.before`
    then holds *everything since the last match*, not just the new chunk.
    Appending it on every 3s timeout duplicated long outputs (audit
    2026-10-08: OLT RA `show running-config` came back ~10x, 5.3 MB for
    ~0.5 MB of config). Only text before a match is committed; on
    timeout the pending buffer replaces (not extends) the uncommitted tail.
    """
    child.sendline(command)
    committed = ""
    output = ""
    last_length = 0
    stalled_timeouts = 0

    for _ in range(500):
        index = child.expect([r"--More--", r"More:", prompt, pexpect.TIMEOUT, pexpect.EOF], timeout=3)

        if index in (0, 1, 2):
            committed += child.before or ""
            output = committed
        else:
            output = committed + (child.before or "")

        if index in (0, 1):
            stalled_timeouts = 0
            child.send(" ")
            continue

        if index == 2:
            return output

        if output:
            if len(output) > last_length:
                last_length = len(output)
                stalled_timeouts = 0
                child.send(" ")
                continue

            stalled_timeouts += 1
            if stalled_timeouts < 3:
                child.send(" ")
                continue

            return output

        raise RuntimeError(f"command timed out: {command}")

    return output


def main():
    host = os.environ.get("OLT_HOST")
    port = os.environ.get("OLT_PORT", "22")
    username = os.environ.get("OLT_USERNAME")
    password = os.environ.get("OLT_PASSWORD")
    commands_json = os.environ.get("OLT_COMMANDS")
    prompt = os.environ.get("OLT_PROMPT", r"[#>]\s*$")

    if not host or not username or not password:
        print(json.dumps({"ok": False, "error": "OLT_HOST, OLT_USERNAME, OLT_PASSWORD required"}))
        sys.exit(1)

    if not commands_json:
        print(json.dumps({"ok": False, "error": "OLT_COMMANDS (JSON array) required"}))
        sys.exit(1)

    try:
        commands = json.loads(commands_json)
    except json.JSONDecodeError as e:
        print(json.dumps({"ok": False, "error": f"Invalid OLT_COMMANDS JSON: {e}"}))
        sys.exit(1)

    # SSH command with options compatible with old OLT SSH servers
    # These OLTs use OpenSSH 7.x era with ssh-rsa host keys and diffie-hellman-group14-sha1 KEX
    ssh_command = (
        "ssh -tt "
        "-o StrictHostKeyChecking=no "
        "-o UserKnownHostsFile=/dev/null "
        "-o PreferredAuthentications=keyboard-interactive,password "
        "-o PubkeyAuthentication=no "
        "-o PasswordAuthentication=yes "
        "-o ConnectTimeout=20 "
        "-o ServerAliveInterval=15 "
        "-o ServerAliveCountMax=3 "
        # Critical: old OLTs need these specific algorithms
        "-o KexAlgorithms=diffie-hellman-group14-sha1 "
        "-o Ciphers=aes128-cbc "
        "-o HostKeyAlgorithms=ssh-rsa "
        "-o PubkeyAcceptedAlgorithms=ssh-rsa "
        f"-p {port} {username}@{host}"
    )

    child = pexpect.spawn(ssh_command, encoding="utf-8", timeout=12)
    child.delaybeforesend = 0.05

    try:
        # Login sequence
        for _ in range(4):
            login_index = child.expect([
                r"yes/no",
                r"[Pp]assword:",
                r"[>#]",
                pexpect.TIMEOUT,
                pexpect.EOF,
            ], timeout=12)

            if login_index == 0:
                child.sendline("yes")
                continue

            if login_index == 1:
                child.sendline(password)
                continue

            if login_index == 2:
                # Already at prompt (no password needed)
                break

            raise RuntimeError(
                f"ssh login did not reach OLT prompt (attempt {_}): "
                f"before={repr((child.before or '')[-200:])} after={repr(child.after)}"
            )
        else:
            raise RuntimeError(
                f"ssh login did not reach OLT prompt: "
                f"before={repr((child.before or '')[-200:])} after={repr(child.after)}"
            )

        outputs = {}

        # Disable pagination
        for setup in ["terminal page-break disable", "terminal length 0", "terminal no param"]:
            try:
                run_command(child, setup, prompt)
            except Exception:
                pass

        # Run each command
        for command in commands:
            try:
                outputs[command] = run_command(child, command, prompt)
            except Exception as e:
                outputs[command] = f"[ERROR: {e}]"

        print(json.dumps({"ok": True, "outputs": outputs}))

    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        sys.exit(1)
    finally:
        try:
            child.sendline("exit")
            child.close(force=True)
        except Exception:
            pass


if __name__ == "__main__":
    main()
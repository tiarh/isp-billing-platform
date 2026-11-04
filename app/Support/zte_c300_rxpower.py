#!/usr/bin/env python3
"""ZTE C300 RX Power Collector — uses CLI 'show pon power attenuation' per ONU.

SSH to OLT, iterates through ONU refs, parses attenuation output.
Returns JSON: {"onus": {"1/2/1:1": {"rx_dbm": -26.576, "tx_dbm": 6.609, "onu_tx_dbm": 1.446, "olt_rx_dbm": -31.259, "attenuation_db": 33.185}, ...}}

Compatible with old ZTE C300 SSH servers (ssh-rsa, diffie-hellman-group14-sha1, aes128-cbc).
"""
import json
import os
import re
import sys
import time

import pexpect


def run_cmd(child, cmd, timeout=5):
    """Run a command on the OLT and return output."""
    child.sendline(cmd)
    output = ""
    for _ in range(100):
        i = child.expect([r"--More--", r"More:", r"#", pexpect.TIMEOUT, pexpect.EOF], timeout=timeout)
        output += child.before or ""
        if i in (0, 1):
            child.send(" ")
            continue
        if i == 2:
            return output
        break
    return output


def parse_attenuation(output):
    """Parse 'show pon power attenuation gpon-onu_X/Y/Z:N' output.

    Returns dict with rx_dbm (ONU RX), or None if no signal/offline.
    """
    result = {}
    # up      Rx :-31.259(dbm)      Tx:1.446(dbm)        32.705(dB)
    # down    Tx :6.609(dbm)        Rx:-26.576(dbm)      33.185(dB)
    for line in output.split("\n"):
        line = line.strip()
        if line.startswith("up"):
            # OLT Rx (upstream received power from ONU)
            m = re.search(r"Rx\s*:\s*([-\d.]+|no signal)\(dbm\)", line)
            if m:
                val = m.group(1)
                result["olt_rx_dbm"] = float(val) if val != "no signal" else None
            # ONU Tx
            m = re.search(r"Tx\s*:\s*([-\d.]+|N/A)\(dbm\)", line)
            if m:
                val = m.group(1)
                result["onu_tx_dbm"] = float(val) if val not in ("N/A", "no signal") else None
        elif line.startswith("down"):
            # OLT Tx (downstream transmitted power)
            m = re.search(r"Tx\s*:\s*([-\d.]+|no signal)\(dbm\)", line)
            if m:
                val = m.group(1)
                result["olt_tx_dbm"] = float(val) if val != "no signal" else None
            # ONU Rx — THIS IS THE KEY "redaman" VALUE
            m = re.search(r"Rx\s*:\s*([-\d.]+|N/A)\(dbm\)", line)
            if m:
                val = m.group(1)
                result["rx_dbm"] = float(val) if val not in ("N/A", "no signal") else None
            # Attenuation
            m = re.search(r"(\d+\.\d+)\(dB\)", line)
            if m:
                result["attenuation_db"] = float(m.group(1))

    # Also handle "no signal" case (upstream)
    if "no signal" in output.lower() and "olt_rx_dbm" not in result:
        result["olt_rx_dbm"] = None

    return result if result else None


def main():
    if len(sys.argv) < 5:
        print("Usage: zte_c300_rxpower.py <host> <port> <username> <password> [onu_refs_json]", file=sys.stderr)
        sys.exit(1)

    host = sys.argv[1]
    port = sys.argv[2]
    username = sys.argv[3]
    password = sys.argv[4]
    onu_refs_json = sys.argv[5] if len(sys.argv) > 5 else None

    # Parse ONU refs from JSON or read from stdin
    if onu_refs_json:
        onu_refs = json.loads(onu_refs_json)
    else:
        onu_refs = json.loads(sys.stdin.read())

    if not onu_refs:
        print(json.dumps({"onus": {}, "error": "No ONU refs provided"}))
        sys.exit(0)

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
        "-o KexAlgorithms=diffie-hellman-group14-sha1 "
        "-o Ciphers=aes128-cbc "
        "-o HostKeyAlgorithms=ssh-rsa "
        "-o PubkeyAcceptedAlgorithms=ssh-rsa "
        f"-p {port} {username}@{host}"
    )

    child = pexpect.spawn(ssh_command, encoding="utf-8", timeout=15)
    child.delaybeforesend = 0.05

    try:
        # Login sequence
        for attempt in range(6):
            i = child.expect([r"[Pp]assword:", r"Username:", r"#", pexpect.TIMEOUT, pexpect.EOF], timeout=10)
            if i == 0:
                child.sendline(password)
            elif i == 1:
                child.sendline(username)
            elif i == 2:
                break
            else:
                continue

        # terminal length 0
        run_cmd(child, "terminal length 0", timeout=3)

        # Collect attenuation for each ONU
        results = {}
        collected = 0
        errors = 0
        start_time = time.time()

        for onu_ref in onu_refs:
            # onu_ref format: "1/2/1:1" → command: "gpon-onu_1/2/1:1"
            cmd_ref = f"gpon-onu_{onu_ref}"
            cmd = f"show pon power attenuation {cmd_ref}"
            
            try:
                output = run_cmd(child, cmd, timeout=5)
                parsed = parse_attenuation(output)
                
                if parsed:
                    results[onu_ref] = parsed
                    collected += 1
                else:
                    # Invalid/unknown ONU ref — skip
                    errors += 1
            except Exception as e:
                errors += 1

        elapsed = time.time() - start_time

        print(json.dumps({
            "onus": results,
            "stats": {
                "total": len(onu_refs),
                "collected": collected,
                "errors": errors,
                "elapsed_seconds": round(elapsed, 1),
            }
        }))

    except Exception as e:
        print(json.dumps({"onus": {}, "error": str(e)}))
        sys.exit(1)
    finally:
        try:
            child.sendline("exit")
            child.close()
        except Exception:
            pass


if __name__ == "__main__":
    main()

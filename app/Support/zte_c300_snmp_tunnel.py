#!/usr/bin/env python3
"""
ZTE C300 SNMP RX via SSH + dual socat relay (UDP client <-> TCP tunnel <-> UDP on OLT).

SSH -L is TCP-only; remote socat bridges TCP to UDP:161 on the OLT.
"""
import json
import os
import sys
import time
import subprocess
import pexpect
from shutil import which as shutil_which


def main():
    host = os.environ.get("OLT_HOST", "")
    port = os.environ.get("OLT_PORT", "22")
    username = os.environ.get("OLT_USERNAME", "")
    password = os.environ.get("OLT_PASSWORD", "")
    # Per-OLT community comes from the caller (SNMP_COMMUNITY). OLT_SNMP_COMMUNITY is the
    # shared fallback (same env as Laravel services.olt_snmp.community). There is no
    # built-in value: without either env the helper fails closed.
    community = os.environ.get("SNMP_COMMUNITY")
    if community is None:
        community = os.environ.get("OLT_SNMP_COMMUNITY") or ""
        if not community:
            print(json.dumps({"ok": False, "error": "SNMP_COMMUNITY or OLT_SNMP_COMMUNITY required"}))
            sys.exit(1)
    local_udp_port = os.environ.get("SNMP_TUNNEL_PORT", "11600")
    local_tcp_port = str(int(local_udp_port) + 100)
    walk_timeout = int(os.environ.get("SNMP_WALK_TIMEOUT", "120"))

    if not shutil_which("socat"):
        print(json.dumps({"ok": False, "error": "socat not installed (required for SNMP UDP tunnel)"}))
        sys.exit(1)

    if not all([host, username, password]):
        print(json.dumps({"ok": False, "error": "Missing OLT_HOST/USERNAME/PASSWORD"}))
        sys.exit(1)

    remote_socat = (
        f"socat TCP4-LISTEN:{local_tcp_port},bind=127.0.0.1,fork,reuseaddr "
        f"UDP4:127.0.0.1:161"
    )

    ssh_cmd = (
        f"ssh -tt -L {local_tcp_port}:127.0.0.1:{local_tcp_port} -p {port} "
        "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "
        "-o PreferredAuthentications=keyboard-interactive,password "
        "-o PubkeyAuthentication=no -o PasswordAuthentication=yes "
        "-o ConnectTimeout=15 -o ServerAliveInterval=10 "
        "-o KexAlgorithms=diffie-hellman-group14-sha1 "
        "-o Ciphers=aes128-cbc -o HostKeyAlgorithms=ssh-rsa "
        "-o PubkeyAcceptedAlgorithms=ssh-rsa "
        f"{username}@{host} {remote_socat}"
    )

    tunnel = pexpect.spawn(ssh_cmd, encoding="utf-8", timeout=25)
    tunnel.delaybeforesend = 0.05
    local_socat = None

    try:
        password_sent = False
        for _ in range(6):
            idx = tunnel.expect([
                r"yes/no", r"[Pp]assword:", pexpect.TIMEOUT, pexpect.EOF
            ], timeout=25)
            if idx == 0:
                tunnel.sendline("yes")
                continue
            if idx == 1:
                tunnel.sendline(password)
                password_sent = True
                time.sleep(3)
                break
            if idx == 2 and password_sent:
                break
            if idx == 3:
                break

        if not tunnel.isalive():
            hint = (tunnel.before or "")[-240:]
            print(json.dumps({"ok": False, "error": "SSH tunnel failed", "ssh_hint": hint}))
            sys.exit(1)

        time.sleep(2)

        local_socat_cmd = (
            f"socat UDP4-LISTEN:{local_udp_port},bind=127.0.0.1,fork "
            f"TCP4:127.0.0.1:{local_tcp_port}"
        )
        local_socat = subprocess.Popen(
            local_socat_cmd, shell=True,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
        )
        time.sleep(1)

        probe = subprocess.run(
            ["snmpget", "-v2c", "-c", community, "-t", "5", "-r", "1",
             f"udp:127.0.0.1:{local_udp_port}", "1.3.6.1.2.1.1.1.0"],
            capture_output=True, text=True, timeout=15
        )
        if probe.returncode != 0 or "No Response" in (probe.stderr or ""):
            print(json.dumps({
                "ok": False,
                "error": "SNMP probe failed on tunnel",
                "snmp_stderr": (probe.stderr or probe.stdout or "")[:300],
                "udp_port": local_udp_port,
                "tcp_port": local_tcp_port,
            }))
            sys.exit(1)

        oids = [
            "1.3.6.1.4.1.3902.1012.3.28.1.2",
            "1.3.6.1.4.1.3902.1015.1010.11.1.2",
        ]

        all_rx = {}
        snmp_errors = []
        for oid in oids:
            try:
                proc = subprocess.run(
                    ["snmpwalk", "-v2c", "-c", community,
                     f"udp:127.0.0.1:{local_udp_port}", oid],
                    capture_output=True, text=True, timeout=walk_timeout
                )
                if proc.stderr:
                    snmp_errors.append(proc.stderr.strip()[:200])
                if proc.stdout and "No Response" not in (proc.stderr or ""):
                    for line in proc.stdout.strip().split("\n"):
                        if not line.strip():
                            continue
                        parts = line.split("=", 1)
                        if len(parts) != 2:
                            continue
                        oid_full = parts[0].strip()
                        value = parts[1].strip()
                        base = oid.split(".")
                        idx_parts = oid_full.split(".")
                        idx_key = ".".join(idx_parts[len(base):])

                        rx = None
                        if "INTEGER" in value:
                            try:
                                num = int(value.split(":")[-1].strip())
                                rx = num / 10.0
                            except (ValueError, IndexError):
                                pass
                        elif value.replace("-", "").replace(".", "").isdigit():
                            try:
                                rx = float(value)
                            except ValueError:
                                pass

                        if rx is not None and idx_key:
                            all_rx[idx_key] = rx

                    if all_rx:
                        break
            except (subprocess.TimeoutExpired, FileNotFoundError):
                continue

        if not all_rx:
            print(json.dumps({
                "ok": False,
                "error": "No SNMP RX data",
                "snmp_errors": snmp_errors[:3],
                "probe_ok": True,
            }))
            sys.exit(1)

        print(json.dumps({"ok": True, "rx_powers": all_rx, "count": len(all_rx)}))

    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        sys.exit(1)
    finally:
        if local_socat:
            local_socat.kill()
        try:
            tunnel.close(force=True)
        except Exception:
            pass


if __name__ == "__main__":
    main()
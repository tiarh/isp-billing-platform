#!/usr/bin/env python3
"""
ZTE C300 ONU RX + state via SNMP — efficient path (enterprise).

Problem with old approach: full-tree snmpbulkwalk of RX OID ≈ 125s for 611 vars
on OLT RA (~5 vars/s). That can never meet a sub-minute SLA.

New approach (NMS-style, aligned with Cepat-Kilat OID layout):
  1) STATUS walk once (full tree is FAST ~8s on this OLT)
  2) snmpget RX only for ONLINE ONUs, in batches (no full RX tree walk)
  3) Optional per-PON bulkwalk fallback if snmpget coverage thin

OID ref: Cepat-Kilat-Teknologi/snmp-olt-zte
  RX     = 1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10.{ifIndex}.{onuId}.1
  STATUS = 1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4.{ifIndex}.{onuId}
  Decode = integer * 0.002 - 30

Modes (SNMP_MODE):
  status  — status walk only (live tick)
  read    — status + online snmpget RX (default READ tier, target <45s)
  gap       — snmpget only SNMP_GAP_INDICES (fast backfill)
  tree      — legacy full RX tree walk (slow, emergency only)
  inventory — status + name + description + serial + distance walks (no RX).
              Replaces the SSH inventory on OLT RA (verified 2026-10-08:
              1223 ONUs in ~6 s, name/serial/phase equal to the CLI).
"""
import json
import os
import shutil
import subprocess
import sys
import time
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor, as_completed

RX_OID = "1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10"
STATUS_OID = "1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4"
# Same fast index space as STATUS (ifIndex.onuId), not the slow RX tree.
# Source: Cepat-Kilat OnuSerialNumberPrefix / OnuGponOpticalDistancePrefix,
# the layout this script already uses for RX and status.
SERIAL_OID = "1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.18"
DISTANCE_OID = "1.3.6.1.4.1.3902.1082.500.10.2.3.10.1.2"
# ONU name / description: same index space (verified on OLT RA 2026-10-08;
# the 3902.1012.3.28.* candidates return No Such Instance on this firmware).
NAME_OID = "1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.2"
DESCRIPTION_OID = "1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.3"
SENTINEL_INTS = {0, 255, 65533, 65534, 65535}
STATUS_MAP = {
    1: "logging",
    2: "los",
    3: "sync",
    4: "online",
    5: "dyinggasp",
    6: "auth_failed",
    7: "offline",
}


def _which(name):
    return shutil.which(name)


def _parse_walk_stdout(stdout, base_oid):
    results = {}
    base_len = len(base_oid.split("."))
    for line in (stdout or "").strip().split("\n"):
        if not line.strip() or "=" not in line:
            continue
        oid_full, value = line.strip().split("=", 1)
        oid_full = oid_full.strip().lstrip(".")
        idx_parts = oid_full.split(".")[base_len:]
        if not idx_parts:
            continue
        snmp_index = ".".join(x for x in idx_parts if x != "")
        results[snmp_index] = value.strip()
    return results


def _run_walk_to_file(cmd, oid, timeout):
    """Run snmp*walk writing to a temp file (survives kill with partial blocks)."""
    import tempfile

    path = None
    try:
        fd, path = tempfile.mkstemp(prefix="snmpwalk_", suffix=".out")
        os.close(fd)
        with open(path, "wb") as out:
            try:
                proc = subprocess.Popen(cmd, stdout=out, stderr=subprocess.DEVNULL)
            except FileNotFoundError:
                return {}, "missing"
            timed_out = False
            try:
                proc.wait(timeout=timeout)
            except subprocess.TimeoutExpired:
                timed_out = True
                try:
                    proc.kill()
                except Exception:
                    pass
                try:
                    proc.wait(timeout=3)
                except Exception:
                    pass
        with open(path, "r", errors="ignore") as fh:
            out_text = fh.read()
        tool = "snmp"
        for part in cmd:
            if "snmp" in part:
                tool = part
                break
        if timed_out:
            tool = "%s:partial" % tool
        return _parse_walk_stdout(out_text, oid), tool
    finally:
        if path:
            try:
                os.unlink(path)
            except Exception:
                pass


def snmp_walk(
    host,
    community,
    oid,
    port,
    timeout,
    bulk_reps=15,
    min_complete=0,
    extra_flags=None,
    stop_on_complete_bulk=False,
):
    """Single OID walk. Prefer bulkwalk. Returns partial on timeout.

    min_complete: if >0 and result reaches this count, stop trying fallback tools.
    extra_flags: additional net-snmp output flags (e.g. -Oa for ASCII strings).
    stop_on_complete_bulk: a bulkwalk that finished (not partial) is final.
    """
    target = "udp:%s:%s" % (host, port)
    # -t 2 -r 1: fail fast on dead branches; -Cr15 stable on C300
    common = ["-v2c", "-c", community, "-On", "-Cc", "-t", "2", "-r", "1"]
    if extra_flags:
        common.extend(extra_flags)
    reps = max(5, min(20, int(bulk_reps)))
    cmds = []
    if _which("snmpbulkwalk"):
        cmds.append(["snmpbulkwalk", *common, "-Cr%d" % reps, target, oid])
    if _which("snmpwalk"):
        cmds.append(["snmpwalk", *common, target, oid])
    if not cmds:
        return {}, "no_tools"

    best = {}
    tool = "empty"
    for cmd in cmds:
        parsed, this_tool = _run_walk_to_file(cmd, oid, timeout)
        if len(parsed) > len(best):
            best, tool = parsed, this_tool
        if min_complete > 0 and len(best) >= min_complete:
            return best, tool
        if stop_on_complete_bulk and "bulk" in this_tool and "partial" not in this_tool and best:
            return best, tool
        if "bulk" in this_tool and "partial" not in this_tool and len(best) >= 400:
            return best, tool
    return best, tool


def snmpget_batch(host, community, full_oids, port, timeout, batch_size=50):
    """GET many OIDs in batches — much faster than walking the whole RX tree."""
    if not full_oids:
        return {}, "empty"
    if not _which("snmpget"):
        return {}, "no_snmpget"

    target = "udp:%s:%s" % (host, port)
    results = {}
    t0 = time.monotonic()
    batch_size = max(10, min(60, int(batch_size)))

    for i in range(0, len(full_oids), batch_size):
        if time.monotonic() - t0 > timeout:
            break
        chunk = full_oids[i : i + batch_size]
        budget = max(3, min(12, int(timeout - (time.monotonic() - t0))))
        cmd = [
            "snmpget",
            "-v2c",
            "-c",
            community,
            "-On",
            "-t",
            "2",
            "-r",
            "1",
            target,
        ] + list(chunk)
        try:
            proc = subprocess.run(cmd, capture_output=True, text=True, timeout=budget)
            out = proc.stdout or ""
        except subprocess.TimeoutExpired as exc:
            out = ""
            if getattr(exc, "stdout", None):
                out = exc.stdout if isinstance(exc.stdout, str) else exc.stdout.decode("utf-8", "ignore")
        except FileNotFoundError:
            return results, "no_snmpget"

        for line in out.strip().split("\n"):
            if not line.strip() or "=" not in line:
                continue
            oid_full, value = line.strip().split("=", 1)
            oid_full = oid_full.strip().lstrip(".")
            if oid_full.startswith(RX_OID + "."):
                idx = oid_full[len(RX_OID) + 1 :]
                results[idx] = value.strip()

    return results, "snmpget_batch"


def snmp_index_to_onu_ref(snmp_index):
    parts = [int(x) for x in snmp_index.split(".") if x != ""]
    if len(parts) < 2:
        return None
    if_index = parts[0]
    onu_id = parts[1] if len(parts) >= 3 else parts[-1]
    slot = (if_index >> 8) & 0xFF
    port = if_index & 0xFF
    if onu_id < 1 or slot < 1 or port < 1 or slot > 31 or port > 31:
        return None
    return f"1/{slot}/{port}:{onu_id}"


def is_plausible_rx_dbm(rx):
    # GPON optical window; do NOT gate on distance_m (3–4km can still be -17..-21).
    # Lower bound -42: some online ONUs report ~-40 (CLI-verified, still carries traffic).
    return -42.0 <= rx <= -8.0


def decode_zte_rx_power(value_str):
    """Decode ZTE optical INTEGER → dBm.

    Primary scale (Cepat-Kilat): raw * 0.002 - 30.

    High raw (e.g. 64032, 64761, 65306, 60536) are uint16 encodings of negative
    int16 — CLI `show pon power attenuation` matches:
      64032 → -1504 → -33.01 dBm, 60536 → -5000 → -40.00 dBm.
    """
    value_str = (value_str or "").strip()
    if "INTEGER" not in value_str:
        try:
            val = float(value_str)
            if is_plausible_rx_dbm(val):
                return val, None
            return None, f"implausible_{val}"
        except ValueError:
            return None, "parse_error"
    try:
        num = int(value_str.split(":")[-1].strip())
    except (ValueError, IndexError):
        return None, "parse_error"
    if num in SENTINEL_INTS:
        return None, "sentinel"
    # uint16 two's-complement optical (CLI residual probe 2026-07-12)
    if num > 32767:
        num = num - 65536
        rx = round(num * 0.002 - 30.0, 2)
    elif num > 0:
        rx = round(num * 0.002 - 30.0, 2)
    else:
        rx = round(num / 10.0, 2)
    if is_plausible_rx_dbm(rx):
        return rx, None
    return None, f"implausible_{rx}"


def decode_zte_distance_raw(value_str):
    """ZTE optical distance is metres on the status-index table."""
    value_str = (value_str or "").strip()
    if ":" in value_str:
        value_str = value_str.split(":")[-1].strip()
    try:
        num = int(float(value_str))
    except ValueError:
        return None
    if num in SENTINEL_INTS or num <= 0 or num > 40000:
        return None
    return num


def decode_zte_serial_raw(value_str):
    """Accept ASCII vendor serials and 8-byte Hex-STRING (4 ASCII + hex tail)."""
    import re

    value_str = (value_str or "").strip()
    if not value_str:
        return None
    if "HEX-STRING" in value_str.upper():
        hex_part = value_str.split(":", 1)[-1]
        try:
            raw = bytes(int(part, 16) for part in hex_part.split() if part)
        except ValueError:
            return None
        if len(raw) < 4:
            return None
        vendor = raw[:4].decode("ascii", "ignore")
        if re.fullmatch(r"[A-Za-z0-9]{4}", vendor or ""):
            serial = (vendor + raw[4:].hex()).upper()
        else:
            serial = raw.decode("ascii", "ignore").strip().upper()
    else:
        serial = re.sub(r"^(?:STRING|OCTET STRING):\s*", "", value_str, flags=re.I)
        serial = serial.strip().strip('"')
        # C300 v2.1 reports "1,FHTTxxxxxxxx": drop the leading "<n>," field.
        serial = re.sub(r"^\s*\d+,", "", serial)
        serial = serial.upper().replace("-", "")
    serial = re.sub(r"[^A-Z0-9]", "", serial or "")
    if re.fullmatch(r"[A-Z0-9]{8,16}", serial or ""):
        return serial
    return None


def _blank_onu(snmp_index):
    return {
        "rx_dbm": None,
        "snmp_index": snmp_index,
        "oid": RX_OID,
        "raw": None,
        "state": None,
        "status_code": None,
    }


def attach_fast_identity(onus, host, community, port, bulk_reps, budget):
    """Walk serial + distance. These tables are fast; do not touch the RX tree."""
    if budget < 4:
        return None, None
    each = max(4, min(8, int(budget) // 2))
    serial_raw, serial_tool = snmp_walk(
        host, community, SERIAL_OID, port, each, bulk_reps, min_complete=0
    )
    distance_raw, distance_tool = snmp_walk(
        host, community, DISTANCE_OID, port, each, bulk_reps, min_complete=0
    )
    for idx, val in serial_raw.items():
        onu_ref = snmp_index_to_onu_ref(idx)
        serial = decode_zte_serial_raw(val)
        if not onu_ref or not serial:
            continue
        entry = onus.get(onu_ref) or _blank_onu(idx)
        entry["serial"] = serial
        onus[onu_ref] = entry
    for idx, val in distance_raw.items():
        onu_ref = snmp_index_to_onu_ref(idx)
        distance = decode_zte_distance_raw(val)
        if not onu_ref or distance is None:
            continue
        entry = onus.get(onu_ref) or _blank_onu(idx)
        entry["distance_m"] = distance
        onus[onu_ref] = entry
    return serial_tool, distance_tool


def decode_snmp_text(value_str):
    """STRING / Hex-STRING value -> text (names may carry non-ASCII bytes)."""
    import re

    value_str = (value_str or "").strip()
    if not value_str:
        return None
    if value_str.upper().startswith("HEX-STRING"):
        hex_part = value_str.split(":", 1)[-1]
        try:
            raw = bytes(int(part, 16) for part in hex_part.split() if part)
        except ValueError:
            return None
        text = raw.decode("utf-8", "replace")
    else:
        text = re.sub(r"^(?:STRING|OCTET STRING):\s*", "", value_str, flags=re.I)
        text = text.strip()
        if len(text) >= 2 and text[0] == '"' and text[-1] == '"':
            text = text[1:-1]
        text = text.replace('\\"', '"')
    text = text.replace("\x00", "").strip()
    return text or None


def collect_inventory(host, community, port, timeout, bulk_reps):
    """Inventory walks in the fast status index space (no RX tree).

    STATUS defines the ONU set; name / description / serial / distance are
    merged onto it. stats.status_partial tells the caller whether the ONU set
    is complete (a partial set must never drive an inventory purge).
    """
    oids = {
        "status": os.environ.get("SNMP_INV_STATUS_OID") or STATUS_OID,
        "name": os.environ.get("SNMP_INV_NAME_OID") or NAME_OID,
        "description": os.environ.get("SNMP_INV_DESCRIPTION_OID") or DESCRIPTION_OID,
        "serial": os.environ.get("SNMP_INV_SERIAL_OID") or SERIAL_OID,
        "distance": os.environ.get("SNMP_INV_DISTANCE_OID") or DISTANCE_OID,
    }
    t0 = time.monotonic()
    tools = {}
    counts = {}
    raw = {}
    # Status first with the largest share (3.8 s for 1223 ONUs on OLT RA).
    order = ["status", "name", "serial", "description", "distance"]
    for i, field in enumerate(order):
        remain = timeout - (time.monotonic() - t0)
        if remain < 3:
            tools[field] = "skipped:budget"
            raw[field] = {}
            counts[field] = 0
            continue
        share = remain * 0.5 if field == "status" else remain / max(1, len(order) - i)
        budget = max(3, int(share))
        flags = ["-Oa"] if field in ("name", "description") else None
        raw[field], tools[field] = snmp_walk(
            host,
            community,
            oids[field],
            port,
            budget,
            bulk_reps,
            extra_flags=flags,
            stop_on_complete_bulk=True,
        )
        counts[field] = len(raw[field])

    onus = {}
    undecoded = 0
    for idx, val in raw["status"].items():
        onu_ref = snmp_index_to_onu_ref(idx)
        if not onu_ref:
            undecoded += 1
            continue
        state, code = decode_zte_status(val)
        onus[onu_ref] = {
            "snmp_index": idx,
            "state": state,
            "status_code": code,
        }

    def merge(field, decode, key):
        for idx, val in raw[field].items():
            onu_ref = snmp_index_to_onu_ref(idx)
            if not onu_ref or onu_ref not in onus:
                continue
            decoded = decode(val)
            if decoded is not None:
                onus[onu_ref][key] = decoded

    merge("name", decode_snmp_text, "name")
    merge("description", decode_snmp_text, "description")
    merge("serial", decode_zte_serial_raw, "serial")
    merge("distance", decode_zte_distance_raw, "distance_m")

    stats = {
        "mode": "inventory",
        "tools": tools,
        "walk_counts": counts,
        "status_partial": "partial" in str(tools.get("status", "")) or not raw["status"],
        "status_undecoded": undecoded,
        "mapped": len(onus),
        "status_online": sum(1 for i in onus.values() if i.get("status_code") == 4),
        "with_name": sum(1 for i in onus.values() if i.get("name")),
        "with_serial": sum(1 for i in onus.values() if i.get("serial")),
        "with_distance": sum(1 for i in onus.values() if i.get("distance_m")),
        "wall_sec": round(time.monotonic() - t0, 2),
    }
    return onus, stats


def decode_zte_status(value_str):
    value_str = (value_str or "").strip()
    try:
        if "INTEGER" in value_str:
            num = int(value_str.split(":")[-1].strip())
        else:
            num = int(value_str)
    except (ValueError, IndexError):
        return None, None
    return STATUS_MAP.get(num), num


def ensure_rx_index(snmp_index):
    """Status index is often ifIndex.onuId ; RX table uses ifIndex.onuId.1"""
    parts = [p for p in snmp_index.split(".") if p]
    if len(parts) == 2:
        return f"{parts[0]}.{parts[1]}.1"
    return snmp_index


def merge_status(onus, snmp_index, value):
    onu_ref = snmp_index_to_onu_ref(snmp_index)
    if not onu_ref:
        return
    state, code = decode_zte_status(value)
    entry = onus.get(onu_ref) or {
        "rx_dbm": None,
        "snmp_index": ensure_rx_index(snmp_index),
        "oid": RX_OID,
        "raw": None,
        "state": None,
        "status_code": None,
    }
    entry["snmp_index"] = ensure_rx_index(snmp_index)
    if state:
        entry["state"] = state
        entry["status_code"] = code
    onus[onu_ref] = entry


def merge_rx(onus, snmp_index, value, reject_counts, decode_rejected):
    onu_ref = snmp_index_to_onu_ref(snmp_index)
    if not onu_ref:
        return
    rx, reason = decode_zte_rx_power(value)
    entry = onus.get(onu_ref) or {
        "rx_dbm": None,
        "snmp_index": snmp_index,
        "oid": RX_OID,
        "raw": value.strip() if value else None,
        "state": None,
        "status_code": None,
    }
    entry["snmp_index"] = snmp_index
    if rx is not None:
        entry["rx_dbm"] = rx
        entry["oid"] = RX_OID
        entry.pop("reject_reason", None)
    elif reason:
        entry["reject_reason"] = reason
        entry["raw"] = value.strip() if value else None
        reject_counts[reason] = reject_counts.get(reason, 0) + 1
        if len(decode_rejected) < 30:
            decode_rejected.append(
                {
                    "onu_ref": onu_ref,
                    "snmp_index": snmp_index,
                    "raw": value.strip() if value else None,
                    "reason": reason,
                }
            )
    onus[onu_ref] = entry


def walk_status(host, community, port, timeout, bulk_reps):
    # Status tree completes ~611; allow early stop once full-ish.
    raw, tool = snmp_walk(
        host, community, STATUS_OID, port, timeout, bulk_reps, min_complete=500
    )
    onus = {}
    for idx, val in raw.items():
        merge_status(onus, idx, val)
    return onus, tool, raw


def apply_rx_map(onus, raw_rx, reject_counts, decode_rejected):
    for idx, val in raw_rx.items():
        merge_rx(onus, idx, val, reject_counts, decode_rejected)


def collect_rx_via_get(host, community, port, onus, timeout, online_only=True):
    """snmpget RX OIDs for (online) ONUs already known from status."""
    oids = []
    for ref, info in onus.items():
        if online_only and info.get("state") != "online":
            continue
        idx = info.get("snmp_index")
        if not idx:
            continue
        oids.append("%s.%s" % (RX_OID, ensure_rx_index(idx)))
    seen = set()
    uniq = []
    for o in oids:
        if o not in seen:
            seen.add(o)
            uniq.append(o)
    return snmpget_batch(host, community, uniq, port, timeout, batch_size=50)


def _walk_one_pon(args):
    host, community, port, if_index, pon_budget, bulk_reps = args
    oid = "%s.%s" % (RX_OID, if_index)
    raw, tool = snmp_walk(host, community, oid, port, pon_budget, bulk_reps)
    merged = {}
    for sub, val in raw.items():
        if not str(sub).startswith(str(if_index)):
            full_idx = "%s.%s" % (if_index, sub)
        else:
            full_idx = sub
        merged[full_idx] = val
    return if_index, merged, tool, len(raw)


def collect_rx_per_pon(host, community, port, onus, timeout, bulk_reps, workers=3, rotate_offset=0):
    """
    Parallel per-PON GETBULK (Cepat-Kilat style board/pon scoping).

    Full-tree walk is ~125s on this OLT. Per-PON subtrees finish cleanly;
    3 workers cut wall time ~3x when the agent tolerates limited concurrency
    on *different* ifIndex roots (unlike concurrent full RX+status).
    """
    by_if = defaultdict(list)
    for ref, info in onus.items():
        idx = ensure_rx_index(str(info.get("snmp_index") or ""))
        parts = idx.split(".")
        if not parts or not parts[0].isdigit():
            continue
        by_if[int(parts[0])].append(ref)

    # Online-heavy PONs first; optional rotate for multi-cycle full coverage
    ordered = sorted(
        by_if.keys(),
        key=lambda i: -sum(1 for r in by_if[i] if onus.get(r, {}).get("state") == "online"),
    )
    if ordered and rotate_offset:
        n = len(ordered)
        off = int(rotate_offset) % n
        ordered = ordered[off:] + ordered[:off]

    workers = max(1, min(4, int(workers)))
    pon_budget = max(4, min(12, int(timeout // max(1, (len(ordered) + workers - 1) // workers))))
    # Cap per-PON so one slow PON cannot burn whole budget
    pon_budget = min(10, max(5, pon_budget))

    merged = {}
    tools = []
    done_pons = 0
    t0 = time.monotonic()

    # Submit in waves of `workers` to bound load on OLT
    for wave_start in range(0, len(ordered), workers):
        if time.monotonic() - t0 >= timeout:
            break
        wave = ordered[wave_start : wave_start + workers]
        remain = max(4, int(timeout - (time.monotonic() - t0)))
        wave_budget = min(pon_budget, remain)
        jobs = [
            (host, community, port, if_index, wave_budget, bulk_reps) for if_index in wave
        ]
        with ThreadPoolExecutor(max_workers=len(jobs)) as pool:
            futs = [pool.submit(_walk_one_pon, j) for j in jobs]
            for fut in as_completed(futs):
                try:
                    if_index, part, tool, n = fut.result()
                except Exception:
                    continue
                merged.update(part)
                tools.append("%s:%s" % (if_index, tool))
                done_pons += 1

    return merged, "per_pon_x%d_pons%d" % (workers, done_pons)


def main():
    host = os.environ.get("OLT_HOST", "")
    # Per-OLT community comes from the caller (OLT_COMMUNITY). OLT_SNMP_COMMUNITY is the
    # shared fallback (same env as Laravel services.olt_snmp.community). There is no
    # built-in value: without either env the helper fails closed.
    community = os.environ.get("OLT_COMMUNITY")
    if community is None:
        community = os.environ.get("OLT_SNMP_COMMUNITY") or ""
        if not community:
            print(json.dumps({"ok": False, "error": "OLT_COMMUNITY or OLT_SNMP_COMMUNITY required"}))
            sys.exit(1)
    snmp_port = int(os.environ.get("OLT_SNMP_PORT", "161"))
    timeout = max(10, min(200, int(os.environ.get("SNMP_TIMEOUT", "45"))))
    bulk_reps = int(os.environ.get("SNMP_BULK_REPS", "15"))
    mode = (os.environ.get("SNMP_MODE") or "").strip().lower()
    # Back-compat flags
    if not mode:
        if os.environ.get("SNMP_STATUS_ONLY", "0") in ("1", "true", "yes"):
            mode = "status"
        elif os.environ.get("SNMP_GAP_INDICES"):
            mode = "gap"
        else:
            mode = "read"

    if not host:
        print(json.dumps({"ok": False, "error": "OLT_HOST required"}))
        sys.exit(1)

    t0 = time.monotonic()
    onus = {}
    reject_counts = {}
    decode_rejected = []
    tool_status = None
    tool_rx = None
    raw_rx = {}

    if mode == "inventory":
        inv_onus, inv_stats = collect_inventory(host, community, snmp_port, timeout, bulk_reps)
        print(json.dumps({"ok": bool(inv_onus), "onus": inv_onus, "stats": inv_stats}))
        sys.exit(0 if inv_onus else 1)

    if mode == "gap":
        indices = [x.strip() for x in os.environ.get("SNMP_GAP_INDICES", "").split(",") if x.strip()]
        oids = ["%s.%s" % (RX_OID, ensure_rx_index(i)) for i in indices]
        raw_rx, tool_rx = snmpget_batch(host, community, oids, snmp_port, timeout, batch_size=40)
        for idx, val in raw_rx.items():
            merge_rx(onus, idx, val, reject_counts, decode_rejected)
    elif mode == "tree":
        # Serial and distance share the fast status index. Take them first so a
        # long RX tree cannot starve them, while still leaving the tree most of
        # the budget.
        attach_fast_identity(onus, host, community, snmp_port, bulk_reps, min(16, timeout))
        remain = max(20, int(timeout - (time.monotonic() - t0)))
        raw_rx, tool_rx = snmp_walk(
            host, community, RX_OID, snmp_port, remain, bulk_reps, min_complete=0
        )
        for idx, val in raw_rx.items():
            merge_rx(onus, idx, val, reject_counts, decode_rejected)
    else:
        # status (+ optional RX)
        status_budget = min(20, timeout if mode == "status" else max(10, timeout // 3))
        onus, tool_status, _raw_st = walk_status(
            host, community, snmp_port, status_budget, bulk_reps
        )
        if mode == "read":
            remain = timeout - (time.monotonic() - t0)
            attach_fast_identity(
                onus, host, community, snmp_port, bulk_reps, min(16, remain)
            )

        if mode == "read" and onus:
            # Primary: parallel per-PON GETBULK (measured faster + complete vs snmpget).
            # snmpget batch on this OLT is NOT faster than walk (~same vars/s) and truncates.
            rx_budget = max(15, timeout - int(time.monotonic() - t0) - 1)
            workers = int(os.environ.get("SNMP_PON_WORKERS", "3") or 3)
            rotate = int(os.environ.get("SNMP_PON_ROTATE", "0") or 0)
            raw_rx, tool_rx = collect_rx_per_pon(
                host,
                community,
                snmp_port,
                onus,
                rx_budget,
                bulk_reps,
                workers=workers,
                rotate_offset=rotate,
            )
            apply_rx_map(onus, raw_rx, reject_counts, decode_rejected)

            # Optional: snmpget only still-missing online (gap fill) if budget left
            remain = timeout - (time.monotonic() - t0)
            missing_online = [
                r
                for r, i in onus.items()
                if i.get("state") == "online" and i.get("rx_dbm") is None and i.get("snmp_index")
            ]
            if remain >= 8 and missing_online:
                gap_onus = {r: onus[r] for r in missing_online[:120]}
                raw2, tool2 = collect_rx_via_get(
                    host, community, snmp_port, gap_onus, int(remain), online_only=True
                )
                apply_rx_map(onus, raw2, reject_counts, decode_rejected)
                tool_rx = "%s+%s" % (tool_rx, tool2)

    if not onus:
        print(
            json.dumps(
                {
                    "ok": False,
                    "error": "No SNMP ONU data",
                    "stats": {"mode": mode, "tool_status": tool_status, "tool_rx": tool_rx},
                }
            )
        )
        sys.exit(1)

    rx_powers = {r: i["rx_dbm"] for r, i in onus.items() if i.get("rx_dbm") is not None}
    online_no_rx = [
        r for r, i in onus.items() if i.get("state") == "online" and i.get("rx_dbm") is None
    ]
    status_online = sum(1 for i in onus.values() if i.get("state") == "online")

    print(
        json.dumps(
            {
                "ok": True,
                "onus": onus,
                "rx_powers": rx_powers,
                "count": len(rx_powers),
                "stats": {
                    "mode": mode,
                    "tool_status": tool_status,
                    "tool_rx": tool_rx,
                    "oid": RX_OID,
                    "status_oid": STATUS_OID,
                    "decode": "cepatt_kilat_mul_0.002_minus_30",
                    "snmp_entries": len(raw_rx) if raw_rx else len(onus),
                    "status_entries": sum(1 for i in onus.values() if i.get("state")),
                    "status_online": status_online,
                    "mapped": len(onus),
                    "with_rx": len(rx_powers),
                    "with_serial": sum(1 for i in onus.values() if i.get("serial")),
                    "with_distance": sum(1 for i in onus.values() if i.get("distance_m")),
                    "mapped_null_rx": len(onus) - len(rx_powers),
                    "online_no_rx": len(online_no_rx),
                    "online_no_rx_sample": online_no_rx[:20],
                    "reject_counts": reject_counts,
                    "decode_rejected_sample": decode_rejected[:15],
                    "wall_sec": round(time.monotonic() - t0, 2),
                },
            }
        )
    )


if __name__ == "__main__":
    main()

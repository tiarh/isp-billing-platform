"""tests.fakes — Python fake-devices for OLT CLI provider boundary tests.

This package contains hand-rolled fake devices used by Phase C feature
tests to assert that injection vectors (shell metacharacters, quote
breaks, length overruns, vendor-glue strings, CRLF injection) cannot
reach an emulated OLT CLI surface. The fakes intentionally only enforce
the universal dangerous-pattern check from Phase B-Skeleton; vendor-
specific positional limits are [VENDOR-RULE-TBD] until operator-sourced
CLI specs are loaded.

Never run these fake devices outside the test runtime.
"""
from __future__ import annotations

from pathlib import Path

PACKAGE_ROOT: Path = Path(__file__).resolve().parent
LOGS_DIR: Path = PACKAGE_ROOT / "logs"
LOGS_DIR.mkdir(parents=True, exist_ok=True)


class DefaultPorts:
    ZTE_C300: int = 16261
    FIBERHOME: int = 16262
    MIKROTIK: int = 8729  # legacy API port, useful for partial recon tests


PACKAGE_INFO = {
    "package": "tests.fakes",
    "vendor_rules": "[VENDOR-RULE-TBD]",
    "phase": "C",
    "audit_link": "audit-ebilling-fullstack-code-quality-2026-08-31.pdf sec 13",
}

__all__ = ["PACKAGE_ROOT", "LOGS_DIR", "PACKAGE_INFO", "DefaultPorts"]

#!/bin/bash
# Wrapper that calls the Python pexpect collector
# Usage: ./collect_olt.sh <host> <port> <username> <password> <commands_json>
# Commands are passed as JSON array, e.g. '["show gpon onu state","show gpon onu by pon 1/2/1"]'

HOST="$1"
PORT="$2"
USERNAME="$3"
PASSWORD="$4"
shift 4
COMMANDS="$*"

export OLT_HOST="$HOST"
export OLT_PORT="$PORT"
export OLT_USERNAME="$USERNAME"
export OLT_PASSWORD="$PASSWORD"
export OLT_COMMANDS="$COMMANDS"
export OLT_PROMPT='[#>]\s*$'

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
python3 "$SCRIPT_DIR/zte_c300_collect.py"
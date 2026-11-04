#!/usr/bin/env bash
# run_fakes.sh — spin up the Phase C fake OLT devices for the test runtime.
#
# Listens on a local interface (default 127.0.0.1) so the network is
# isolated from Tiar's production network. Each fake writes captured
# accepted commands to tests/fakes/logs/captured_<vendor>_cmds.jsonl for
# the matching feature test to assert on.
#
# Usage:
#   bash tests/fakes/run_fakes.sh up        # start all fakes in background
#   bash tests/fakes/run_fakes.sh down      # stop all fakes
#   bash tests/fakes/run_fakes.sh status    # show port bindings

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="${ROOT_DIR}/logs"
mkdir -p "${LOG_DIR}"

PID_DIR="${LOG_DIR}/.pid"
mkdir -p "${PID_DIR}"

HOST="${FAKE_OLT_HOST:-127.0.0.1}"
PORT_ZTE="${FAKE_OLT_PORT_ZTE_C300:-16261}"
PORT_FH="${FAKE_OLT_PORT_FIBERHOME:-16262}"

start_one() {
    local script="$1"
    local port="$2"
    local name="$3"
    local logfile="${LOG_DIR}/${name}.log"
    local pidfile="${PID_DIR}/${name}.pid"

    if [[ -f "${pidfile}" ]]; then
        if kill -0 "$(cat "${pidfile}")" 2>/dev/null; then
            echo "${name}: already running (pid=$(cat "${pidfile}"))"
            return 0
        fi
        rm -f "${pidfile}"
    fi

    echo "starting ${name} on ${HOST}:${port}"
    FAKE_OLT_HOST="${HOST}" FAKE_OLT_PORT="${port}" \
        nohup python3 "${script}" --host "${HOST}" --port "${port}" \
        > "${logfile}" 2>&1 &
    echo $! > "${pidfile}"
    sleep 0.2
}

stop_one() {
    local name="$1"
    local pidfile="${PID_DIR}/${name}.pid"
    if [[ ! -f "${pidfile}" ]]; then
        echo "${name}: no pidfile"
        return 0
    fi
    local pid
    pid="$(cat "${pidfile}")"
    if kill -0 "${pid}" 2>/dev/null; then
        echo "stopping ${name} (pid=${pid})"
        kill "${pid}" 2>/dev/null || true
        sleep 0.2
        kill -9 "${pid}" 2>/dev/null || true
    fi
    rm -f "${pidfile}"
}

status_one() {
    local name="$1"
    local pidfile="${PID_DIR}/${name}.pid"
    echo "${name}:"
    if [[ -f "${pidfile}" ]] && kill -0 "$(cat "${pidfile}")" 2>/dev/null; then
        echo "  status: running (pid=$(cat "${pidfile}"))"
    else
        echo "  status: stopped"
    fi
}

cmd="${1:-status}"
case "${cmd}" in
    up)
        start_one "${ROOT_DIR}/fake_zte_c300.py" "${PORT_ZTE}"  "zte_c300"
        start_one "${ROOT_DIR}/fake_fiberhome.py" "${PORT_FH}" "fiberhome"
        ;;
    down)
        stop_one "zte_c300"
        stop_one "fiberhome"
        ;;
    status)
        status_one "zte_c300"
        status_one "fiberhome"
        ;;
    *)
        echo "usage: $0 {up|down|status}"
        exit 64
        ;;
esac

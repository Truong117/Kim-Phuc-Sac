#!/bin/sh
# PID 1 owns all children. No migrations or provider calls run at boot.
set -u
cd "${KPS_APP_DIR:-/app}" || exit 1
script=$(realpath "$0")
child=
stopping=0

stop_child() {
    stopping=1
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null || :
    fi
}

pause() {
    sleep "$1" &
    child=$!
    wait "$child" || :
    if [ "$stopping" = 1 ]; then
        wait "$child" 2>/dev/null || :
    fi
    child=
}

case "${1:-service}" in
    worker|recovery)
        mode=$1
        trap stop_child TERM INT
        # On the first rollout, let the old unsupervised container leave the queue.
        pause "${WORK_REPORT_AI_BOOT_DELAY_SECONDS:-60}"
        while [ "$stopping" = 0 ]; do
            if [ "$mode" = worker ]; then
                php -d max_execution_time=0 artisan queue:work work_report_ai \
                    --queue=ai-work-reports --timeout=270 --tries=1 --sleep=1 &
            else
                php -d max_execution_time=0 artisan work-report-ai:recover &
            fi
            child=$!
            wait "$child" || :
            if [ "$stopping" = 1 ]; then
                # Laravel finishes its in-flight job after SIGTERM. Reap it before exit.
                wait "$child" 2>/dev/null || :
                exit 0
            fi
            child=
            if [ "$mode" = worker ]; then
                echo 'Work report AI worker exited; restarting.' >&2
                pause 2
            else
                pause 60
            fi
        done
        exit 0
        ;;
    service) ;;
    *) echo 'Unknown service mode.' >&2; exit 1 ;;
esac

php artisan config:cache || exit 1
worker=
recovery=
web=
monitor=
shutdown() {
    trap '' TERM INT
    [ -z "$monitor" ] || kill -TERM "$monitor" 2>/dev/null || :
    [ -z "$worker" ] || kill -TERM "$worker" 2>/dev/null || :
    [ -z "$recovery" ] || kill -TERM "$recovery" 2>/dev/null || :
    [ -z "$worker" ] || wait "$worker" 2>/dev/null || :
    [ -z "$recovery" ] || wait "$recovery" 2>/dev/null || :
    [ -z "$web" ] || kill -TERM "$web" 2>/dev/null || :
    [ -z "$web" ] || wait "$web" 2>/dev/null || :
    exit "$1"
}
trap 'shutdown 0' TERM INT

frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile &
web=$!
# Read effective Laravel configuration, including config:cache; never print secrets.
queue_enabled=$(php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $queue = config("work_report_ai.queue_connection") ?? (config("queue.default") === "sync" ? "sync" : "work_report_ai"); echo config("work_report_ai.enabled") && $queue === "work_report_ai" ? "yes" : "no";') || shutdown 1
case "${WORK_REPORT_AI_WORKER_MODE:-embedded}" in
    embedded)
        if [ "$queue_enabled" = yes ]; then
            sh "$script" worker &
            worker=$!
            sh "$script" recovery &
            recovery=$!
        fi
        ;;
    external|off) ;;
    *) echo 'Invalid WORK_REPORT_AI_WORKER_MODE.' >&2; shutdown 1 ;;
esac

while kill -0 "$web" 2>/dev/null; do
    # A failed supervisor loop must fail the container, allowing Render to restart it.
    if { [ -n "$worker" ] && ! kill -0 "$worker" 2>/dev/null; } || \
        { [ -n "$recovery" ] && ! kill -0 "$recovery" 2>/dev/null; }; then
        echo 'Work report AI supervisor exited unexpectedly.' >&2
        shutdown 1
    fi
    sleep 1 &
    monitor=$!
    wait "$monitor" || :
    monitor=
done
wait "$web"
status=$?
# Even an unexpected clean web exit should restart the service.
[ "$status" -ne 0 ] || status=1
shutdown "$status"

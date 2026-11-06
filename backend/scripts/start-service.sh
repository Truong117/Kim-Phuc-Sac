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
    worker|recovery|daily-worker|daily-recovery|daily-scheduler|social-worker)
        mode=$1
        trap stop_child TERM INT
        # On the first rollout, let the old unsupervised container leave the queue.
        case "$mode" in
            social-worker) pause "${SOCIAL_METRICS_BOOT_DELAY_SECONDS:-60}" ;;
            daily-*) pause "${DAILY_REPORT_AI_BOOT_DELAY_SECONDS:-60}" ;;
            *) pause "${WORK_REPORT_AI_BOOT_DELAY_SECONDS:-60}" ;;
        esac
        while [ "$stopping" = 0 ]; do
            case "$mode" in
                worker)
                    php -d max_execution_time=0 artisan queue:work work_report_ai \
                        --queue=ai-work-reports --timeout=270 --tries=1 --sleep=1 &
                    ;;
                daily-worker)
                    php -d max_execution_time=0 artisan queue:work daily_report_ai \
                        --queue=ai-daily-reports --timeout=270 --tries=1 --sleep=1 &
                    ;;
                social-worker)
                    php -d max_execution_time=0 artisan queue:work social_metrics \
                        --queue=social-metrics --timeout=300 --tries=1 --sleep=5 --memory=96 --max-time=3600 &
                    ;;
                daily-recovery) php -d max_execution_time=0 artisan daily-report-ai:recover & ;;
                daily-scheduler) php -d max_execution_time=0 artisan daily-report-ai:dispatch & ;;
                recovery) php -d max_execution_time=0 artisan work-report-ai:recover & ;;
            esac
            child=$!
            wait "$child" || :
            if [ "$stopping" = 1 ]; then
                # Laravel finishes its in-flight job after SIGTERM. Reap it before exit.
                wait "$child" 2>/dev/null || :
                exit 0
            fi
            child=
            case "$mode" in
                worker|daily-worker|social-worker)
                    echo "Queue $mode exited; restarting." >&2
                    pause 2
                    ;;
                daily-scheduler) pause 300 ;;
                *) pause 60 ;;
            esac
        done
        exit 0
        ;;
    service) ;;
    *) echo 'Unknown service mode.' >&2; exit 1 ;;
esac

php artisan config:cache || exit 1
worker=
recovery=
daily_worker=
daily_recovery=
daily_scheduler=
social_worker=
web=
monitor=
shutdown() {
    trap '' TERM INT
    [ -z "$monitor" ] || kill -TERM "$monitor" 2>/dev/null || :
    # Stop producers first, then let queue consumers finish their in-flight jobs.
    [ -z "$daily_scheduler" ] || kill -TERM "$daily_scheduler" 2>/dev/null || :
    [ -z "$daily_recovery" ] || kill -TERM "$daily_recovery" 2>/dev/null || :
    [ -z "$social_worker" ] || kill -TERM "$social_worker" 2>/dev/null || :
    [ -z "$daily_worker" ] || kill -TERM "$daily_worker" 2>/dev/null || :
    [ -z "$worker" ] || kill -TERM "$worker" 2>/dev/null || :
    [ -z "$recovery" ] || kill -TERM "$recovery" 2>/dev/null || :
    [ -z "$daily_scheduler" ] || wait "$daily_scheduler" 2>/dev/null || :
    [ -z "$daily_recovery" ] || wait "$daily_recovery" 2>/dev/null || :
    [ -z "$social_worker" ] || wait "$social_worker" 2>/dev/null || :
    [ -z "$daily_worker" ] || wait "$daily_worker" 2>/dev/null || :
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

# The daily queue has its own connection and must not be consumed by the weekly worker.
case "${DAILY_REPORT_AI_WORKER_MODE:-off}" in
    embedded)
        daily_queue_enabled=$(php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("daily_report_ai.enabled") && config("daily_report_ai.queue_connection") === "daily_report_ai" ? "yes" : "no";') || shutdown 1
        if [ "$daily_queue_enabled" = yes ]; then
            sh "$script" daily-worker &
            daily_worker=$!
            sh "$script" daily-recovery &
            daily_recovery=$!
            echo 'Daily AI embedded worker and recovery started.'
            daily_auto_enabled=$(php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("daily_report_ai.auto_dispatch") && (int) config("daily_report_ai.dispatch_user_id") > 0 ? "yes" : "no";') || shutdown 1
            if [ "$daily_auto_enabled" = yes ]; then
                sh "$script" daily-scheduler &
                daily_scheduler=$!
                echo 'Daily AI embedded dispatcher started (300-second interval).'
            fi
        fi
        ;;
    external|off) ;;
    *) echo 'Invalid DAILY_REPORT_AI_WORKER_MODE.' >&2; shutdown 1 ;;
esac

# Opt in to consuming the social queue in this container; scheduling remains separate.
case "${SOCIAL_METRICS_WORKER_MODE:-off}" in
    embedded)
        social_queue_enabled=$(php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("social_metrics.enabled") ? "yes" : "no";') || shutdown 1
        if [ "$social_queue_enabled" = yes ]; then
            sh "$script" social-worker &
            social_worker=$!
            echo 'Social metrics embedded worker started (manual sync queue).'
        fi
        ;;
    external|off) ;;
    *) echo 'Invalid SOCIAL_METRICS_WORKER_MODE.' >&2; shutdown 1 ;;
esac

while kill -0 "$web" 2>/dev/null; do
    # A failed supervisor loop must fail the container, allowing Render to restart it.
    if { [ -n "$worker" ] && ! kill -0 "$worker" 2>/dev/null; } || \
        { [ -n "$recovery" ] && ! kill -0 "$recovery" 2>/dev/null; } || \
        { [ -n "$daily_worker" ] && ! kill -0 "$daily_worker" 2>/dev/null; } || \
        { [ -n "$daily_recovery" ] && ! kill -0 "$daily_recovery" 2>/dev/null; } || \
        { [ -n "$social_worker" ] && ! kill -0 "$social_worker" 2>/dev/null; } || \
        { [ -n "$daily_scheduler" ] && ! kill -0 "$daily_scheduler" 2>/dev/null; }; then
        echo 'Queue supervisor exited unexpectedly.' >&2
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

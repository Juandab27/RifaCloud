#!/bin/sh
# Minimal supervisor for the Laravel scheduler.
#
# `php artisan schedule:work` does not own the container PID 1, so Docker's
# SIGTERM used to be forwarded poorly and a manual `docker compose stop` was
# reported as Exited (137). This wrapper forwards SIGTERM, waits for the
# current run and exits cleanly; unexpected child crashes are restarted with a
# short backoff so the scheduler is never left silently dead.
set -u

child=""

shutdown() {
    echo "scheduler: shutdown requested" >&2
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null || true
        wait "$child" 2>/dev/null || true
    fi
    echo "scheduler: exiting cleanly" >&2
    exit 0
}

trap shutdown TERM INT QUIT

while true; do
    php artisan schedule:work &
    child=$!

    status=0
    wait "$child" || status=$?

    # A clean child exit (for example during shutdown) ends the supervisor.
    if [ "$status" -eq 0 ]; then
        exit 0
    fi

    echo "scheduler child exited with status $status; restarting in 5s" >&2
    sleep 5
done

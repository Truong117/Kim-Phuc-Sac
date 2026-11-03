"""Exercise the actual POSIX entrypoint with fake child executables, no PHP/DB/API."""
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
import unittest


CHILD = r'''#!/usr/bin/env python3
import os, pathlib, signal, sys, time
root = pathlib.Path(os.environ['KPS_APP_DIR'])
def event(value):
    with (root / 'events').open('a') as stream:
        stream.write(value + '\n')
if pathlib.Path(sys.argv[0]).name == 'frankenphp':
    event('web-start')
    signal.signal(signal.SIGTERM, lambda *_: (event('web-stop'), sys.exit(0)))
    while True: time.sleep(.02)
elif '-r' in sys.argv:
    code = sys.argv[sys.argv.index('-r') + 1]
    if 'daily_report_ai.auto_dispatch' in code:
        print(os.environ.get('TEST_DAILY_AUTO', 'no'))
    elif 'daily_report_ai.enabled' in code:
        print(os.environ.get('TEST_DAILY_ENABLED', 'no'))
    else:
        print(os.environ.get('TEST_QUEUE_ENABLED', 'yes'))
elif 'config:cache' in sys.argv:
    pass
elif 'work-report-ai:recover' in sys.argv:
    event('recovery')
elif 'daily-report-ai:recover' in sys.argv:
    event('daily-recovery')
elif 'daily-report-ai:dispatch' in sys.argv:
    event('daily-dispatch')
elif 'queue:work' in sys.argv:
    prefix = 'daily-worker' if 'daily_report_ai' in sys.argv else 'worker'
    if not (root / (prefix + '-crashed')).exists():
        (root / (prefix + '-crashed')).touch()
        event(prefix + '-crash')
        sys.exit(1)
    event(prefix + '-start')
    def finish(*_):
        event(prefix + '-term')
        time.sleep(.3)
        event(prefix + '-completed')
        sys.exit(0)
    signal.signal(signal.SIGTERM, finish)
    while True: time.sleep(.02)
else:
    sys.exit(99)
'''


class SupervisorTest(unittest.TestCase):
    def run_service(self, mode='embedded', enabled='yes', daily_mode='off', daily_enabled='no', daily_auto='no'):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        for name in ('php', 'frankenphp'):
            executable = root / name
            executable.write_text(CHILD)
            executable.chmod(0o755)
        script = Path(__file__).resolve().parents[2] / 'scripts/start-service.sh'
        process = subprocess.Popen(['sh', str(script)], env={**os.environ,
            'PATH': str(root) + os.pathsep + os.environ['PATH'], 'KPS_APP_DIR': str(root),
            'WORK_REPORT_AI_WORKER_MODE': mode, 'WORK_REPORT_AI_BOOT_DELAY_SECONDS': '0',
            'TEST_QUEUE_ENABLED': enabled, 'DAILY_REPORT_AI_WORKER_MODE': daily_mode,
            'DAILY_REPORT_AI_BOOT_DELAY_SECONDS': '0', 'TEST_DAILY_ENABLED': daily_enabled,
            'TEST_DAILY_AUTO': daily_auto}, start_new_session=True,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

        def cleanup():
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait(timeout=5)
        self.addCleanup(cleanup)
        return process, root

    def events(self, root):
        return (root / 'events').read_text().splitlines() if (root / 'events').exists() else []

    def await_event(self, root, event):
        deadline = time.monotonic() + 8
        while time.monotonic() < deadline:
            if event in self.events(root):
                return
            time.sleep(.03)
        self.fail(f'{event} missing: {self.events(root)}')

    def test_worker_restarts_and_sigterm_finishes_job_before_web_stops(self):
        process, root = self.run_service()
        self.await_event(root, 'worker-start')
        self.await_event(root, 'recovery')
        process.terminate()
        self.assertEqual(0, process.wait(timeout=5))
        events = self.events(root)
        self.assertEqual(1, events.count('worker-crash'))
        self.assertEqual(1, events.count('worker-start'))
        self.assertLess(events.index('worker-term'), events.index('worker-completed'))
        self.assertLess(events.index('worker-completed'), events.index('web-stop'))

    def test_external_or_disabled_queue_does_not_launch_an_embedded_worker(self):
        for mode, enabled in [('external', 'yes'), ('embedded', 'no')]:
            with self.subTest(mode=mode, enabled=enabled):
                process, root = self.run_service(mode, enabled)
                self.await_event(root, 'web-start')
                time.sleep(.2)
                process.terminate()
                self.assertEqual(0, process.wait(timeout=5))
                self.assertEqual(['web-start', 'web-stop'], self.events(root))


    def test_daily_and_weekly_workers_restart_independently_and_both_drain_before_web(self):
        process, root = self.run_service(daily_mode='embedded', daily_enabled='yes', daily_auto='yes')
        for event in ['worker-start', 'daily-worker-start', 'daily-recovery', 'daily-dispatch']:
            self.await_event(root, event)
        process.terminate()
        self.assertEqual(0, process.wait(timeout=5))
        events = self.events(root)
        for worker in ['worker', 'daily-worker']:
            self.assertEqual(1, events.count(worker + '-crash'))
            self.assertEqual(1, events.count(worker + '-start'))
            self.assertLess(events.index(worker + '-term'), events.index(worker + '-completed'))
            self.assertLess(events.index(worker + '-completed'), events.index('web-stop'))

    def test_manual_daily_mode_runs_worker_and_recovery_without_auto_dispatch(self):
        process, root = self.run_service(enabled='no', daily_mode='embedded', daily_enabled='yes')
        self.await_event(root, 'daily-worker-start')
        self.await_event(root, 'daily-recovery')
        self.assertNotIn('daily-dispatch', self.events(root))
        self.assertNotIn('worker-start', self.events(root))
        process.terminate()
        self.assertEqual(0, process.wait(timeout=5))

    def test_daily_external_off_or_disabled_never_launches_embedded_daily_processes(self):
        for mode, enabled in [('external', 'yes'), ('off', 'yes'), ('embedded', 'no')]:
            with self.subTest(mode=mode, enabled=enabled):
                process, root = self.run_service(enabled='no', daily_mode=mode, daily_enabled=enabled, daily_auto='yes')
                self.await_event(root, 'web-start')
                time.sleep(.2)
                process.terminate()
                self.assertEqual(0, process.wait(timeout=5))
                self.assertEqual(['web-start', 'web-stop'], self.events(root))


if __name__ == '__main__':
    unittest.main()

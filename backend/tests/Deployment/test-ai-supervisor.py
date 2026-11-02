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
    print(os.environ.get('TEST_QUEUE_ENABLED', 'yes'))
elif 'config:cache' in sys.argv:
    pass
elif 'work-report-ai:recover' in sys.argv:
    event('recovery')
elif 'queue:work' in sys.argv:
    if not (root / 'crashed').exists():
        (root / 'crashed').touch()
        event('worker-crash')
        sys.exit(1)
    event('worker-start')
    def finish(*_):
        event('worker-term')
        time.sleep(.3)
        event('worker-completed')
        sys.exit(0)
    signal.signal(signal.SIGTERM, finish)
    while True: time.sleep(.02)
else:
    sys.exit(99)
'''


class SupervisorTest(unittest.TestCase):
    def run_service(self, mode='embedded', enabled='yes'):
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
            'TEST_QUEUE_ENABLED': enabled}, start_new_session=True,
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


if __name__ == '__main__':
    unittest.main()

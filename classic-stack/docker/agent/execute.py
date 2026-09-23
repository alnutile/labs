import json
import os
import signal
import subprocess
import sys
import tempfile

# Bound output on disk rather than buffering an arbitrary program's stdout in RAM.
with tempfile.TemporaryFile() as output:
    process = subprocess.Popen(
        ["bash", "-lc", sys.argv[1]], cwd="/workspace",
        stdout=output, stderr=subprocess.STDOUT, start_new_session=True,
    )
    timed_out = False
    try:
        timeout = max(1, min(60, int(sys.argv[2]) if len(sys.argv) > 2 else 60))
        process.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        timed_out = True
    finally:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()
    size = output.tell()
    output.seek(0)
    print(json.dumps({
        "exit_code": 124 if timed_out else process.returncode,
        "output": output.read(16000).decode("utf-8", errors="replace"),
        "truncated": size > 16000, "timed_out": timed_out,
    }))

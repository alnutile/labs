"""Private Docker broker. No caller-supplied images, mounts, networks or Docker options."""
import base64
import hmac
import json
import os
import re
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import docker

client = docker.from_env(timeout=100)
token = os.environ["AGENT_BROKER_TOKEN"]
if len(token) < 32:
    raise RuntimeError("AGENT_BROKER_TOKEN must contain at least 32 characters")
label = "classic-stack.agent-sandbox"
task_timeout = max(60, min(3600, int(os.environ.get("AGENT_TASK_TIMEOUT_SECONDS", "300"))))
session_lifetime = task_timeout + 30
sessions = {}
lock = threading.RLock()


def remove(session):
    with lock:
        item = sessions.pop(session, None)
        if item:
            try:
                item["container"].remove(force=True)
            except docker.errors.NotFound:
                pass


def reap():
    while True:
        time.sleep(10)
        with lock:
            expired = [key for key, item in sessions.items() if item["expires"] < time.time()]
        for key in expired:
            remove(key)


def start(data):
    with lock:
        if sessions:
            raise ValueError("A sandbox is already active; try again after it finishes")
        session = uuid.uuid4().hex
        container = client.containers.run(
            os.environ["AGENT_SANDBOX_IMAGE"], ["sleep", str(session_lifetime)], detach=True,
            name="classic-agent-" + session, labels={label: "true"},
            network=os.environ["AGENT_SANDBOX_NETWORK"], user="1000:1000",
            read_only=True, cap_drop=["ALL"], security_opt=["no-new-privileges:true"],
            mem_limit="1g", memswap_limit="1g", nano_cpus=1_000_000_000,
            pids_limit=128, init=True,
            tmpfs={"/workspace": "rw,nosuid,nodev,size=256m,uid=1000,gid=1000,mode=0700",
                   "/tmp": "rw,nosuid,nodev,size=64m,mode=1777"},
            environment={"HOME": "/workspace", "HTTP_PROXY": "http://agent-proxy:3128",
                         "HTTPS_PROXY": "http://agent-proxy:3128",
                         "http_proxy": "http://agent-proxy:3128",
                         "https_proxy": "http://agent-proxy:3128"},
            log_config=docker.types.LogConfig(type="none"),
        )
        sessions[session] = {"container": container, "expires": time.time() + session_lifetime, "calls": 0}
        try:
            container.exec_run(["mkdir", "-p", "/workspace/output"])
            if data.get("file"):
                name = data["file"]["name"]
                if not re.fullmatch(r"input\.[a-zA-Z0-9]{1,12}", name):
                    raise ValueError("Invalid input filename")
                content = base64.b64decode(data["file"]["content"], validate=True)
                if len(content) > 20 * 1024 * 1024:
                    raise ValueError("Input exceeds 20 MB")
                # Docker's archive API rejects read-only roots, even for a tmpfs.
                # Send bytes on stdin to a fixed program; never interpolate uploads into a shell.
                upload = client.api.exec_create(container.id, [
                    "python3", "-I", "-c",
                    "import sys; from pathlib import Path; Path(sys.argv[1]).write_bytes(sys.stdin.buffer.read(int(sys.argv[2])))",
                    "/workspace/" + name, str(len(content)),
                ], stdin=True)
                connection = client.api.exec_start(upload["Id"], socket=True)
                try:
                    connection._sock.settimeout(30)
                    connection._sock.sendall(content)
                    deadline = time.time() + 30
                    while client.api.exec_inspect(upload["Id"])["Running"]:
                        if time.time() > deadline:
                            raise ValueError("Upload timed out")
                        time.sleep(0.05)
                    if client.api.exec_inspect(upload["Id"])["ExitCode"] != 0:
                        raise ValueError("Upload failed")
                finally:
                    connection.close()
            return {"session": session}
        except Exception:
            remove(session)
            raise


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def respond(self, code, body):
        encoded = json.dumps(body).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_POST(self):
        if not hmac.compare_digest(self.headers.get("Authorization", ""), "Bearer " + token):
            return self.respond(401, {"error": "Unauthorized"})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length < 0 or length > 30 * 1024 * 1024:
                return self.respond(413, {"error": "Request too large"})
            data = json.loads(self.rfile.read(length) or b"{}")
            if not isinstance(data, dict):
                return self.respond(422, {"error": "Request body must be a JSON object"})
            if self.path == "/sessions":
                return self.respond(201, start(data))
            match = re.fullmatch(r"/sessions/([a-f0-9]{32})/(command|artifacts|stop)", self.path)
            if not match:
                return self.respond(404, {"error": "Not found"})
            session, action = match.groups()
            if action == "stop":
                remove(session)
                return self.respond(200, {"stopped": True})
            with lock:
                item = sessions.get(session)
                if not item or item["expires"] < time.time():
                    return self.respond(410, {"error": "Sandbox expired"})
                if action == "command":
                    if item["calls"] >= 400:
                        raise ValueError("Maximum of 400 commands reached")
                    command = data.get("command", "")
                    command_timeout = data.get("timeout", 60)
                    if not isinstance(command, str) or not 1 <= len(command) <= 16000:
                        raise ValueError("Invalid command")
                    if not isinstance(command_timeout, int) or not 1 <= command_timeout <= 60:
                        raise ValueError("Invalid command timeout")
                    item["calls"] += 1
            args = (["python3", "-I", "/opt/agent/execute.py", command, str(command_timeout)] if action == "command"
                    else ["python3", "-I", "/opt/agent/collect.py"])
            result = item["container"].exec_run(args, workdir="/workspace")
            if result.exit_code != 0:
                raise ValueError("Sandbox command wrapper stopped unexpectedly")
            self.respond(200, json.loads(result.output))
        except (ValueError, KeyError, TypeError):
            self.respond(422, {"error": "Invalid request, busy sandbox, or execution limit reached"})
        except Exception as error:
            print(type(error).__name__, str(error), flush=True)
            self.respond(503, {"error": "Sandbox unavailable; check the broker service"})


if __name__ == "__main__":
    # Broker restarts must not leave old task containers behind.
    for old in client.containers.list(all=True, filters={"label": label}):
        old.remove(force=True)
    threading.Thread(target=reap, daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", 8010), Handler).serve_forever()

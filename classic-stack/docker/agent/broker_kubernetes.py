"""Private Kubernetes broker. Callers can only start the pinned sandbox Pod."""
import base64
import hmac
import json
import os
import re
import subprocess
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

token = os.environ["AGENT_BROKER_TOKEN"]
if len(token) < 32:
    raise RuntimeError("AGENT_BROKER_TOKEN must contain at least 32 characters")

namespace = os.environ.get("AGENT_SANDBOX_NAMESPACE", "classic-stack")
sandbox_image = os.environ["AGENT_SANDBOX_IMAGE"]
label = "classic-stack-agent-sandbox"
sessions = {}
lock = threading.RLock()


def kubectl(*args, input_bytes=None, timeout=90):
    return subprocess.run(
        ["kubectl", "--namespace", namespace, *args],
        input=input_bytes,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
        check=True,
    )


def remove(session):
    with lock:
        item = sessions.pop(session, None)
    if item:
        kubectl("delete", "pod", item["pod"], "--ignore-not-found", "--wait=false", timeout=30)


def reap():
    while True:
        time.sleep(10)
        with lock:
            expired = [key for key, item in sessions.items() if item["expires"] < time.time()]
        for key in expired:
            remove(key)


def pod_manifest(name):
    proxy = "http://agent-proxy:3128"
    return {
        "apiVersion": "v1",
        "kind": "Pod",
        "metadata": {
            "name": name,
            "namespace": namespace,
            "labels": {
                "app.kubernetes.io/name": "classic-stack-agent-sandbox",
                "classic-stack-agent-sandbox": "true",
            },
        },
        "spec": {
            "activeDeadlineSeconds": 900,
            "automountServiceAccountToken": False,
            "enableServiceLinks": False,
            "restartPolicy": "Never",
            "serviceAccountName": "classic-stack-agent-sandbox",
            "terminationGracePeriodSeconds": 0,
            "securityContext": {
                "fsGroup": 1000,
                "runAsNonRoot": True,
                "runAsUser": 1000,
                "seccompProfile": {"type": "RuntimeDefault"},
            },
            "containers": [{
                "name": "sandbox",
                "image": sandbox_image,
                "imagePullPolicy": "Always",
                "command": ["sleep", "900"],
                "env": [
                    {"name": key, "value": proxy}
                    for key in ["HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy"]
                ],
                "resources": {
                    "requests": {"cpu": "100m", "memory": "128Mi"},
                    "limits": {"cpu": "1", "memory": "1Gi"},
                },
                "securityContext": {
                    "allowPrivilegeEscalation": False,
                    "capabilities": {"drop": ["ALL"]},
                    "readOnlyRootFilesystem": True,
                    "runAsNonRoot": True,
                    "runAsUser": 1000,
                },
                "volumeMounts": [
                    {"name": "workspace", "mountPath": "/workspace"},
                    {"name": "tmp", "mountPath": "/tmp"},
                ],
                "workingDir": "/workspace",
            }],
            "volumes": [
                {"name": "workspace", "emptyDir": {"sizeLimit": "256Mi"}},
                {"name": "tmp", "emptyDir": {"sizeLimit": "64Mi"}},
            ],
        },
    }


def start(data):
    with lock:
        if sessions:
            raise ValueError("A sandbox is already active; try again after it finishes")
        session = uuid.uuid4().hex
        pod = "classic-agent-" + session
        sessions[session] = {"pod": pod, "expires": time.time() + 900, "calls": 0}
    try:
        manifest = json.dumps(pod_manifest(pod)).encode()
        kubectl("apply", "-f", "-", input_bytes=manifest)
        kubectl("wait", "--for=condition=Ready", "pod/" + pod, "--timeout=90s", timeout=100)
        kubectl("exec", pod, "--", "mkdir", "-p", "/workspace/output")
        if data.get("file"):
            name = data["file"]["name"]
            if not re.fullmatch(r"input\.[a-zA-Z0-9]{1,12}", name):
                raise ValueError("Invalid input filename")
            content = base64.b64decode(data["file"]["content"], validate=True)
            if len(content) > 20 * 1024 * 1024:
                raise ValueError("Input exceeds 20 MB")
            kubectl(
                "exec", "-i", pod, "--", "python3", "-I", "-c",
                "import sys; from pathlib import Path; Path(sys.argv[1]).write_bytes(sys.stdin.buffer.read(int(sys.argv[2])))",
                "/workspace/" + name, str(len(content)), input_bytes=content, timeout=40,
            )
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
                    if item["calls"] >= 20:
                        raise ValueError("Maximum of 20 commands reached")
                    command = data.get("command", "")
                    if not isinstance(command, str) or not 1 <= len(command) <= 16000:
                        raise ValueError("Invalid command")
                    item["calls"] += 1
                pod = item["pod"]
            args = (["python3", "-I", "/opt/agent/execute.py", command]
                    if action == "command" else ["python3", "-I", "/opt/agent/collect.py"])
            result = kubectl("exec", pod, "--", *args, timeout=75)
            self.respond(200, json.loads(result.stdout))
        except (ValueError, KeyError, TypeError, json.JSONDecodeError):
            self.respond(422, {"error": "Invalid request, busy sandbox, or execution limit reached"})
        except Exception as error:
            print(type(error).__name__, str(error), flush=True)
            self.respond(503, {"error": "Sandbox unavailable; check the broker service"})


if __name__ == "__main__":
    kubectl("delete", "pods", "-l", label + "=true", "--ignore-not-found", "--wait=false", timeout=30)
    threading.Thread(target=reap, daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", 8010), Handler).serve_forever()

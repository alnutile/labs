import base64
import json
import os
import stat

files = []
total = 0
# Open a real directory and only regular files; never follow agent-created symlinks.
root = os.open("/workspace/output", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
try:
    for name in sorted(os.listdir(root))[:20]:
        try:
            fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=root)
        except OSError:
            continue
        with os.fdopen(fd, "rb") as file:
            info = os.fstat(file.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size + total > 20 * 1024 * 1024:
                continue
            content = file.read(20 * 1024 * 1024 + 1)
            total += len(content)
            if total > 20 * 1024 * 1024:
                break
            files.append({"name": name, "content": base64.b64encode(content).decode()})
finally:
    os.close(root)
print(json.dumps({"files": files}))

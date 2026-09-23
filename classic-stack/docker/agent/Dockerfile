FROM python:3.12-slim-bookworm AS sandbox
RUN apt-get update && apt-get install -y --no-install-recommends bash curl jq ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir requests==2.32.5 beautifulsoup4==4.13.5 \
    && useradd --uid 1000 --create-home agent
COPY execute.py collect.py /opt/agent/
USER 1000:1000
WORKDIR /workspace
CMD ["sleep", "900"]

FROM python:3.12-slim-bookworm AS broker
RUN pip install --no-cache-dir docker==7.1.0
COPY broker.py /opt/agent/broker.py
CMD ["python", "-u", "/opt/agent/broker.py"]

FROM python:3.12-slim-bookworm AS kubernetes-broker
ARG KUBECTL_VERSION=v1.36.4
ARG KUBECTL_SHA256=8b8f088da2dab964f853b38464033b1be15ede2839eca751482357c45abdd05a
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl \
    && curl -fsSLo /usr/local/bin/kubectl "https://dl.k8s.io/release/${KUBECTL_VERSION}/bin/linux/amd64/kubectl" \
    && echo "${KUBECTL_SHA256}  /usr/local/bin/kubectl" | sha256sum --check \
    && chmod 0755 /usr/local/bin/kubectl \
    && rm -rf /var/lib/apt/lists/*
COPY broker_kubernetes.py /opt/agent/broker.py
USER 65532:65532
CMD ["python", "-u", "/opt/agent/broker.py"]

FROM debian:bookworm-slim AS proxy
RUN apt-get update && apt-get install -y --no-install-recommends squid \
    && rm -rf /var/lib/apt/lists/*
COPY squid.conf /etc/squid/squid.conf
CMD ["squid", "-N", "-f", "/etc/squid/squid.conf"]

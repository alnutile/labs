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

FROM debian:bookworm-slim AS proxy
RUN apt-get update && apt-get install -y --no-install-recommends squid \
    && rm -rf /var/lib/apt/lists/*
COPY squid.conf /etc/squid/squid.conf
CMD ["squid", "-N", "-f", "/etc/squid/squid.conf"]

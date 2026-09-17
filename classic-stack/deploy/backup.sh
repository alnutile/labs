#!/usr/bin/env bash
set -euo pipefail
export APP_ROOT="${APP_ROOT:-/home/deploy/apps/classic-stack}"
release_path="$(readlink -f "$APP_ROOT/current")"
backup_dir="${1:?Usage: backup.sh /path/to/backup-directory}"
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
umask 077
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
docker compose --env-file "$APP_ROOT/shared/env/production.env" -f "$release_path/deploy/compose.infra.yaml" exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup_dir/postgres-$stamp.dump"
docker run --rm -v classic-stack-storage:/data:ro alpine:3.22 tar -C /data -czf - . > "$backup_dir/storage-$stamp.tar.gz"
echo "Backup written to $backup_dir; copy it off this EC2 host. These are separate snapshots, not an atomic database/filesystem backup."

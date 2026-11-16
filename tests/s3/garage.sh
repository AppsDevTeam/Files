#!/usr/bin/env bash
# Single-node Garage (S3) in Docker for the S3 storage tests. Prints the environment the
# tests read (ADT_FILES_S3_*); the tests are skipped without it.
#
#   eval "$(tests/s3/garage.sh up)"     # start (idempotent) and export the variables
#   vendor/bin/phpunit
#   tests/s3/garage.sh down             # remove the container and its data
set -euo pipefail

name=adt-files-test-garage
port=${ADT_FILES_S3_PORT:-3910}
image=${GARAGE_IMAGE:-dxflrs/garage:v2.5.0}

g() { docker exec "$name" /garage "$@" 2>/dev/null; }

up() {
	if ! docker inspect "$name" >/dev/null 2>&1; then
		dir=$(mktemp -d)
		cat > "$dir/garage.toml" <<TOML
metadata_dir = "/var/lib/garage/meta"
data_dir = "/var/lib/garage/data"
db_engine = "lmdb"
replication_factor = 1
rpc_bind_addr = "0.0.0.0:3901"
rpc_public_addr = "127.0.0.1:3901"
rpc_secret = "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
[s3_api]
s3_region = "garage"
api_bind_addr = "0.0.0.0:3900"
root_domain = ".s3.garage.localhost"
[admin]
api_bind_addr = "0.0.0.0:3903"
admin_token = "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
TOML
		docker run -d --name "$name" -p "127.0.0.1:$port:3900" -v "$dir/garage.toml:/etc/garage.toml:ro" "$image" >/dev/null 2>&1
		for i in $(seq 1 30); do g status >/dev/null 2>&1 && break; sleep 1; done
		id=$(g node id -q | cut -d@ -f1)
		g layout assign -z dc1 -c 1G "$id" >/dev/null
		g layout apply --version 1 >/dev/null
		g key create test >/dev/null
		for bucket in files-data files-archive; do
			g bucket create "$bucket" >/dev/null
			g bucket allow --read --write --owner "$bucket" --key test >/dev/null
		done
	fi
	docker start "$name" >/dev/null 2>&1 || true
	for i in $(seq 1 30); do g status >/dev/null 2>&1 && break; sleep 1; done
	info=$(g key info --show-secret test)
	echo "export ADT_FILES_S3_ENDPOINT=http://127.0.0.1:$port"
	echo "export ADT_FILES_S3_REGION=garage"
	echo "export ADT_FILES_S3_KEY=$(echo "$info" | awk -F': *' '/^Key ID/ {print $2}' | tr -d '\r ')"
	echo "export ADT_FILES_S3_SECRET=$(echo "$info" | awk -F': *' '/^Secret key/ {print $2}' | tr -d '\r ')"
	echo "export ADT_FILES_S3_BUCKET=files-data"
	echo "export ADT_FILES_S3_ARCHIVE_BUCKET=files-archive"
}

down() {
	docker rm -fv "$name" >/dev/null 2>&1 || true
}

case "${1:-}" in
	up) up ;;
	down) down ;;
	*) echo "usage: $0 up|down" >&2; exit 2 ;;
esac

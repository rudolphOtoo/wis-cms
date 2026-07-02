#!/usr/bin/env bash
# Builds the app and webserver images and pushes them to GHCR.
# Usage: docker/build-and-push.sh [tag]   (tag defaults to "latest")
set -euo pipefail

REGISTRY="ghcr.io/rudolphotoo"
TAG="${1:-latest}"

# 1. Export current database data into the church-data.json file that
#    will be bundled in the Docker image.
php artisan app:data-migrate --export

# 2. Build and push images.
docker build --target app -t "$REGISTRY/wis-cms-app:$TAG" .
docker build --target webserver -t "$REGISTRY/wis-cms-webserver:$TAG" .

docker push "$REGISTRY/wis-cms-app:$TAG"
docker push "$REGISTRY/wis-cms-webserver:$TAG"

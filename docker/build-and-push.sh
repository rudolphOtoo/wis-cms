#!/usr/bin/env bash
# Builds the app and webserver images and pushes them to GHCR.
# Usage: docker/build-and-push.sh [tag]   (tag defaults to "latest")
set -euo pipefail

REGISTRY="ghcr.io/rudolphotoo"
TAG="${1:-latest}"

docker build --target app -t "$REGISTRY/wis-cms-app:$TAG" .
docker build --target webserver -t "$REGISTRY/wis-cms-webserver:$TAG" .

docker push "$REGISTRY/wis-cms-app:$TAG"
docker push "$REGISTRY/wis-cms-webserver:$TAG"

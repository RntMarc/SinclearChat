#!/bin/bash
# Startet den PHP Built-in Development Server
# Usage: ./scripts/serve.sh [port]

PORT=${1:-8080}
DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "Starting SinclearChat API on http://localhost:${PORT}"
echo "Document root: ${DIR}/public"
echo "Press Ctrl+C to stop."

php -S "0.0.0.0:${PORT}" -t "${DIR}/public"

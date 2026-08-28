#!/bin/bash
# Package the plugin into advery-reviews.zip for a GitHub release.
# The zip's top-level folder is `advery-reviews/`. Dev-only files are excluded;
# the compiled build/ is kept. Output: dist/advery-reviews.zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
SRC="${ROOT}/advery-reviews"
STAGE="$(mktemp -d)"
OUT_DIR="${ROOT}/dist"
OUT="${OUT_DIR}/advery-reviews.zip"

mkdir -p "${OUT_DIR}"
rm -f "${OUT}"

rsync -a \
  --exclude 'node_modules/' \
  --exclude 'src/' \
  --exclude '.gitignore' \
  --exclude 'package.json' \
  --exclude 'package-lock.json' \
  --exclude '.DS_Store' \
  "${SRC}/" "${STAGE}/advery-reviews/"

( cd "${STAGE}" && zip -rq "${OUT}" advery-reviews )
rm -rf "${STAGE}"

echo "Built ${OUT}"
unzip -l "${OUT}" | tail -20

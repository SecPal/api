#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
repo_root=$(git rev-parse --show-toplevel)
cd "$repo_root"
arch=${1:?native architecture}
output=${2:?owned output directory}
governance="$repo_root/.context/publisher-governance"
tools="${RUNNER_TEMP:?}/secpal-publisher-tools"
case "$arch:$(uname -m):${RUNNER_ARCH:-}" in
  amd64:x86_64:X64|arm64:aarch64:ARM64) ;;
  *) echo 'Native build architecture rejected' >&2; exit 1 ;;
esac
for handler in /proc/sys/fs/binfmt_misc/qemu-*; do
  if [ -f "$handler" ] && grep -qx enabled "$handler"; then
    echo 'Foreign-architecture emulation is not native evidence' >&2
    exit 1
  fi
done
mkdir -p "$output"
output=$(realpath "$output")
python3 scripts/container-publisher.py source --root "$repo_root" --commit "${GITHUB_SHA:?}" --output "$output/source.json"
snapshot_parent=$(mktemp -d "${RUNNER_TEMP}/secpal-api-context.XXXXXX")
cleanup() {
  chmod -R u+w "$snapshot_parent"
  rm -rf -- "$snapshot_parent"
}
trap cleanup EXIT
snapshot="$snapshot_parent/source"
python3 -I "$governance/scripts/secpal-trivy-repository-scan.py" prepare-snapshot \
  --workspace "$repo_root" --commit "$GITHUB_SHA" --destination "$snapshot"
python3 scripts/container-publisher.py bases --root "$snapshot" --arch "$arch" --output "$output/bases.json"
composer_base=$(jq -er '.[] | select(.stage=="composer") | (.reference | split("@")[0] | split(":")[0])+"@"+.digest' "$output/bases.json")
runtime_base=$(jq -er '.[] | select(.stage=="extensions") | (.reference | split("@")[0] | split(":")[0])+"@"+.digest' "$output/bases.json")
buildah pull --arch "$arch" "$composer_base"
buildah pull --arch "$arch" "$runtime_base"
epoch=$(jq -er .epoch "$output/source.json")
created=$(date -u -d "@$epoch" +%Y-%m-%dT%H:%M:%SZ)
reviewed_base=$(jq -er '.[]|select(.stage=="extensions")|.reference' "$output/bases.json")
base_digest=$(jq -er '.[]|select(.stage=="extensions")|.digest' "$output/bases.json")
image="localhost/secpal-api:build-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT-$arch"
buildah build --format oci --timestamp "$epoch" --pull=never --layers --arch "$arch" \
  --http-proxy=false --iidfile "$output/image-id" \
  --build-arg "COMPOSER_IMAGE=$composer_base" --build-arg "FRANKENPHP_IMAGE=$runtime_base" \
  --label "org.opencontainers.image.source=https://github.com/SecPal/api" \
  --label "org.opencontainers.image.revision=$GITHUB_SHA" \
  --label "org.opencontainers.image.created=$created" \
  --label "org.opencontainers.image.licenses=AGPL-3.0-or-later" \
  --label "org.opencontainers.image.base.name=$reviewed_base" \
  --label "org.opencontainers.image.base.digest=$base_digest" \
  --label "org.opencontainers.image.title=SecPal API" \
  --label "org.opencontainers.image.description=Production API for SecPal operations software" \
  --file "$snapshot/Containerfile" --tag "$image" "$snapshot"
buildah push --format oci "$image" "oci:$output/image:api"
python3 scripts/container-publisher.py record --layout "$output/image" --arch "$arch" \
  --source "$output/source.json" --bases "$output/bases.json" --output "$output/record.json"
manifest=$(jq -er .subject.manifest_digest "$output/record.json")
env -i HOME="$tools" PATH="$PATH" SYFT_CHECK_FOR_APP_UPDATE=false \
  "$tools/syft" scan --config "$tools/empty.yaml" "oci-dir:$output/image" \
  --source-name "ghcr.io/secpal/api@$manifest" --source-version "$GITHUB_SHA" \
  --output "spdx-json=$output/syft.spdx.json"
python3 scripts/container-publisher.py association --record "$output/record.json" \
  --spdx "$output/syft.spdx.json" --output "$output/association.json"
python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" verify-syft \
  --platform-digest "$manifest" --platform "linux/$arch" --spdx "$output/syft.spdx.json" \
  --association "$output/association.json" --output "$output/syft-association.json"
SKIP_BUILD=1 IMAGE_TAG="$image" EXPECTED_ARCH="$arch" tests/docker/smoke.sh
python3 scripts/container-publisher.py source --root "$repo_root" --commit "$GITHUB_SHA" --output "$output/source-after.json"
cmp "$output/source.json" "$output/source-after.json"

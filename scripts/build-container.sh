#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
repo_root=$(git rev-parse --show-toplevel)
cd "$repo_root"
arch=${1:?native architecture}
output=${2:?owned output directory}
runtime=${3:-legacy}
if [ "$runtime" = pair ]; then
  for component in php-fpm nginx; do
    "$0" "$arch" "$output/$component" "$component"
  done
  PHP_FPM_IMAGE=$(cat "$output/php-fpm/image-ref")
  NGINX_IMAGE=$(cat "$output/nginx/image-ref")
  export PHP_FPM_IMAGE NGINX_IMAGE
  SKIP_BUILD=1 RUNTIME_SET=pair EXPECTED_ARCH="$arch" tests/docker/smoke.sh
  python3 - "$output" "$arch" <<'PYPAIR'
import json,os,sys
from datetime import datetime,timezone
from pathlib import Path
root=Path(sys.argv[1]);arch=sys.argv[2]
pair={component:json.loads((root/component/'record.json').read_bytes())['subject']['manifest_digest']
      for component in ('php-fpm','nginx')}
for component in pair:
    document=dict(platform='linux/'+arch,digest=pair[component],runner_architecture=arch,
                  test='tests/docker/smoke.sh',result='SUCCEEDED',
                  completed_at=datetime.now(timezone.utc).isoformat(),
                  workflow_run_id=os.environ['GITHUB_RUN_ID'],
                  workflow_run_attempt=os.environ['GITHUB_RUN_ATTEMPT'],runtime_pair=pair)
    (root/component/'smoke.json').write_text(json.dumps(document,sort_keys=True,separators=(',',':'))+'\n')
PYPAIR
  exit 0
fi
definition=$(python3 scripts/container-publisher.py runtime-definition --runtime "$runtime")
containerfile=$(jq -er .containerfile <<< "$definition")
runtime_argument=$(jq -er .argument <<< "$definition")
governance="${GITHUB_WORKSPACE:?}/.context/publisher-governance"
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
python3 scripts/container-publisher.py source --root "$repo_root" --commit "${GITHUB_SHA:?}" --runtime "$runtime" --output "$output/source.json"
snapshot_parent=$(mktemp -d "${RUNNER_TEMP}/secpal-api-context.XXXXXX")
cleanup() {
  chmod -R u+w "$snapshot_parent"
  rm -rf -- "$snapshot_parent"
}
trap cleanup EXIT
snapshot="$snapshot_parent/source"
python3 -I "$governance/scripts/secpal-trivy-repository-scan.py" prepare-snapshot \
  --workspace "$repo_root" --commit "$GITHUB_SHA" --destination "$snapshot"
python3 scripts/container-publisher.py bases --root "$snapshot" --arch "$arch" --runtime "$runtime" --output "$output/bases.json"
runtime_base=$(jq -er '.[] | select(.stage=="extensions") | (.reference | split("@")[0] | split(":")[0])+"@"+.digest' "$output/bases.json")
build_arguments=(--build-arg "$runtime_argument=$runtime_base")
if [ "$(jq -er .composer <<< "$definition")" = true ]; then
  composer_base=$(jq -er '.[] | select(.stage=="composer") | (.reference | split("@")[0] | split(":")[0])+"@"+.digest' "$output/bases.json")
  buildah pull --arch "$arch" "$composer_base"
  build_arguments+=(--build-arg "COMPOSER_IMAGE=$composer_base")
fi
buildah pull --arch "$arch" "$runtime_base"
epoch=$(jq -er .epoch "$output/source.json")
if [ "$runtime" != nginx ]; then
  build_arguments+=(--build-arg "SOURCE_DATE_EPOCH=$epoch")
fi
created=$(date -u -d "@$epoch" +%Y-%m-%dT%H:%M:%SZ)
reviewed_base=$(jq -er '.[]|select(.stage=="extensions")|.reference' "$output/bases.json")
base_digest=$(jq -er '.[]|select(.stage=="extensions")|.digest' "$output/bases.json")
image="localhost/secpal-api:build-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT-$arch"
component_labels=()
if [ "$runtime" != legacy ]; then
  image="localhost/secpal-api:$runtime-build-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT-$arch"
  component_labels+=(--label "secpal.dev/runtime-component=$runtime")
fi
printf '%s\n' "$image" > "$output/image-ref"
buildah build --format oci --timestamp "$epoch" --pull=never --layers --arch "$arch" \
  --http-proxy=false --iidfile "$output/image-id" \
  "${build_arguments[@]}" "${component_labels[@]}" \
  --label "org.opencontainers.image.source=https://github.com/SecPal/api" \
  --label "org.opencontainers.image.revision=$GITHUB_SHA" \
  --label "org.opencontainers.image.created=$created" \
  --label "org.opencontainers.image.licenses=AGPL-3.0-or-later" \
  --label "org.opencontainers.image.base.name=$reviewed_base" \
  --label "org.opencontainers.image.base.digest=$base_digest" \
  --label "org.opencontainers.image.title=SecPal API" \
  --label "org.opencontainers.image.description=Production API for SecPal operations software" \
  --file "$snapshot/$containerfile" --tag "$image" "$snapshot"
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
if [ "$runtime" = legacy ]; then
  SKIP_BUILD=1 IMAGE_TAG="$image" EXPECTED_ARCH="$arch" tests/docker/smoke.sh
else
  tests/docker/runtime-component.sh "$runtime" "$image" "$arch"
fi
python3 scripts/container-publisher.py source --root "$repo_root" --commit "$GITHUB_SHA" --runtime "$runtime" --output "$output/source-after.json"
cmp "$output/source.json" "$output/source-after.json"

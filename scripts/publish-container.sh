#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Exact API publisher stages; not an arbitrary registry-command interface.
set -euo pipefail
stage=${1:?publisher stage}
test "${GITHUB_REPOSITORY:?}" = SecPal/api
test "${GITHUB_EVENT_NAME:?}" = workflow_dispatch
test "${GITHUB_REF:?}" = refs/heads/main
repo_root=$(git rev-parse --show-toplevel)
cd "$repo_root"
repository=ghcr.io/secpal/api
prepared="$repo_root/.context/publisher"
tools="${RUNNER_TEMP:?}/secpal-publisher-tools"
governance="$repo_root/.context/publisher-governance"
auth_root=$(mktemp -d "$RUNNER_TEMP/secpal-api-registry.XXXXXX")
export DOCKER_CONFIG="$auth_root"
export REGISTRY_AUTH_FILE="$auth_root/config.json"
cleanup() {
  rm -f -- "$REGISTRY_AUTH_FILE"
  rmdir "$auth_root"
}
trap cleanup EXIT
if [ "$stage" = verify ]; then
  printf '%s' "${GH_TOKEN:?}" | podman login ghcr.io --authfile "$REGISTRY_AUTH_FILE" \
    --username "${GITHUB_ACTOR:?}" --password-stdin
elif [ "$stage" != evidence ]; then
  printf '%s' "${GH_TOKEN:?}" | "$tools/oras" login ghcr.io --registry-config "$REGISTRY_AUTH_FILE" \
    --username "${GITHUB_ACTOR:?}" --password-stdin
fi
if [ -f "$REGISTRY_AUTH_FILE" ]; then chmod 0600 "$REGISTRY_AUTH_FILE"; fi
verify_attestation() {
  local digest=$1 output=$2
  gh attestation verify "oci://$repository@$digest" --bundle-from-oci \
    --repo SecPal/api --signer-workflow SecPal/api/.github/workflows/publish-container.yml \
    --signer-digest "$GITHUB_SHA" --source-ref refs/heads/main --source-digest "$GITHUB_SHA" \
    --deny-self-hosted-runners --format json > "$output"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" verify-attestation \
    --input "$output" --digest "$digest"
}
publish_discovery() {
  local index_digest=$1 published_tag=$2 source_manifest=$3
    # Existing pointers are verified, never overwritten. Only the pinned CLI's
    # exact registry-not-found result permits creating a previously absent tag.
    if "$tools/oras" manifest fetch --registry-config "$REGISTRY_AUTH_FILE" \
      "$repository:$published_tag" > "$prepared/existing-discovery.json" 2> "$RUNNER_TEMP/discovery-status"; then
      cmp "$source_manifest" "$prepared/existing-discovery.json"
    else
      expected_missing="Error response from registry: failed to fetch the content of \"$repository:$published_tag\": $repository:$published_tag: not found"
      if [ "$(cat "$RUNNER_TEMP/discovery-status")" != "$expected_missing" ]; then
        echo 'Discovery presence could not be authenticated' >&2
        exit 1
      fi
      "$tools/oras" tag --registry-config "$REGISTRY_AUTH_FILE" "$repository@$index_digest" "$published_tag"
    fi
    "$tools/oras" manifest fetch --registry-config "$REGISTRY_AUTH_FILE" "$repository:$published_tag" > "$prepared/discovery-index.json"
    cmp "$source_manifest" "$prepared/discovery-index.json"
}

prepare_component() {
    local component=$1 prepared="$prepared/$1"
    local security_file=.context/repository-security/result.json
    local index_digest sboms arch native manifest layout sbom_digest spdx_digest config
    python3 scripts/container-publisher.py prepare --root "$repo_root" --native .context/native \
      --security "$security_file" --runtime "$component" --output "$prepared"
    index_digest=$(jq -er .index_digest "$prepared/prepared.json")
    sboms='[]'
    for arch in amd64 arm64; do
      native="$repo_root/.context/native/api-native-$arch-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT/$component"
      manifest=$(jq -er .subject.manifest_digest "$native/record.json")
      "$tools/oras" cp --to-registry-config "$REGISTRY_AUTH_FILE" --from-oci-layout \
        "$native/image:api" "$repository@$manifest"
      layout="$RUNNER_TEMP/sbom-$component-$arch"
      (
        cd "$native"
        "$tools/oras" push --oci-layout --image-spec v1.1 --artifact-type application/vnd.secpal-syft.spdx.v1+json \
          --export-manifest "$prepared/sbom-manifest-$arch.json" "$layout:sbom" \
          syft.spdx.json:application/spdx+json association.json:application/json
      )
      sbom_digest="sha256:$(sha256sum "$prepared/sbom-manifest-$arch.json" | cut -d ' ' -f1)"
      "$tools/oras" cp --to-registry-config "$REGISTRY_AUTH_FILE" --from-oci-layout \
        "$layout:sbom" "$repository@$sbom_digest"
      spdx_digest=$(jq -er .spdx_digest "$native/association.json")
      sboms=$(jq -c --arg platform "linux/$arch" --arg digest "$manifest" --arg artifact "$repository@$sbom_digest" \
        --arg spdx "$spdx_digest" '.+[{platform:$platform,subject_digest:$digest,artifact:$artifact,spdx_sha256:$spdx,syft_version:"1.54.1"}]' <<< "$sboms")
    done
    printf '%s\n' "$sboms" > "$prepared/sboms.json"
    "$tools/oras" manifest push --registry-config "$REGISTRY_AUTH_FILE" \
      "$repository@$index_digest" "$prepared/index.json"
    "$tools/oras" manifest fetch --registry-config "$REGISTRY_AUTH_FILE" "$repository@$index_digest" > "$prepared/remote-index.json"
    cmp "$prepared/index.json" "$prepared/remote-index.json"
    for arch in amd64 arm64; do
      manifest=$(jq -er --arg arch "linux/$arch" '.records[]|select(.subject.platform==$arch)|.subject.manifest_digest' "$prepared/prepared.json")
      config=$(jq -er --arg arch "linux/$arch" '.records[]|select(.subject.platform==$arch)|.subject.config_digest' "$prepared/prepared.json")
      "$tools/oras" manifest fetch --registry-config "$REGISTRY_AUTH_FILE" "$repository@$manifest" > "$prepared/remote-platform-$arch.json"
      "$tools/oras" blob fetch --registry-config "$REGISTRY_AUTH_FILE" --output "$prepared/remote-config-$arch.json" "$repository@$config"
      cmp "$prepared/platform-$arch.json" "$prepared/remote-platform-$arch.json"
      cmp "$prepared/config-$arch.json" "$prepared/remote-config-$arch.json"
      python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" verify-index \
        --index-manifest "$prepared/remote-index.json" --index-digest "$index_digest" \
        --platform-manifest "$prepared/remote-platform-$arch.json" --platform-digest "$manifest" --platform "linux/$arch"
    done
}

pull_verified_component() {
    local manifest=$1
    verify_attestation "$manifest" "$RUNNER_TEMP/platform-attestation.json"
    podman pull --authfile "$REGISTRY_AUTH_FILE" "$repository@$manifest"
    test "$(podman image inspect --format '{{.Digest}}' "$repository@$manifest")" = "$manifest"
}

verify_component_evidence() {
    local component=$1 readback=$2 prepared="$prepared/$1"
    local index_digest readback_component bundle manifest arch reference sbom_readback scan
    python3 scripts/container-publisher.py source-security --input "$prepared/source-security.json" --commit "$GITHUB_SHA"
    index_digest=$(jq -er .subject.index_digest "$prepared/build-evidence.json")
    readback_component="$readback/$component"
    bundle="$readback/build-evidence.bundle.json"
    cmp "$prepared/build-evidence.json" "$readback_component/build-evidence.json"
    cmp "$prepared/source-security.json" "$readback_component/source-security.json"
    gh attestation verify "$readback_component/build-evidence.json" --bundle "$bundle" \
      --repo SecPal/api --signer-workflow SecPal/api/.github/workflows/publish-container.yml \
      --signer-digest "$GITHUB_SHA" --source-ref refs/heads/main --source-digest "$GITHUB_SHA" --deny-self-hosted-runners
    verify_attestation "$index_digest" "$prepared/index-attestation.json"
    for manifest in $(jq -er '.subject.platforms[].manifest_digest' "$prepared/build-evidence.json"); do
      verify_attestation "$manifest" "$RUNNER_TEMP/verified-platform-attestation.json"
    done
    for arch in amd64 arm64; do
      reference=$(jq -er --arg platform "linux/$arch" '.sboms[]|select(.platform==$platform)|.artifact' "$prepared/build-evidence.json")
      verify_attestation "${reference##*@}" "$RUNNER_TEMP/verified-sbom-attestation.json"
      sbom_readback=$(mktemp -d "$RUNNER_TEMP/secpal-sbom-readback.XXXXXX")
      "$tools/oras" pull --registry-config "$REGISTRY_AUTH_FILE" --output "$sbom_readback" "$reference"
      scan=".context/scan/$component/$arch"
      cmp "$scan/sbom/syft.spdx.json" "$sbom_readback/syft.spdx.json"
      cmp "$scan/sbom/association.json" "$sbom_readback/association.json"
      rm -rf -- "$sbom_readback"
    done
    "$tools/oras" manifest fetch --registry-config "$REGISTRY_AUTH_FILE" "$repository@$index_digest" > "$prepared/accepted-index.json"
    cmp "$prepared/index.json" "$prepared/accepted-index.json"
}

case "$stage" in
    prepare)
      rm -f -- "$prepared/runtime-pair.json"
      for role in php-fpm nginx; do prepare_component "$role"; done
      python3 - "$prepared" <<'PYPAIR'
import json,os,subprocess,sys
from pathlib import Path
root=Path(sys.argv[1]);candidates={}
for role in ('php-fpm','nginx'):
    candidates[role]=json.loads(subprocess.check_output([
        'python3','scripts/container-publisher.py','component-subjects','--prepared',str(root/role)]))
(root/'candidates.json').write_text(json.dumps(candidates,sort_keys=True,separators=(',',':'))+'\n')
with open(os.environ['GITHUB_OUTPUT'],'a') as output:
    output.write('candidates='+json.dumps(candidates,separators=(',',':'))+'\n')
PYPAIR
      ;;
    verify)
      arch=${2:?native architecture}
      case "$arch:$(uname -m):${RUNNER_ARCH:-}" in amd64:x86_64:X64|arm64:aarch64:ARM64) ;; *) exit 1 ;; esac
      test "$(podman info --format '{{.Host.Security.Rootless}}')" = true
      # shellcheck disable=SC2016 # jq binds $platform.
      platform_filter='.records[] | select(.subject.platform==$platform) | .subject.manifest_digest'
      fpm_manifest=$(jq -er --arg platform "linux/$arch" "$platform_filter" "$prepared/php-fpm/prepared.json")
      nginx_manifest=$(jq -er --arg platform "linux/$arch" "$platform_filter" "$prepared/nginx/prepared.json")
      pull_verified_component "$fpm_manifest"
      pull_verified_component "$nginx_manifest"
      PHP_FPM_IMAGE="$repository@$fpm_manifest"
      NGINX_IMAGE="$repository@$nginx_manifest"
      export PHP_FPM_IMAGE NGINX_IMAGE
      EXPECTED_ARCH="$arch" tests/docker/smoke.sh
      python3 - "$arch" "$fpm_manifest" "$nginx_manifest" <<'PYSMOKE'
import json,os,sys
from datetime import datetime,timezone
from pathlib import Path
arch,fpm,nginx=sys.argv[1:]
pair={'php-fpm':fpm,'nginx':nginx}
for role in pair:
    folder=Path('.context/smoke')/role;folder.mkdir(parents=True,exist_ok=True)
    document=dict(platform='linux/'+arch,digest=pair[role],runner_architecture=arch,
                  test='tests/docker/smoke.sh',result='SUCCEEDED',
                  completed_at=datetime.now(timezone.utc).isoformat(),runtime_pair=pair,
                  workflow_run_id=os.environ['GITHUB_RUN_ID'],workflow_run_attempt=os.environ['GITHUB_RUN_ATTEMPT'])
    (folder/'smoke.json').write_text(json.dumps(document,sort_keys=True,separators=(',',':'))+'\n')
PYSMOKE
      ;;
    evidence)
      python3 scripts/container-publisher.py complete-pair --root "$repo_root" --prepared "$prepared" \
        --scan-root .context/scan --smoke-root .context/smoke --output "$prepared/runtime-pair.json"
      ;;
    retain-evidence)
      evidence_files=(runtime-pair.json:application/json build-evidence.bundle.json:application/json
        php-fpm/build-evidence.json:application/json php-fpm/source-security.json:application/json
        nginx/build-evidence.json:application/json nginx/source-security.json:application/json)
      (
        cd "$prepared"
        "$tools/oras" push --oci-layout --image-spec v1.1 --artifact-type application/vnd.secpal-build-evidence.v1+json \
          --export-manifest build-evidence-manifest.json "$RUNNER_TEMP/build-evidence:build" \
          "${evidence_files[@]}"
      )
      evidence_digest="sha256:$(sha256sum "$prepared/build-evidence-manifest.json" | cut -d ' ' -f1)"
      "$tools/oras" cp --to-registry-config "$REGISTRY_AUTH_FILE" --from-oci-layout \
        "$RUNNER_TEMP/build-evidence:build" "$repository@$evidence_digest"
      printf 'evidence_digest=%s\n' "$evidence_digest" >> "$GITHUB_OUTPUT"
      ;;
    accept)
      evidence_digest=${2:?attested runtime-pair evidence digest}
      verify_attestation "$evidence_digest" "$prepared/runtime-pair-artifact-attestation.json"
      readback=$(mktemp -d "$RUNNER_TEMP/secpal-runtime-pair.XXXXXX")
      "$tools/oras" pull --registry-config "$REGISTRY_AUTH_FILE" --output "$readback" "$repository@$evidence_digest"
      cmp "$prepared/runtime-pair.json" "$readback/runtime-pair.json"
      cmp "$prepared/build-evidence.bundle.json" "$readback/build-evidence.bundle.json"
      gh attestation verify "$readback/runtime-pair.json" --bundle "$readback/build-evidence.bundle.json" \
        --repo SecPal/api --signer-workflow SecPal/api/.github/workflows/publish-container.yml \
        --signer-digest "$GITHUB_SHA" --source-ref refs/heads/main --source-digest "$GITHUB_SHA" --deny-self-hosted-runners
      for role in php-fpm nginx; do verify_component_evidence "$role" "$readback"; done
      rm -rf -- "$readback"
      python3 scripts/container-publisher.py admission-pair --root "$repo_root" --prepared "$prepared" \
        --scan-root .context/scan --smoke-root .context/smoke
      candidate_attempt=$(jq -er .workflow.run_attempt "$prepared/runtime-pair.json")
      published_tag="runtime-pair-$GITHUB_SHA-$GITHUB_RUN_ID-$candidate_attempt"
      publish_discovery "$evidence_digest" "$published_tag" "$prepared/build-evidence-manifest.json"
      printf 'runtime_pair_digest=%s\n' "$evidence_digest" >> "$GITHUB_OUTPUT"
      printf 'Accepted runtime pair: %s@%s\n' "$repository" "$evidence_digest" >> "$GITHUB_STEP_SUMMARY"
      ;;
    *) echo 'Unsupported publisher stage' >&2; exit 1 ;;
esac

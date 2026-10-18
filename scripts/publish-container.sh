#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Exact API publisher stages; not an arbitrary registry-command interface.
set -euo pipefail
stage=${1:?publisher stage}
test "${GITHUB_REPOSITORY:?}" = SecPal/api
test "${GITHUB_EVENT_NAME:?}" = push
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
case "$stage" in
  prepare)
    security_files=(.context/repository-security/*/result.json)
    test "${#security_files[@]}" -eq 1
    python3 - "$governance/docs/schemas/secpal-trivy-repository-scan-v2.schema.json" "${security_files[0]}" <<'PY'
import json,sys
from jsonschema import Draft202012Validator, ValidationError
try:
    schema=json.load(open(sys.argv[1],encoding="utf-8"))
    Draft202012Validator(schema,format_checker=Draft202012Validator.FORMAT_CHECKER).validate(
        json.load(open(sys.argv[2],encoding="utf-8")))
except (ValidationError, json.JSONDecodeError, OSError):
    raise SystemExit("FAIL: malformed source-security evidence") from None
PY
    python3 scripts/container-publisher.py prepare --root "$repo_root" --native .context/native \
      --security "${security_files[0]}" --output "$prepared"
    index_digest=$(jq -er .index_digest "$prepared/prepared.json")
    sboms='[]'
    subjects=$(jq -c '[.index_digest]+[.records[].subject.manifest_digest]' "$prepared/prepared.json")
    for arch in amd64 arm64; do
      native="$repo_root/.context/native/api-native-$arch-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
      manifest=$(jq -er .subject.manifest_digest "$native/record.json")
      "$tools/oras" cp --registry-config "$REGISTRY_AUTH_FILE" --from-oci-layout \
        "$native/image:api" "$repository@$manifest"
      layout="$RUNNER_TEMP/sbom-$arch"
      (
        cd "$native"
        "$tools/oras" push --oci-layout --image-spec v1.1 --artifact-type application/vnd.secpal-syft.spdx.v1+json \
          --export-manifest "$prepared/sbom-manifest-$arch.json" "$layout:sbom" \
          syft.spdx.json:application/spdx+json association.json:application/json
      )
      sbom_digest="sha256:$(sha256sum "$prepared/sbom-manifest-$arch.json" | cut -d ' ' -f1)"
      "$tools/oras" cp --registry-config "$REGISTRY_AUTH_FILE" --from-oci-layout \
        "$layout:sbom" "$repository@$sbom_digest"
      spdx_digest=$(jq -er .spdx_digest "$native/association.json")
      sboms=$(jq -c --arg platform "linux/$arch" --arg digest "$manifest" --arg artifact "$repository@$sbom_digest" \
        --arg spdx "$spdx_digest" '.+[{platform:$platform,subject_digest:$digest,artifact:$artifact,spdx_sha256:$spdx,syft_version:"1.54.1"}]' <<< "$sboms")
      subjects=$(jq -c --arg value "$sbom_digest" '.+[$value]' <<< "$subjects")
      printf '%s_digest=%s\n%s_sbom=%s\n%s_sbom_digest=%s\n' "$arch" "$manifest" "$arch" "$repository@$sbom_digest" "$arch" "$sbom_digest" >> "$GITHUB_OUTPUT"
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
    printf 'index_digest=%s\nsubjects=%s\n' "$index_digest" "$subjects" >> "$GITHUB_OUTPUT"
    ;;
  verify)
    arch=${2:?native architecture}
    case "$arch:$(uname -m):${RUNNER_ARCH:-}" in amd64:x86_64:X64|arm64:aarch64:ARM64) ;; *) exit 1 ;; esac
    test "$(podman info --format '{{.Host.Security.Rootless}}')" = true
    manifest=$(jq -er --arg arch "linux/$arch" '.records[]|select(.subject.platform==$arch)|.subject.manifest_digest' "$prepared/prepared.json")
    verify_attestation "$manifest" "$RUNNER_TEMP/platform-attestation.json"
    podman pull --authfile "$REGISTRY_AUTH_FILE" "$repository@$manifest"
    test "$(podman image inspect --format '{{.Digest}}' "$repository@$manifest")" = "$manifest"
    SKIP_BUILD=1 IMAGE_TAG="$repository@$manifest" EXPECTED_ARCH="$arch" tests/docker/smoke.sh
    mkdir -p .context/smoke
    jq -n --arg platform "linux/$arch" --arg digest "$manifest" --arg arch "$arch" \
      --arg completed "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --arg run "$GITHUB_RUN_ID" --arg attempt "$GITHUB_RUN_ATTEMPT" \
      '{platform:$platform,digest:$digest,runner_architecture:$arch,test:"tests/docker/smoke.sh",result:"SUCCEEDED",completed_at:$completed,workflow_run_id:$run,workflow_run_attempt:$attempt}' \
      > .context/smoke/smoke.json
    ;;
  evidence)
    python3 scripts/container-publisher.py complete --prepared "$prepared" --scan-root .context/scan \
      --smoke-root .context/smoke --root "$repo_root" --output "$prepared/build-evidence.json"
    ;;
  retain-evidence)
    # The file attestation already exists; retain its exact bytes and bundle as
    # one immutable OCI artifact in the existing official package, never a new tag.
    (
      cd "$prepared"
      "$tools/oras" push --oci-layout --image-spec v1.1 --artifact-type application/vnd.secpal-build-evidence.v1+json \
        --export-manifest build-evidence-manifest.json "$RUNNER_TEMP/build-evidence:build" \
        build-evidence.json:application/json source-security.json:application/json build-evidence.bundle.json:application/json
    )
    evidence_digest="sha256:$(sha256sum "$prepared/build-evidence-manifest.json" | cut -d ' ' -f1)"
    "$tools/oras" cp --registry-config "$REGISTRY_AUTH_FILE" --from-oci-layout \
      "$RUNNER_TEMP/build-evidence:build" "$repository@$evidence_digest"
    printf 'evidence_digest=%s\n' "$evidence_digest" >> "$GITHUB_OUTPUT"
    ;;
  accept)
    python3 scripts/container-publisher.py source-security --input "$prepared/source-security.json" --commit "$GITHUB_SHA"
    index_digest=$(jq -er .subject.index_digest "$prepared/build-evidence.json")
    evidence_digest=${2:?attested build evidence artifact digest}
    verify_attestation "$evidence_digest" "$prepared/build-evidence-artifact-attestation.json"
    readback=$(mktemp -d "$RUNNER_TEMP/secpal-evidence-readback.XXXXXX")
    "$tools/oras" pull --registry-config "$REGISTRY_AUTH_FILE" --output "$readback" "$repository@$evidence_digest"
    for name in build-evidence.json source-security.json build-evidence.bundle.json; do
      cmp "$prepared/$name" "$readback/$name"
    done
    gh attestation verify "$readback/build-evidence.json" --bundle "$readback/build-evidence.bundle.json" \
      --repo SecPal/api --signer-workflow SecPal/api/.github/workflows/publish-container.yml \
      --signer-digest "$GITHUB_SHA" --source-ref refs/heads/main --source-digest "$GITHUB_SHA" --deny-self-hosted-runners
    rm -rf -- "$readback"
    verify_attestation "$index_digest" "$prepared/index-attestation.json"
    for manifest in $(jq -er '.subject.platforms[].manifest_digest' "$prepared/build-evidence.json"); do
      verify_attestation "$manifest" "$RUNNER_TEMP/verified-platform-attestation.json"
    done
    while IFS= read -r reference; do
      verify_attestation "${reference##*@}" "$RUNNER_TEMP/verified-sbom-attestation.json"
    done < <(jq -er '.sboms[].artifact' "$prepared/build-evidence.json")
    "$tools/oras" manifest fetch --registry-config "$REGISTRY_AUTH_FILE" "$repository@$index_digest" > "$prepared/accepted-index.json"
    cmp "$prepared/index.json" "$prepared/accepted-index.json"
    published_tag="build-$GITHUB_SHA-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
    "$tools/oras" tag --registry-config "$REGISTRY_AUTH_FILE" "$repository@$index_digest" "$published_tag"
    "$tools/oras" manifest fetch --registry-config "$REGISTRY_AUTH_FILE" "$repository:$published_tag" > "$prepared/discovery-index.json"
    cmp "$prepared/index.json" "$prepared/discovery-index.json"
    {
      printf '## Published SecPal API image\n\n'
      # Markdown backticks are literal output.
      # shellcheck disable=SC2016
      printf -- '- Source commit: `%s`\n' "$GITHUB_SHA"
      # shellcheck disable=SC2016
      printf -- '- Discovery tag: `%s:%s`\n' "$repository" "$published_tag"
      # shellcheck disable=SC2016
      printf -- '- Canonical digest: `%s@%s`\n' "$repository" "$index_digest"
      # shellcheck disable=SC2016
      printf -- '- Build evidence: `%s@%s`\n' "$repository" "$evidence_digest"
      # shellcheck disable=SC2016
      printf -- '- Platforms: `linux/amd64`, `linux/arm64`\n'
      printf -- '- Syft, Grype, Trivy, native smoke and GitHub attestations: verified\n'
      printf 'The discovery tag is not a deployment or trust reference.\n'
    } >> "$GITHUB_STEP_SUMMARY"
    ;;
  *) echo 'Unsupported publisher stage' >&2; exit 1 ;;
esac

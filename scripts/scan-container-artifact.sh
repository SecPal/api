#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Publisher observation only; accepted .github adapters/policy own decisions.
set -euo pipefail
test "${GITHUB_REPOSITORY:?}" = SecPal/api
mode=${1:-published}
runtime_set=${API_RUNTIME_SET:-legacy}
case "$mode:$runtime_set:${GITHUB_EVENT_NAME:?}" in
  local:pair:pull_request) ;;
  published:legacy:push|published:pair:workflow_dispatch)
    test "${GITHUB_REF:?}" = refs/heads/main ;;
  *) echo 'Artifact scan invocation rejected' >&2; exit 1 ;;
esac
repo_root=$(git rev-parse --show-toplevel)
cd "$repo_root"
prepared_root="$repo_root/.context/publisher"
governance="$repo_root/.context/publisher-governance"
tools="${RUNNER_TEMP:?}/secpal-publisher-tools"
registry=ghcr.io/secpal/api
auth_root=$(mktemp -d "$RUNNER_TEMP/secpal-api-scan-auth.XXXXXX")
export DOCKER_CONFIG="$auth_root"
cleanup() {
  rm -f -- "$DOCKER_CONFIG/config.json"
  rmdir "$auth_root"
}
trap 'cleanup' EXIT
if [ "$mode" = published ]; then
printf '%s' "${GH_TOKEN:?}" | "$tools/oras" login ghcr.io --registry-config "$auth_root/config.json" \
  --username "${GITHUB_ACTOR:?}" --password-stdin
chmod 0600 "$auth_root/config.json"
fi
cache=$(mktemp -d "$RUNNER_TEMP/secpal-api-scan-cache.XXXXXX")
printf '{}\n' > "$cache/config.yaml"
export GRYPE_DB_CACHE_DIR="$cache/grype"
export GRYPE_DB_AUTO_UPDATE=false
export GRYPE_CHECK_FOR_APP_UPDATE=false
"$tools/grype" --config "$cache/config.yaml" db update
env -i HOME="$cache" "$tools/trivy" --config "$cache/config.yaml" image \
  --download-db-only --cache-dir "$cache/trivy"
env -i HOME="$cache" "$tools/trivy" --config "$cache/config.yaml" image \
  --download-java-db-only --cache-dir "$cache/trivy"
vex_arguments=()
if [ -d "$governance/reviewed-vex" ]; then
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" list-vex \
    --directory "$governance/reviewed-vex" > "$cache/vex-list"
  while IFS= read -r -d '' manifest; do
    python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" verify-vex \
      --checkout "$governance" --manifest "$manifest" --trusted-repository SecPal/.github \
      --trusted-commit "$(git -C "$governance" rev-parse HEAD)"
    vex_arguments+=(--reviewed-vex-manifest "$manifest")
  done < "$cache/vex-list"
fi
policy_failure=0
components=(legacy)
if [ "$runtime_set" = pair ]; then components=(php-fpm nginx); fi
for component in "${components[@]}"; do
  prepared="$prepared_root"
  scan_root="$repo_root/.context/scan"
  if [ "$component" != legacy ]; then
    prepared="$prepared/$component"
    scan_root="$scan_root/$component"
  fi
for arch in amd64 arm64; do
  output="$scan_root/$arch"
  mkdir -p "$output/raw" "$output/sbom"
  if [ "$mode" = local ]; then
    native="$repo_root/.context/native/api-native-$arch-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT/$component"
    python3 scripts/container-publisher.py verify-native --root "$repo_root" --directory "$native" \
      --arch "$arch" --runtime "$component" --output "$output/subject.json"
    manifest=$(jq -er .oci_digest "$output/subject.json")
    cp "$native/syft.spdx.json" "$output/sbom/syft.spdx.json"
    cp "$native/association.json" "$output/sbom/association.json"
    # Trivy 0.74 supports OCI directories selected by manifest digest. The
    # canonical subject names the local layout; no registry push or JSON rewrite.
    input_root="$cache/input-$component-$arch"
    mkdir -p "$input_root/ghcr.io/secpal"
    cp -a "$native/image" "$input_root/$registry"
    python3 scripts/container-publisher.py verify-native --root "$repo_root" --directory "$native" \
      --arch "$arch" --runtime "$component" --layout "$input_root/$registry" --output "$output/local-input-subject.json"
    cmp "$output/subject.json" "$output/local-input-subject.json"
    jq -n --arg subject "$registry@$manifest" --arg native "$native" \
      '{transport:"local-oci-directory",subject:$subject,native_artifact:$native,published:false}' > "$output/local-input.json"
    trivy_input=(--input "$registry@$manifest")
  else
  manifest=$(jq -er --arg arch "linux/$arch" '.records[]|select(.subject.platform==$arch)|.subject.manifest_digest' "$prepared/prepared.json")
  sbom=$(jq -er --arg arch "linux/$arch" '.[]|select(.platform==$arch)|.artifact' "$prepared/sboms.json")
  for subject in "$registry@$manifest" "$sbom" "$registry@$(jq -er .index_digest "$prepared/prepared.json")"; do
    attestation_file="$(python3 scripts/container-publisher.py attestation-filename --digest "${subject##*@}")"
    gh attestation verify "oci://$subject" --bundle-from-oci --repo SecPal/api \
      --signer-workflow SecPal/api/.github/workflows/publish-container.yml \
      --signer-digest "$GITHUB_SHA" --source-ref refs/heads/main --source-digest "$GITHUB_SHA" \
      --deny-self-hosted-runners --format json > "$output/raw/${attestation_file}"
    python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" verify-attestation \
      --input "$output/raw/${attestation_file}" --digest "${subject##*@}"
  done
  "$tools/oras" pull --registry-config "$auth_root/config.json" --output "$output/sbom" "$sbom"
    trivy_input=("$registry@$manifest")
  fi
  jq -n --arg digest "$manifest" --arg platform "linux/$arch" \
    '{oci_digest:$digest,platform:$platform}' > "$output/subject.json"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" verify-syft \
    --platform-digest "$manifest" --platform "linux/$arch" --spdx "$output/sbom/syft.spdx.json" \
    --association "$output/sbom/association.json" --output "$output/syft.json"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" database \
    --scanner trivy --database-status "$cache/trivy/db/metadata.json" --database-root "$cache/trivy/db" --output "$output/trivy-core-database.json"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" database \
    --scanner trivy-java --database-status "$cache/trivy/java-db/metadata.json" --database-root "$cache/trivy/java-db" --output "$output/trivy-java-database.json"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" combine-databases \
    --database "$output/trivy-core-database.json" --database "$output/trivy-java-database.json" --output "$output/trivy-database.json"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" scan-grype \
    --archive "$tools/grype.tar.gz" --spdx "$output/sbom/syft.spdx.json" \
    --association "$output/sbom/association.json" --subject "$output/subject.json" \
    --database-root "$cache/grype" --native "$output/raw/grype.json" \
    --metadata "$output/grype-metadata.json" --provenance "$output/grype-input.json"
  (
  if [ "$mode" = local ]; then cd "$input_root"; fi
  env -i HOME="$cache" DOCKER_CONFIG="$auth_root" "$tools/trivy" --config "$cache/config.yaml" image \
    --scanners vuln --format json --show-suppressed --skip-db-update --skip-java-db-update \
    --skip-check-update --skip-version-check --cache-dir "$cache/trivy" --platform "linux/$arch" \
    --ignorefile "$governance/policies/trivy-repository-scan-ignore-v1.yaml" \
    --output "$output/raw/trivy.json" "${trivy_input[@]}"
  )
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" metadata --scanner trivy \
    --database "$output/trivy-database.json" --subject "$output/subject.json" --version 0.74.0 \
    --immutable-id sha256:2ae6fe3ee734b7fdf11335663e18c75ea12dccc76062f09f164a3b0f8be4371a \
    --completed-at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --output "$output/trivy-metadata.json"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" adapt --scanner grype \
    --native "$output/raw/grype.json" --metadata "$output/grype-metadata.json" \
    --provenance "$output/grype-input.json" --spdx "$output/sbom/syft.spdx.json" \
    --association "$output/sbom/association.json" --output "$output/grype-adapted.json"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" adapt --scanner trivy \
    --native "$output/raw/trivy.json" --metadata "$output/trivy-metadata.json" \
    --output "$output/trivy-adapted.json"
  python3 "$governance/scripts/secpal-vulnerability-reevaluation.py" assemble \
    --subject "$output/subject.json" --syft "$output/syft.json" \
    --grype "$output/grype-adapted.json" --trivy "$output/trivy-adapted.json" \
    --evaluated-at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --output "$output/evaluation.json"
  python3 "$governance/scripts/secpal-vulnerability-policy.py" "$output/evaluation.json" \
    --policy "$governance/policies/vulnerability-triage-v1.json" "${vex_arguments[@]}" > "$output/policy-result.json"
  python3 - "$output" "$arch" <<'PY'
import hashlib,json,sys
from pathlib import Path
root=Path(sys.argv[1]);arch=sys.argv[2]
policy=json.loads((root/'policy-result.json').read_bytes())
if (policy['evidence_health']['state']!='HEALTHY'
        or policy['gate_state'] not in ('CLEAN','ACTIONABLE','REVIEW_REQUIRED')):
    raise SystemExit('Artifact vulnerability evidence is not healthy and complete')
subject=json.loads((root/'subject.json').read_bytes())
association=json.loads((root/'sbom/association.json').read_bytes())
records=[]
for scanner in ('grype','trivy'):
    metadata=json.loads((root/(scanner+'-metadata.json')).read_bytes())
    records.append(dict(platform='linux/'+arch,scanner=scanner,scanner_version=metadata['scanner_identity']['version'],
                        database=metadata['database'],subject_digest=association['spdx_digest'] if scanner=='grype' else subject['oci_digest'],
                        completed_at=metadata['completed_at'],status=metadata['status'],policy_result=policy['gate_state'],
                        evidence_sha256='sha256:'+hashlib.sha256((root/'raw'/(scanner+'.json')).read_bytes()).hexdigest()))
(root/'scan-records.json').write_text(json.dumps(records,sort_keys=True,separators=(',',':'))+'\n')
PY
  if [ "$mode" = local ] && [ "$(jq -er .gate_state "$output/policy-result.json")" != CLEAN ]; then
    policy_failure=1
  fi
done
done
# No DB payload or registry credential is an evidence artifact.
rm -rf -- "$cache"
cleanup
trap - EXIT
exit "$policy_failure"

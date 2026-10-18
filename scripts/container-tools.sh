#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
mode=${1:?build or publish}
tool_root=${2:?private tool directory}
install -d -m 0700 "$tool_root"
case "$(uname -m)" in
  x86_64) arch=amd64; syft_sha=c069905b391cc4c20a5ba65ad5c10be2a7ba074f8ea6ad203e24d14e303dad47 ;;
  aarch64) arch=arm64; syft_sha=dfdf0537610113edbefe1f1fc6548bc957b2d77439636ec824fcf0e10d46d054 ;;
  *) echo 'Unsupported native tool architecture' >&2; exit 1 ;;
esac
download() {
  local name=$1 url=$2 sha=$3
  curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 \
    --output "$tool_root/$name.tar.gz" "$url"
  printf '%s  %s\n' "$sha" "$tool_root/$name.tar.gz" | sha256sum --check --strict
  tar -xOf "$tool_root/$name.tar.gz" "${4:-$name}" > "$tool_root/$name"
  chmod 0500 "$tool_root/$name"
}
github_cli() {
  local gh_sha=ed4960225d2833e04a61590d9fa2b5773d147f3aa375459e5466a40c102f3832
  if [ "$arch" = arm64 ]; then gh_sha=564eff56a61e8caf193efde16937fba879eb62a3a479c9dd6be2001e7647680b; fi
  download gh "https://github.com/cli/cli/releases/download/v2.99.0/gh_2.99.0_linux_${arch}.tar.gz" "$gh_sha" "gh_2.99.0_linux_${arch}/bin/gh"
  printf '%s\n' "$tool_root" >> "${GITHUB_PATH:?}"
}
case "$mode" in
  verify) github_cli ;;
  build)
    download syft "https://github.com/anchore/syft/releases/download/v1.54.1/syft_1.54.1_linux_${arch}.tar.gz" "$syft_sha"
    ;;
  registry|publish)
    test "$arch" = amd64
    github_cli
    download oras https://github.com/oras-project/oras/releases/download/v1.3.4/oras_1.3.4_linux_amd64.tar.gz f27adb935022d94df8dc77719c322dda592c78a0d57a6f7dcdd8d900b248c454
    if [ "$mode" = publish ]; then
      download grype https://github.com/anchore/grype/releases/download/v0.118.0/grype_0.118.0_linux_amd64.tar.gz 1d444c5e7360471815f7158f71935fcecc68a3c417d85c7344f770854300bba2
      download trivy https://github.com/aquasecurity/trivy/releases/download/v0.74.0/trivy_0.74.0_Linux-64bit.tar.gz 2ae6fe3ee734b7fdf11335663e18c75ea12dccc76062f09f164a3b0f8be4371a
    fi
    ;;
  *) echo 'Unsupported publisher tool set' >&2; exit 1 ;;
esac
printf '{}\n' > "$tool_root/empty.yaml"
chmod 0400 "$tool_root/empty.yaml"

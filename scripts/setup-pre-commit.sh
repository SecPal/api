#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2025 SecPal Contributors
# SPDX-License-Identifier: MIT

set -euo pipefail

echo "🔧 Setting up pre-commit hooks for SecPal..."

# Check if pre-commit is installed
if ! command -v pre-commit &>/dev/null; then
	echo "❌ pre-commit is not installed."
	echo ""
	echo "Install it using one of the following methods:"
	echo ""
	echo "  # Using pip:"
	echo "  pip install pre-commit"
	echo ""
	echo "  # Using brew (macOS):"
	echo "  brew install pre-commit"
	echo ""
	echo "  # Using apt (Debian/Ubuntu):"
	echo "  sudo apt install pre-commit"
	echo ""
	exit 1
fi

# Install pre-commit hooks
echo "📦 Installing pre-commit hooks..."
pre-commit install --install-hooks

# Run hooks on all files to verify setup
echo "🧪 Running hooks on all files to verify setup..."
if pre-commit run --all-files; then
	echo ""
	echo "✅ Pre-commit hooks installed successfully!"
	echo ""
	echo "Hooks will now run automatically on git commit."
	echo "To run manually: pre-commit run --all-files"
	echo "To update hooks: pre-commit autoupdate"
else
	echo ""
	echo "⚠️  Some hooks failed. Please fix the issues above."
	echo ""
	exit 1
fi

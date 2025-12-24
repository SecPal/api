<!--
SPDX-FileCopyrightText: 2025 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **OpenTimestamp Proof Verification** (Issue #412, Epic #385)
  - Implemented secure `verify()` method in `OpenTimestampService` using hybrid validation approach
  - Features:
    - Input validation (digest format, proof structure)
    - Commitment extraction and matching against provided digest
    - Bitcoin attestation presence verification (ensures proof is confirmed on blockchain)
    - Proof result caching (30 days) to avoid redundant verification
    - Comprehensive error handling with graceful degradation
  - Security improvements:
    - Validates digest must be 64-character hex SHA256 hash
    - Rejects proofs without Bitcoin blockchain attestation (pending proofs)
    - Detects commitment mismatches (tampered proofs)
    - Graceful failure handling with detailed logging
  - Added 10 comprehensive unit tests covering:
    - Invalid digest format rejection
    - Empty/malformed proof handling
    - Valid confirmed proof acceptance
    - Wrong commitment detection
    - Pending proof rejection
    - Cache functionality
    - Legacy proof format support
  - All tests passing (10/10), PHPStan Level 9 compliant, Pint formatted
  - Enables full Level 3 audit trail functionality (BewachV § 21 Abs. 4 compliance)

[... rest of file unchanged ...]
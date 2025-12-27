#!/usr/bin/env php
<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Automatically add @property PHPDoc annotations for Pest beforeEach properties.
 *
 * This script scans all Pest test files, identifies properties set in beforeEach closures,
 * and adds corresponding @property annotations to help IDEs understand the dynamic properties.
 */
$testDir = __DIR__.'/../tests';

function scanPestFiles(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

function extractBeforeEachProperties(string $content): array
{
    $properties = [];

    // Match beforeEach(function pattern
    if (! preg_match('/beforeEach\s*\(\s*function\s*\([^)]*\)\s*(?::\s*void\s*)?{/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $start = $matches[0][1] + strlen($matches[0][0]);

    // Find matching closing brace
    $braceLevel = 1;
    $length = strlen($content);
    $end = $start;

    for ($i = $start; $i < $length && $braceLevel > 0; $i++) {
        if ($content[$i] === '{') {
            $braceLevel++;
        } elseif ($content[$i] === '}') {
            $braceLevel--;
            if ($braceLevel === 0) {
                $end = $i;
            }
        }
    }

    if ($braceLevel !== 0) {
        return []; // Mismatched braces
    }

    $beforeEachBlock = substr($content, $start, $end - $start);

    // Extract $this->property = ... assignments
    if (preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*=/m', $beforeEachBlock, $propertyMatches)) {
        $properties = array_unique($propertyMatches[1]);
    }

    return $properties;
}

function inferPropertyType(string $content, string $property): string
{
    // Look for type hints in property assignments
    $patterns = [
        '/\\$this->'.preg_quote($property, '/')."\s*=\s*([A-Z][a-zA-Z0-9_\\\\]+)::(?:factory|create|make)/",
        '/\\$this->'.preg_quote($property, '/')."\s*=\s*app\(([A-Z][a-zA-Z0-9_\\\\]+)::class\)/",
        '/\\$this->'.preg_quote($property, '/')."\s*=\s*new\s+([A-Z][a-zA-Z0-9_\\\\]+)/",
        '/\\$this->'.preg_quote($property, '/')."\s*=\s*Mockery::mock\(([A-Z][a-zA-Z0-9_\\\\]+)::class\)/",
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content, $matches)) {
            return ltrim($matches[1], '\\');
        }
    }

    // Common property name patterns
    $typeMap = [
        'tenant' => 'TenantKey',
        'user' => 'User',
        'employee' => 'Employee',
        'customer' => 'Customer',
        'site' => 'Site',
        'policy' => 'mixed',
        'service' => 'mixed',
        'registrar' => 'PermissionRegistrar',
        'orgUnit' => 'OrganizationalUnit',
        'organizationalUnit' => 'OrganizationalUnit',
    ];

    return $typeMap[$property] ?? 'mixed';
}

function hasExistingDocBlock(string $content): bool
{
    // Check if file already has a class-level docblock with @property
    return (bool) preg_match('/^\/\*\*.*?@property/sm', $content);
}

function addPropertyAnnotations(string $filePath): bool
{
    $content = file_get_contents($filePath);

    if (hasExistingDocBlock($content)) {
        echo '  ⊖ Skipping (already has @property annotations): '.basename($filePath)."\n";

        return false;
    }

    $properties = extractBeforeEachProperties($content);

    if (empty($properties)) {
        return false;
    }

    // Build PHPDoc block
    $docLines = ['/**'];
    foreach ($properties as $property) {
        $type = inferPropertyType($content, $property);
        $docLines[] = " * @property {$type} \${$property}";
    }
    $docLines[] = ' */';
    $docBlock = implode("\n", $docLines)."\n";

    // Find insertion point (after SPDX headers and declare statement)
    $lines = explode("\n", $content);
    $insertIndex = 0;

    foreach ($lines as $index => $line) {
        if (str_starts_with(trim($line), 'uses(')) {
            $insertIndex = $index;
            break;
        }
    }

    if ($insertIndex === 0) {
        echo '  ⚠ Could not find insertion point: '.basename($filePath)."\n";

        return false;
    }

    // Insert docblock before uses()
    array_splice($lines, $insertIndex, 0, $docBlock);
    $newContent = implode("\n", $lines);

    file_put_contents($filePath, $newContent);

    echo '  ✓ Added @property annotations ('.count($properties).'): '.basename($filePath)."\n";

    return true;
}

// Main execution
echo "🔍 Scanning Pest test files...\n";
$files = scanPestFiles($testDir);
echo 'Found '.count($files)." test files\n\n";

$modified = 0;
$skipped = 0;

foreach ($files as $file) {
    if (addPropertyAnnotations($file)) {
        $modified++;
    } else {
        $skipped++;
    }
}

echo "\n✅ Complete!\n";
echo "   Modified: {$modified}\n";
echo "   Skipped: {$skipped}\n";

<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Verifies Adobe isolated security patches via fingerprints from MageWatch remote config.
 *
 * marker_files: paths that exist only after the patch (July-style new files).
 * marker_contains: existing files that must contain a unique patched snippet (August+).
 */
class IsolatedPatchVerifier
{
    /**
     * @return list<array{
     *     patch_id: string,
     *     bulletin_id: string,
     *     status: string,
     *     method: string,
     *     present: list<string>,
     *     missing: list<string>
     * }>
     */
    public function verify(string $magentoRoot, string $magentoVersion, array $checks): array
    {
        $results = [];

        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }

            $patchId = $check['patch_id'] ?? null;
            $bulletinId = $check['bulletin_id'] ?? null;
            $isolatedBase = $check['isolated_base'] ?? null;
            $markerFiles = $check['marker_files'] ?? [];
            $markerContains = $check['marker_contains'] ?? [];

            if (
                !is_string($patchId) || $patchId === ''
                || !is_string($bulletinId) || $bulletinId === ''
                || !is_string($isolatedBase) || $isolatedBase === ''
                || (!is_array($markerFiles) && !is_array($markerContains))
            ) {
                continue;
            }

            if (!is_array($markerFiles)) {
                $markerFiles = [];
            }
            if (!is_array($markerContains)) {
                $markerContains = [];
            }

            if ($markerFiles === [] && $markerContains === []) {
                continue;
            }

            if ($isolatedBase !== $magentoVersion) {
                continue;
            }

            $present = [];
            $missing = [];
            $root = rtrim($magentoRoot, '/\\');

            foreach ($markerFiles as $relativePath) {
                if (!is_string($relativePath) || $relativePath === '') {
                    continue;
                }

                $absolutePath = $root . '/' . ltrim($relativePath, '/');
                if (is_readable($absolutePath)) {
                    $present[] = $relativePath;
                } else {
                    $missing[] = $relativePath;
                }
            }

            foreach ($markerContains as $spec) {
                if (!is_array($spec)) {
                    continue;
                }

                $relativePath = $spec['path'] ?? null;
                $needle = $spec['contains'] ?? null;
                if (!is_string($relativePath) || $relativePath === '' || !is_string($needle) || $needle === '') {
                    continue;
                }

                $absolutePath = $root . '/' . ltrim($relativePath, '/');
                if (!is_readable($absolutePath)) {
                    $missing[] = $relativePath;
                    continue;
                }

                $contents = @file_get_contents($absolutePath);
                if (!is_string($contents) || !str_contains($contents, $needle)) {
                    $missing[] = $relativePath;
                } else {
                    $present[] = $relativePath;
                }
            }

            if ($present === [] && $missing === []) {
                continue;
            }

            $status = 'partial';
            if ($missing === []) {
                $status = 'applied';
            } elseif ($present === []) {
                $status = 'missing';
            }

            $results[] = [
                'patch_id' => $patchId,
                'bulletin_id' => $bulletinId,
                'status' => $status,
                'method' => 'fingerprint',
                'present' => $present,
                'missing' => $missing,
            ];
        }

        return $results;
    }
}

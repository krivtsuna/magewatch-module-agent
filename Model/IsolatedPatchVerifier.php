<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Verifies Adobe isolated security patches via file fingerprints from MageWatch remote config.
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
            $markerFiles = $check['marker_files'] ?? null;

            if (
                !is_string($patchId) || $patchId === ''
                || !is_string($bulletinId) || $bulletinId === ''
                || !is_string($isolatedBase) || $isolatedBase === ''
                || !is_array($markerFiles) || $markerFiles === []
            ) {
                continue;
            }

            if ($isolatedBase !== $magentoVersion) {
                continue;
            }

            $present = [];
            $missing = [];

            foreach ($markerFiles as $relativePath) {
                if (!is_string($relativePath) || $relativePath === '') {
                    continue;
                }

                $absolutePath = rtrim($magentoRoot, '/\\') . '/' . ltrim($relativePath, '/');
                if (is_readable($absolutePath)) {
                    $present[] = $relativePath;
                } else {
                    $missing[] = $relativePath;
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

<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Maps Magento module names (Vendor_Module) to likely Composer package names.
 */
class ModulePackageResolver
{
    /**
     * @var array<string, list<string>>
     */
    private const VENDOR_ALIASES = [
        'stripeintegration' => ['stripe'],
    ];

    /**
     * @return list<string>
     */
    public function packageCandidates(string $moduleName): array
    {
        if (! preg_match('/^([^_]+)_(.+)$/', $moduleName, $matches)) {
            return [];
        }

        $vendors = $this->vendorPrefixesForModule($moduleName);
        $modulePart = $matches[2];
        $kebabs = array_values(array_unique(array_filter([
            $this->camelToKebab($modulePart),
            str_replace('_', '-', strtolower($modulePart)),
            strtolower($modulePart),
        ])));

        $candidates = [];

        foreach ($vendors as $vendor) {
            foreach ($kebabs as $kebab) {
                $candidates[] = "{$vendor}/module-{$kebab}";
                $candidates[] = "{$vendor}/{$kebab}";
                $candidates[] = "{$vendor}/magento2-{$kebab}";
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    public function vendorPrefixesForModule(string $moduleName): array
    {
        if (! preg_match('/^([^_]+)_/', $moduleName, $matches)) {
            return [];
        }

        $vendor = strtolower($matches[1]);

        return array_values(array_unique([
            $vendor,
            ...self::VENDOR_ALIASES[$vendor] ?? [],
        ]));
    }

    public function camelToKebab(string $value): string
    {
        $withHyphens = preg_replace('/([a-z])([A-Z])/', '$1-$2', $value) ?? $value;

        return strtolower(str_replace('_', '-', $withHyphens));
    }
}

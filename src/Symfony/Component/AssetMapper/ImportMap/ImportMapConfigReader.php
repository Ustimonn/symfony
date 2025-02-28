<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\ImportMap;

use Symfony\Component\AssetMapper\Exception\RuntimeException;
use Symfony\Component\VarExporter\VarExporter;

/**
 * Reads/Writes the importmap.php file and returns the list of entries.
 *
 * @author Ryan Weaver <ryan@symfonycasts.com>
 */
class ImportMapConfigReader
{
    private ImportMapEntries $rootImportMapEntries;

    public function __construct(private readonly string $importMapConfigPath)
    {
    }

    public function getEntries(): ImportMapEntries
    {
        if (isset($this->rootImportMapEntries)) {
            return $this->rootImportMapEntries;
        }

        $configPath = $this->importMapConfigPath;
        $importMapConfig = is_file($this->importMapConfigPath) ? (static fn () => include $configPath)() : [];

        $entries = new ImportMapEntries();
        foreach ($importMapConfig ?? [] as $importName => $data) {
            $validKeys = ['path', 'version', 'type', 'entrypoint', 'url'];
            if ($invalidKeys = array_diff(array_keys($data), $validKeys)) {
                throw new \InvalidArgumentException(sprintf('The following keys are not valid for the importmap entry "%s": "%s". Valid keys are: "%s".', $importName, implode('", "', $invalidKeys), implode('", "', $validKeys)));
            }

            // should solve itself when the config is written again
            if (isset($data['url'])) {
                trigger_deprecation('symfony/asset-mapper', '6.4', 'The "url" option is deprecated, use "version" instead.');
            }

            $type = isset($data['type']) ? ImportMapType::tryFrom($data['type']) : ImportMapType::JS;
            $isEntry = $data['entrypoint'] ?? false;

            if ($isEntry && ImportMapType::JS !== $type) {
                throw new RuntimeException(sprintf('The "entrypoint" option can only be used with the "js" type. Found "%s" in importmap.php for key "%s".', $importName, $type->value));
            }

            $path = $data['path'] ?? null;
            $version = $data['version'] ?? null;
            if (null === $version && ($data['url'] ?? null)) {
                // BC layer for 6.3->6.4
                $version = $this->extractVersionFromLegacyUrl($data['url']);
            }
            if (null === $version && null === $path) {
                throw new RuntimeException(sprintf('The importmap entry "%s" must have either a "path" or "version" option.', $importName));
            }
            if (null !== $version && null !== $path) {
                throw new RuntimeException(sprintf('The importmap entry "%s" cannot have both a "path" and "version" option.', $importName));
            }

            $entries->add(new ImportMapEntry(
                $importName,
                path: $path,
                version: $version,
                type: $type,
                isEntrypoint: $isEntry,
            ));
        }

        return $this->rootImportMapEntries = $entries;
    }

    public function writeEntries(ImportMapEntries $entries): void
    {
        $this->rootImportMapEntries = $entries;

        $importMapConfig = [];
        foreach ($entries as $entry) {
            $config = [];
            if ($entry->path) {
                $path = $entry->path;
                $config['path'] = $path;
            }
            if ($entry->version) {
                $config['version'] = $entry->version;
            }
            if (ImportMapType::JS !== $entry->type) {
                $config['type'] = $entry->type->value;
            }
            if ($entry->isEntrypoint) {
                $config['entrypoint'] = true;
            }
            $importMapConfig[$entry->importName] = $config;
        }

        $map = class_exists(VarExporter::class) ? VarExporter::export($importMapConfig) : var_export($importMapConfig, true);
        file_put_contents($this->importMapConfigPath, <<<EOF
        <?php

        /**
         * Returns the importmap for this application.
         *
         * - "path" is a path inside the asset mapper system. Use the
         *     "debug:asset-map" command to see the full list of paths.
         *
         * - "entrypoint" (JavaScript only) set to true for any module that will
         *     be used as an the "entrypoint" (and passed to the importmap() Twig function).
         *
         * The "importmap:require" command can be used to add new entries to this file.
         *
         * This file has been auto-generated by the importmap commands.
         */
        return $map;

        EOF);
    }

    public function getRootDirectory(): string
    {
        return \dirname($this->importMapConfigPath);
    }

    private function extractVersionFromLegacyUrl(string $url): ?string
    {
        // URL pattern https://ga.jspm.io/npm:bootstrap@5.3.2/dist/js/bootstrap.esm.js
        if (false === $lastAt = strrpos($url, '@')) {
            return null;
        }

        $nextSlash = strpos($url, '/', $lastAt);
        if (false === $nextSlash) {
            return null;
        }

        return substr($url, $lastAt + 1, $nextSlash - $lastAt - 1);
    }
}

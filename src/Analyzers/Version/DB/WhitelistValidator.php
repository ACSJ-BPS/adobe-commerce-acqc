<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\DB;

class WhitelistValidator
{
    public function __construct(private readonly string $projectRoot) {}

    public function validate(): array
    {
        $appCodeDir = $this->projectRoot . '/app/code';
        if (!is_dir($appCodeDir)) {
            return [];
        }

        $findings = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appCodeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'db_schema.xml') {
                continue;
            }

            $schemaPath = $file->getPathname();
            $moduleDir  = dirname($schemaPath);
            $vendorModule = $this->resolveModuleName($schemaPath);
            $whitelistPath = $moduleDir . '/db_schema_whitelist.json';

            if (!file_exists($whitelistPath)) {
                $findings[] = [
                    'type'             => 'db_schema_whitelist_missing',
                    'severity'         => 'error',
                    'file'             => $schemaPath,
                    'module'           => $vendorModule,
                    'message'          => "Module {$vendorModule} has db_schema.xml but no db_schema_whitelist.json. Upgrade will fail without whitelist.",
                    'remediation'      => "Run: bin/magento setup:db-declaration:generate-whitelist --module-name={$vendorModule}",
                    'effort_hours_min' => 1,
                    'effort_hours_max' => 2,
                ];
                continue;
            }

            // Both exist — check for staleness
            $staleFindings = $this->checkStaleness($schemaPath, $whitelistPath, $vendorModule);
            foreach ($staleFindings as $f) {
                $findings[] = $f;
            }
        }

        return $findings;
    }

    private function checkStaleness(string $schemaPath, string $whitelistPath, string $vendorModule): array
    {
        $xml = @simplexml_load_file($schemaPath);
        if (!$xml) {
            return [];
        }

        $whitelistContent = file_get_contents($whitelistPath);
        if ($whitelistContent === false) {
            return [];
        }

        $whitelist = json_decode($whitelistContent, true);
        if (!is_array($whitelist)) {
            return [];
        }

        $missing = 0;

        foreach ($xml->xpath('//table') as $table) {
            $tableName = (string)($table['name'] ?? '');
            if ($tableName === '') {
                continue;
            }

            if (!isset($whitelist[$tableName])) {
                $missing++;
                continue;
            }

            foreach ($table->xpath('.//column') as $col) {
                $colName = (string)($col['name'] ?? '');
                if ($colName === '' ) {
                    continue;
                }
                if (!isset($whitelist[$tableName]['column'][$colName])) {
                    $missing++;
                }
            }
        }

        if ($missing === 0) {
            return [];
        }

        $moduleUnderscored = str_replace('/', '_', $vendorModule);

        return [[
            'type'        => 'db_schema_whitelist_stale',
            'severity'    => 'warning',
            'file'        => $whitelistPath,
            'module'      => $vendorModule,
            'message'     => "db_schema_whitelist.json for {$vendorModule} is outdated. {$missing} tables/columns added to db_schema.xml are not in whitelist.",
            'remediation' => "Regenerate whitelist: bin/magento setup:db-declaration:generate-whitelist --module-name={$moduleUnderscored}",
        ]];
    }

    private function resolveModuleName(string $schemaPath): string
    {
        // Expect path like .../app/code/Vendor/Module/etc/db_schema.xml
        $parts = explode(DIRECTORY_SEPARATOR, $schemaPath);
        $etcIdx = array_search('etc', $parts);
        if ($etcIdx !== false && $etcIdx >= 2) {
            return $parts[$etcIdx - 2] . '/' . $parts[$etcIdx - 1];
        }
        return basename(dirname(dirname($schemaPath)));
    }
}

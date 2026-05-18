<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\DB;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\ParserFactory;

class LegacyScriptDetector
{
    private const LEGACY_INTERFACES = [
        'Magento\Framework\Setup\InstallSchemaInterface',
        'Magento\Framework\Setup\UpgradeSchemaInterface',
        'Magento\Framework\Setup\InstallDataInterface',
        'Magento\Framework\Setup\UpgradeDataInterface',
    ];

    private const PATH_PATTERNS = [
        'InstallSchema.php',
        'UpgradeSchema.php',
        'InstallData.php',
        'UpgradeData.php',
        'Uninstall.php',
    ];

    private const REMEDIATION = "1. Create etc/db_schema.xml with declarative schema. "
        . "2. Replace data scripts with Setup/Patch/Data classes implementing DataPatchInterface. "
        . "3. Generate db_schema_whitelist.json with: bin/magento setup:db-declaration:generate-whitelist --module-name=VendorModule. "
        . "See: https://developer.adobe.com/commerce/php/development/components/declarative-schema/";

    public function __construct(private readonly string $projectRoot) {}

    public function detect(): array
    {
        $appCodeDir = $this->projectRoot . '/app/code';
        if (!is_dir($appCodeDir)) {
            return [];
        }

        $findings = [];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appCodeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $filepath = $file->getPathname();

            // Fast pre-filter: only parse files matching known legacy setup paths
            $basename = $file->getFilename();
            if (!in_array($basename, self::PATH_PATTERNS, true)) {
                continue;
            }

            $content = file_get_contents($filepath);
            if ($content === false) {
                continue;
            }

            try {
                $ast = $parser->parse($content);
            } catch (Error) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $detectedClass = null;
            $detectedInterface = null;

            $traverser = new NodeTraverser();
            $visitor = new class ($detectedClass, $detectedInterface) extends NodeVisitorAbstract {
                public ?string $className = null;
                public ?string $interfaceName = null;

                private const TARGET = [
                    'Magento\Framework\Setup\InstallSchemaInterface',
                    'Magento\Framework\Setup\UpgradeSchemaInterface',
                    'Magento\Framework\Setup\InstallDataInterface',
                    'Magento\Framework\Setup\UpgradeDataInterface',
                ];

                public function __construct(mixed &$cls, mixed &$iface) {}

                public function enterNode(Node $node): null
                {
                    if (!$node instanceof Node\Stmt\Class_) {
                        return null;
                    }

                    if ($node->implements === []) {
                        return null;
                    }

                    $this->className = $node->name?->toString() ?? '';

                    foreach ($node->implements as $impl) {
                        $fqn = $impl->toString();
                        $shortName = ltrim($fqn, '\\');
                        if (in_array($shortName, self::TARGET, true)) {
                            $this->interfaceName = $shortName;
                            break;
                        }
                        // Also match short names (use statements resolved by parser keep full names)
                        foreach (self::TARGET as $target) {
                            if (str_ends_with($target, '\\' . $fqn) || $fqn === $target) {
                                $this->interfaceName = $target;
                                break 2;
                            }
                        }
                    }

                    return null;
                }
            };

            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            // Fall back to regex if AST visitor didn't resolve use-aliased names
            if ($visitor->interfaceName === null) {
                foreach (self::LEGACY_INTERFACES as $iface) {
                    $short = substr($iface, strrpos($iface, '\\') + 1);
                    if (preg_match('/implements\b[^{]*\b' . preg_quote($short, '/') . '\b/', $content)) {
                        $visitor->interfaceName = $iface;
                        if ($visitor->className === null) {
                            if (preg_match('/class\s+(\w+)/', $content, $m)) {
                                $visitor->className = $m[1];
                            }
                        }
                        break;
                    }
                }
            }

            if ($visitor->interfaceName !== null) {
                $className = $visitor->className ?? basename($filepath, '.php');
                $findings[] = [
                    'type'             => 'legacy_setup_script_detected',
                    'severity'         => 'error',
                    'file'             => $filepath,
                    'message'          => "Legacy setup script found: {$className} implements {$visitor->interfaceName}. Migrate to db_schema.xml + DataPatch.",
                    'remediation'      => self::REMEDIATION,
                    'effort_hours_min' => 16,
                    'effort_hours_max' => 80,
                ];
            }
        }

        return $findings;
    }
}

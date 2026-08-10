<?php

declare(strict_types=1);

namespace ACQC\Core\Platform;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads platform plugin manifests from config/platforms/*.yaml.
 *
 * This is the single extension seam for supporting new languages/apps: drop a
 * manifest in config/platforms/ and it is picked up automatically.
 */
class PlatformRegistry
{
    /** @var array<string,PlatformDefinition>|null */
    private ?array $platforms = null;

    public function __construct(private readonly string $acqcRoot)
    {
    }

    /**
     * @return array<string,PlatformDefinition> keyed by platform id
     */
    public function all(): array
    {
        if ($this->platforms !== null) {
            return $this->platforms;
        }

        $this->platforms = [];
        $dir = "{$this->acqcRoot}/config/platforms";
        if (!is_dir($dir)) {
            return $this->platforms;
        }

        $finder = new Finder();
        $finder->files()->name(['*.yaml', '*.yml'])->in($dir)->sortByName();

        foreach ($finder as $file) {
            try {
                $data = Yaml::parseFile($file->getRealPath());
            } catch (\Throwable) {
                continue;
            }

            $block = is_array($data['platform'] ?? null) ? $data['platform'] : null;
            if ($block === null || !isset($block['id'])) {
                continue;
            }

            $definition = PlatformDefinition::fromArray($block);
            $this->platforms[$definition->id] = $definition;
        }

        return $this->platforms;
    }

    public function get(string $id): ?PlatformDefinition
    {
        return $this->all()[$id] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

class RuleDefinition
{
    public function __construct(
        public readonly string  $schemaVersion,
        public readonly string  $id,
        public readonly string  $name,
        public readonly string  $title,
        public readonly string  $description,
        public readonly string  $category,
        public readonly string  $severity,
        public readonly int     $tier,
        public readonly array   $tags,
        public readonly array   $roles,
        public readonly array   $platforms,
        public readonly array   $globs,
        public readonly ?array  $check,
        public readonly ?string $recommendation,
        public readonly ?array  $examples,
        public readonly array   $references,
        public readonly string  $introducedIn,
        public readonly ?string $source,
        public readonly ?string $deprecatedIn,
        public readonly ?string $replacedBy,
        public readonly string  $layerPath,
        public readonly ?array  $suppression,
    ) {}

    public function isActive(): bool
    {
        return $this->suppression === null && $this->deprecatedIn === null;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecatedIn !== null;
    }

    public function appliesToRole(string $role): bool
    {
        $normalized = str_replace('-', '_', strtolower($role));
        return in_array($normalized, $this->roles, true);
    }

    public function appliesToFile(string $path): bool
    {
        if (empty($this->globs)) {
            return true;
        }

        $basename = basename($path);

        foreach ($this->globs as $glob) {
            if (fnmatch($glob, $path)) {
                return true;
            }
            if (fnmatch($glob, $basename)) {
                return true;
            }
            // Convert ** glob to regex for deep-path matching.
            // `**/` must match zero or more path segments (including none), so
            // `**/Resolver/**/*.php` matches both `Resolver/Foo.php` and
            // `GraphQl/Resolver/Product/Foo.php`.
            if (str_contains($glob, '**')) {
                $regex = '#' . str_replace(
                    ['\*\*/', '\*\*', '\*', '\?'],
                    ['(?:[^/]+/)*', '.*', '[^/]*', '.'],
                    preg_quote($glob, '#')
                ) . '$#';
                if (preg_match($regex, $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Return a copy with the suppression field populated. */
    public function withSuppression(array $suppression): self
    {
        return new self(
            schemaVersion:  $this->schemaVersion,
            id:             $this->id,
            name:           $this->name,
            title:          $this->title,
            description:    $this->description,
            category:       $this->category,
            severity:       $this->severity,
            tier:           $this->tier,
            tags:           $this->tags,
            roles:          $this->roles,
            platforms:      $this->platforms,
            globs:          $this->globs,
            check:          $this->check,
            recommendation: $this->recommendation,
            examples:       $this->examples,
            references:     $this->references,
            introducedIn:   $this->introducedIn,
            source:         $this->source,
            deprecatedIn:   $this->deprecatedIn,
            replacedBy:     $this->replacedBy,
            layerPath:      $this->layerPath,
            suppression:    $suppression,
        );
    }

    public static function fromArray(array $data, string $layerPath): self
    {
        return new self(
            schemaVersion:  (string) ($data['schema_version'] ?? '1.0'),
            id:             (string) ($data['id']             ?? ''),
            name:           (string) ($data['name']           ?? ''),
            title:          (string) ($data['title']          ?? ''),
            description:    (string) ($data['description']    ?? ''),
            category:       (string) ($data['category']       ?? ''),
            severity:       (string) ($data['severity']       ?? ''),
            tier:           (int)    ($data['tier']           ?? 1),
            tags:           (array)  ($data['tags']           ?? []),
            roles:          (array)  ($data['roles']          ?? []),
            platforms:      (array)  ($data['platforms']      ?? []),
            globs:          (array)  ($data['globs']          ?? []),
            check:          isset($data['check'])          ? (array)  $data['check']          : null,
            recommendation: isset($data['recommendation']) ? (string) $data['recommendation'] : null,
            examples:       isset($data['examples'])       ? (array)  $data['examples']       : null,
            references:     (array)  ($data['references']    ?? []),
            introducedIn:   (string) ($data['introduced_in'] ?? ''),
            source:         isset($data['source'])         ? (string) $data['source']         : null,
            deprecatedIn:   isset($data['deprecated_in'])  ? (string) $data['deprecated_in']  : null,
            replacedBy:     isset($data['replaced_by'])    ? (string) $data['replaced_by']    : null,
            layerPath:      $layerPath,
            suppression:    isset($data['suppression'])    ? (array)  $data['suppression']    : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

use ACQC\Core\Rules\Checkers\CheckerInterface;
use ACQC\Core\Rules\Checkers\GuidanceChecker;
use ACQC\Core\Rules\Checkers\RegexChecker;
use ACQC\Core\Rules\Checkers\XmlXpathChecker;

class CheckerRegistry
{
    /** @var array<string, CheckerInterface> */
    private array $checkers;

    public function __construct()
    {
        $this->checkers = [
            'xml_xpath' => new XmlXpathChecker(),
            'regex'     => new RegexChecker(),
            'guidance'  => new GuidanceChecker(),
        ];
    }

    public function getChecker(string $type): CheckerInterface
    {
        if (!isset($this->checkers[$type])) {
            // Unknown types fall back to guidance (no violations) to avoid crashes
            return new GuidanceChecker();
        }

        return $this->checkers[$type];
    }

    public function register(string $type, CheckerInterface $checker): void
    {
        $this->checkers[$type] = $checker;
    }

    public function has(string $type): bool
    {
        return isset($this->checkers[$type]);
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

class RuleSchema
{
    const FIELD_SCHEMA_VERSION = 'schema_version';
    const FIELD_ID             = 'id';
    const FIELD_NAME           = 'name';
    const FIELD_TITLE          = 'title';
    const FIELD_DESCRIPTION    = 'description';
    const FIELD_CATEGORY       = 'category';
    const FIELD_SEVERITY       = 'severity';
    const FIELD_TIER           = 'tier';
    const FIELD_ROLES          = 'roles';
    const FIELD_PLATFORMS      = 'platforms';
    const FIELD_INTRODUCED_IN  = 'introduced_in';
    const FIELD_TAGS           = 'tags';
    const FIELD_GLOBS          = 'globs';
    const FIELD_CHECK          = 'check';
    const FIELD_EXAMPLES       = 'examples';
    const FIELD_RECOMMENDATION = 'recommendation';
    const FIELD_REFERENCES     = 'references';
    const FIELD_SOURCE         = 'source';
    const FIELD_DEPRECATED_IN  = 'deprecated_in';
    const FIELD_REPLACED_BY    = 'replaced_by';
    const FIELD_SUPPRESSION    = 'suppression';

    const REQUIRED_FIELDS = [
        'schema_version',
        'id',
        'name',
        'title',
        'description',
        'category',
        'severity',
        'tier',
        'roles',
        'platforms',
        'introduced_in',
    ];

    const REQUIRED_FIELDS_TIER_1_2 = [
        'globs',
        'check',
        'examples',
        'recommendation',
    ];

    const CHECK_TYPES = [
        'php_ast',
        'xml_xpath',
        'regex',
        'json_path',
        'structural',
        'composite',
        'guidance',
    ];

    const SEVERITIES = [
        'blocker',
        'critical',
        'error',
        'warning',
        'info',
        'minor',
    ];

    const TIERS = [1, 2, 3];

    const ROLES = [
        'developer',
        'tech_lead',
        'architect',
        'executive',
        'ci',
    ];

    const PLATFORMS = [
        'cli',
        'cursor',
        'claude',
        'vscode',
        'ci',
        'sarif',
        'junit',
    ];

    const ID_PATTERN = '/^[A-Z]{2,5}_[A-Z]{2,8}_\d{3}$/';
}

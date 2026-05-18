<?php

return [
    /*
     * Absolute path to the acqc PHP binary.
     * Defaults to bin/acqc in the project root (one level above server/).
     */
    'bin' => env('ACQC_BIN', base_path('../bin/acqc')),

    /*
     * Max seconds before a CLI scan subprocess is killed.
     */
    'timeout_ms' => (int) env('ACQC_MCP_TIMEOUT_MS', 120000),

    /*
     * Optional base directory that all project local_paths must reside within.
     * Set ACQC_SCAN_BASE_PATH in .env to restrict scanning to a safe subtree
     * (e.g. /var/www/projects). When null, any existing directory is accepted.
     */
    'scan_base_path' => env('ACQC_SCAN_BASE_PATH'),
];

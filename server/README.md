# server

This folder holds a Laravel API (`/api/v1`) for a planned ACQC web dashboard. It stores projects,
scans, findings, suppressions and upgrade scans.

The ACQC command line does not need it. It is not documented or supported yet.

The only link from the CLI is `acqc upgrade:analyze --save --project-id=<id>`, which sends the
upgrade results to `$ACQC_API_URL/api/v1/projects/<id>/upgrade-scans`. That route needs a Sanctum
login token and the CLI does not send one, so the server rejects the request. See
[SECURITY.md](../SECURITY.md).

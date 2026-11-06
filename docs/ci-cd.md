# CI/CD

Use `acqc audit` as the quality gate in your pipeline. `report` and `upgrade:analyze` always exit `0`, so they cannot gate a build.

## Recommended gate

Audit the files changed on the branch with the `ci` profile:

```bash
php /opt/acqc/bin/acqc audit . --diff-from=origin/main --profile=ci --format=json --sarif-file=acqc.sarif
```

| Part | Why |
|---|---|
| `--diff-from=origin/main` | Checks only files changed against the base branch, so old issues in untouched files do not fail the build. Accepts a branch, a commit or a range such as `origin/main..HEAD`. Needs the base branch in the clone (full history, or fetch it). |
| `--profile=ci` | Blocks on `error`, `critical` and `blocker`. Skips personal rules, so the result is the same on every machine. |
| `--format=json` | Machine output on stdout. Other formats: `console`, `compact`, `html`, `sarif`. |
| `--sarif-file=acqc.sarif` | Also writes SARIF 2.1.0 for GitHub code scanning or a SARIF viewer. |
| `--fail-on=critical,blocker` | Optional. Overrides the profile `fail_on` list (exact severities, not a threshold). |

To check a whole folder instead of the changes, drop `--diff-from`: `acqc audit app/code --profile=ci`.

### Exit codes

| Code | Meaning | Pipeline action |
|---|---|---|
| `0` | Gate passed | Continue |
| `1` | Gate failed: issues with a `fail_on` severity were found | Fail the job |
| `2` | Tool error, for example an unknown git ref, or App Builder files changed and Node.js is missing. With `--format=json`, stdout holds `{"error": "...", "exit_code": 2}`. With `--format=sarif`, stdout is empty and the message goes to stderr. | Fail the job |

In CI a tool error fails the job. The local pre-commit hook allows the commit on a tool error unless `ACQC_STRICT=1`. See [Pre-commit gate](pre-commit.md).

## Install ACQC in the pipeline

ACQC runs from its own checkout, next to the project under test.

| Need | Notes |
|---|---|
| PHP 8.2 or later | With Composer, git and the extensions Composer asks for (including `gd`, `zip`, `mbstring`, `dom`, `xml`). |
| Read access to the ACQC repository | `https://github.com/OneAdobe/adobe-commerce-acqc.git`. Store the access token as a CI secret. If the organization uses SAML SSO, authorize the token for it. |
| Node.js 20.19+ | Only for App Builder / Node.js projects. Run `npm install` in the ACQC folder to build abqc. See [App Builder](app-builder.md). |

```bash
git clone --depth 1 https://github.com/OneAdobe/adobe-commerce-acqc.git /opt/acqc
composer install --no-interaction --prefer-dist --working-dir=/opt/acqc
```

## GitHub Actions

This workflow publishes the findings as code scanning alerts and still fails the job when the gate fails. It needs `security-events: write` and full git history.

```yaml
name: ACQC

on:
  pull_request:

permissions:
  contents: read
  security-events: write

jobs:
  acqc:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Install ACQC
        env:
          GH_TOKEN: ${{ secrets.ACQC_REPO_TOKEN }}
        run: |
          git clone --depth 1 "https://x-access-token:${GH_TOKEN}@github.com/OneAdobe/adobe-commerce-acqc.git" /opt/acqc
          composer install --no-interaction --prefer-dist --working-dir=/opt/acqc

      - name: ACQC audit
        id: acqc
        run: |
          set +e
          php /opt/acqc/bin/acqc audit . --diff-from="origin/${{ github.base_ref }}" --profile=ci --format=sarif > acqc.sarif
          echo "exit=$?" >> "$GITHUB_OUTPUT"

      # Exit 0 or 1 means acqc.sarif is valid. Exit 2 means it is empty, so skip the upload.
      - name: Upload SARIF
        if: always() && (steps.acqc.outputs.exit == '0' || steps.acqc.outputs.exit == '1')
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: acqc.sarif
          category: acqc

      - name: Enforce the ACQC gate
        if: always() && steps.acqc.outputs.exit != '0'
        run: |
          case "${{ steps.acqc.outputs.exit }}" in
            1) echo "::error::ACQC quality gate failed. See the code scanning alerts on this PR." ;;
            *) echo "::error::ACQC could not run (exit ${{ steps.acqc.outputs.exit }}). Check the audit step log." ;;
          esac
          exit 1
```

`ACQC_REPO_TOKEN` is a secret you create with read access to the ACQC repository. For App Builder projects, add `actions/setup-node` and run `cd /opt/acqc && npm install` after the Composer step.

## GitLab CI

Runs in merge request pipelines. Use an image with PHP 8.2+, Composer and git.

```yaml
acqc:
  stage: test
  image: <your PHP 8.2+ image with Composer and git>
  variables:
    GIT_DEPTH: 0
  rules:
    - if: $CI_PIPELINE_SOURCE == "merge_request_event"
  script:
    - git clone --depth 1 "https://x-access-token:${ACQC_REPO_TOKEN}@github.com/OneAdobe/adobe-commerce-acqc.git" /opt/acqc
    - composer install --no-interaction --prefer-dist --working-dir=/opt/acqc
    - git fetch origin "+refs/heads/$CI_MERGE_REQUEST_TARGET_BRANCH_NAME:refs/remotes/origin/$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
    - php /opt/acqc/bin/acqc audit . --diff-from="origin/$CI_MERGE_REQUEST_TARGET_BRANCH_NAME" --profile=ci --format=json --sarif-file=acqc.sarif > acqc.json
  artifacts:
    when: always
    paths:
      - acqc.json
      - acqc.sarif
```

## Bitbucket Pipelines

Runs on pull requests. Use an image with PHP 8.2+, Composer and git.

```yaml
pipelines:
  pull-requests:
    '**':
      - step:
          name: ACQC
          image: <your PHP 8.2+ image with Composer and git>
          clone:
            depth: full
          script:
            - git clone --depth 1 "https://x-access-token:${ACQC_REPO_TOKEN}@github.com/OneAdobe/adobe-commerce-acqc.git" /opt/acqc
            - composer install --no-interaction --prefer-dist --working-dir=/opt/acqc
            - git fetch origin "+refs/heads/$BITBUCKET_PR_DESTINATION_BRANCH:refs/remotes/origin/$BITBUCKET_PR_DESTINATION_BRANCH"
            - php /opt/acqc/bin/acqc audit . --diff-from="origin/$BITBUCKET_PR_DESTINATION_BRANCH" --profile=ci --format=json --sarif-file=acqc.sarif > acqc.json
          artifacts:
            - acqc.json
            - acqc.sarif
```

In GitLab and Bitbucket, `ACQC_REPO_TOKEN` is a masked CI variable you create. The explicit `git fetch` makes `origin/<target branch>` available for `--diff-from`.

## SARIF output

`--format=sarif` writes SARIF to stdout. `--sarif-file=<file>` writes it to a file next to any other format.

| Property | Value |
|---|---|
| Paths | Relative to the project root (`SRCROOT`), also for `--staged` |
| Fingerprint | `partialFingerprints["acqcFingerprint/v1"]`: a hash of the rule, the path and the text of the flagged line. An alert keeps its identity when code above it moves. |
| Levels | `blocker`, `critical`, `error` -> `error`; `warning` -> `warning`; `info`, `minor` -> `note` |
| Security rules | Also get `security-severity` (for example `error` = 7.5) for GitHub severity buckets |
| Content | Paths, line numbers and messages. No source code. |

## Other commands in a pipeline

| Command | Use in CI | Notes |
|---|---|---|
| `acqc report` | Build an HTML, JSON or Excel report as an artifact | Always exits `0`. Not a gate. See [Reports](reports.md). |
| `acqc upgrade:analyze` | Upgrade readiness as an artifact | Exits `0` even with critical issues. Not a gate. See [Upgrade analysis](upgrade.md). |
| `acqc appbuilder:lint` | App Builder-only gate | Exits `1` on error findings. See [App Builder](app-builder.md). |

### `acqc init --ci`

`acqc init --ci=github|gitlab|bitbucket` writes a starter file (`.github/workflows/acqc.yml`, `.gitlab-ci-acqc.yml` or `bitbucket-pipelines-acqc.yml`). The starter runs `php bin/acqc scan . --profile=ci --format=json` from the project root. Before you use it:

- change `php bin/acqc` to the path of your ACQC checkout;
- replace `scan` with the `audit` command above. `scan --format=json` prints a console banner before the JSON, so the output cannot be parsed.

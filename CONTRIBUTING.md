# Contributing to ACQC

This page explains how to set up ACQC for development, run the tests and send a change.

## Set up

You need PHP 8.2 or later, Composer 2 and Git. Node.js 20.19+ and npm are needed only to work on
the App Builder checks.

```bash
git clone https://github.com/OneAdobe/adobe-commerce-acqc.git acqc
cd acqc
composer install
npm install            # only for App Builder work: builds the bundled abqc engine
php bin/acqc --version
```

## Repository layout

| Path | Contents |
|---|---|
| `bin/acqc` | CLI entry point |
| `src/` | PHP source, namespace `ACQC\`: commands, analyzers, rule engine, report generators |
| `config/rules/` | Bundled YAML rules (`adobe-commerce/`, `global/`) |
| `config/profiles/` | Built-in profiles (`developer`, `ci`, `pre-commit` and others) |
| `config/scoring.yaml` | Default scoring |
| `config/platforms/`, `config/versions/` | Platform detection and Adobe Commerce version data |
| `templates/` | Report templates and the git hook template |
| `tests/` | PHPUnit tests, namespace `ACQC\Tests\` |
| `abqc/` | Bundled App Builder and Node.js engine. See [abqc/README.md](abqc/README.md). |
| `docs/` | User documentation |

## Run the tests

```bash
composer test                                  # full PHPUnit suite
vendor/bin/phpunit tests/Unit                  # one folder
vendor/bin/phpunit --filter=ScoreCalculator    # tests whose name matches
php -l src/Path/To/File.php                    # syntax check of one file
```

Use `composer test` or `vendor/bin/phpunit`. The other `test:*` scripts in `composer.json` (for
example `test:unit`) name test suites that `phpunit.xml.dist` does not define, so they do not run
any tests.

A timing check in `tests/Unit/Analyzers/PHP/PHPCSAnalyzerTest.php` expects the analysis to take
less than 30 seconds, so it can fail on a slow or busy machine.

The abqc engine has its own tests: `cd abqc && npm test`.

## Add or change a bundled rule

1. Create a scaffold. The file goes to `config/rules/adobe-commerce/<category>/` for an `AC_` id,
   `config/rules/global/frontend/<category>/` for an `FE_` id, and `config/rules/global/<category>/`
   for any other prefix:

   ```bash
   php bin/acqc rules:new --id=AC_DI_010 --category=di --tier=1 --check-type=regex
   ```

2. Replace every `TODO` in the file. The fields are described in
   [docs/rules.md](docs/rules.md#rule-fields). Use a check type that reports findings: `regex` or `xml_xpath`
   (see [What a rule can check](docs/rules.md#what-a-rule-can-check)).
3. Validate the rules:

   ```bash
   php bin/acqc rules:validate
   ```

4. Run the rule against code that should and should not match, for example the fixtures in
   `tests/Fixtures/`:

   ```bash
   php bin/acqc audit tests/Fixtures/MagentoCode/BadModule --format=json
   ```

5. Add or update a test in `tests/`.

`audit` caches the rule set for up to one hour. If a new rule does not show up, clear the cache as
described in [docs/rules.md](docs/rules.md#suppress-a-rule).

## Documentation

- When you add or change a command, an option or a default, update the page in `docs/` that
  covers it. `acqc help <command>` is the reference for options.
- Run every command you add to the docs and check the output.
- Say "Adobe Commerce" in text. Keep code names such as `Magento2` and `bin/magento` as they are.

## Send a change

1. Create a branch from `main`.
2. Make the change, with tests.
3. Run `composer test`.
4. Add a line under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
5. Open a pull request against `main` in `OneAdobe/adobe-commerce-acqc`. Describe what changed,
   why, and how you tested it.

## Security issues

Do not report vulnerabilities in a public issue. See [SECURITY.md](SECURITY.md).

## Code of conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

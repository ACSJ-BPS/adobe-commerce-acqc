# Security

## Report a vulnerability

Do not report a vulnerability in a public issue or pull request. Send an email with:

- what the problem is and what an attacker could do with it;
- the steps or a sample project to reproduce it;
- the ACQC version (`acqc --version`) and the PHP version (`php -v`).

Fixes are made on the `main` branch and listed in [CHANGELOG.md](CHANGELOG.md). The current
version is 3.1.0.

## What ACQC runs, reads and sends

ACQC reads the code you point it at. Most commands work offline. This table lists the cases where ACQC
runs code from the project, uses the network or reads the machine it runs on.

| Command | What happens | How to avoid it |
|---|---|---|
| `report` on an Adobe Commerce project | Runs `app/etc/env.php` with PHP `include`, sends HTTP requests to the store base URL (`performance_http_protocol` does not verify the TLS certificate) and reads the OPcache settings and load average of the machine. Details in [Reports](docs/reports.md#what-report-reads-besides-your-code). | Turn the analyzers off in `.acqc/project.yaml`, or run `report` only on code you trust. `audit`, `analyze` and `scan` do not do this. |
| `audit`, `report`, `appbuilder:lint` on an App Builder or Node.js project | Run the bundled abqc engine with Node.js. abqc inherits your environment. If `ABQC_PROVIDER` is set (for example to `anthropic` or `openai`), abqc sends the content of the reviewed files to that AI provider. | Keep `ABQC_PROVIDER` unset. ACQC does not need it. |
| `version:sync` | Downloads version data from repo.magento.com (default) or Packagist (`--source=packagist`). repo.magento.com needs `ACQC_MAGENTO_PUBLIC_KEY` and `ACQC_MAGENTO_PRIVATE_KEY`, or `~/.composer/auth.json`. | Do not run it. The bundled data in `config/versions/` is used instead. |
| `version:download` | Runs Composer to download Adobe Commerce from repo.magento.com (or `--repository-url`) into `var/vanilla/<version>/`. | Do not run it. |
| `upgrade:analyze --simulate` | Downloads version data from Packagist and updates `config/versions/adobe-commerce/`. Offline, it continues with the bundled data. | Run without `--simulate`. |
| `upgrade:analyze --composer-deep` | Requests package data from repo.packagist.org, for packages not already in its cache, to check whether they are abandoned. | Leave `--composer-deep` off (the default). |
| `upgrade:analyze --upgrade-recommendations` | When `ACQC_IMS_TOKEN` is set, sends each finding's type, the versions, the file path and up to 500 characters of code to `ACQC_FLUFFYJAWS_URL`. Without the token, it uses built-in text and sends nothing. | Leave the option off, or keep `ACQC_IMS_TOKEN` unset. |
| `upgrade:analyze --save --project-id=<id>` | Sends the score and all findings to `$ACQC_API_URL/api/v1/projects/<id>/upgrade-scans`. Without `ACQC_API_URL` it only prints a warning. | Leave `--save` off (the default). |

## Handle reports with care

Reports and `audit` output contain file paths, line numbers and the weak spots found in the project.
Treat them like source code: do not attach them to public issues or share them outside the team.

## Secrets

Never commit Composer keys, `ACQC_IMS_TOKEN` or other tokens. Set them as environment variables or
CI secrets.

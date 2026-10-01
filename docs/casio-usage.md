# Casio Usage Guidelines — `phpcs:report`

Project-specific guide for running ACQC's **PHPCS-only rich HTML report** against the
Casio Commerce codebases (e.g. `casio-uk`). This covers the exact setup, commands, and
conventions the Casio team should use.

> TL;DR
> ```bash
> # from inside the project's PHP 8.3 container, with ACQC available at /acqc
> php /acqc/bin/acqc phpcs:report \
>   vendor/casio vendor/casio-common vendor/casio-uk vendor/casio-us \
>   --severity=8 --project-name="Casio UK — vendor/casio*" \
>   --output=reports/casio-phpcs.html
> ```

---

## 1. Why a specific guide for Casio

- The Casio custom code ships as Composer packages under `vendor/casio*`
  (`vendor/casio`, `vendor/casio-common`, `vendor/casio-uk`, `vendor/casio-us`),
  **not** under `app/code`. Point the scanner at those directories.
- ACQC requires **PHP 8.1–8.5** (8.3 recommended). Casio projects run **Warden**, which
  already provides a PHP 8.3 container — use it instead of the host PHP.
- ACQC bundles the Magento 2 coding standard (v41) and its own `phpcs` binary, so you do
  **not** need `phpcs` on your `PATH`, and you do **not** need to touch the project's own
  `vendor/bin/phpcs`.

---

## 2. Prerequisites

1. A local checkout of this tool (the ACSJ-BPS copy):
   ```bash
   git clone https://github.com/ACSJ-BPS/adobe-commerce-acqc.git
   ```
2. Dependencies installed **with PHP 8.3** (auto-registers the `Magento2` standard):
   ```bash
   docker run --rm -v "$PWD/adobe-commerce-acqc:/acqc" -w /acqc \
     wardenenv/php-fpm:8.3-magento2 composer install --no-interaction
   ```
   > Installing with the host PHP (often 8.2 on macOS) fails because the locked
   > dependencies require PHP ≥ 8.3.

---

## 3. Running against a Casio project

The simplest approach is to mount the ACQC checkout into the Casio project's running
Warden PHP container and run `bin/acqc` there. From the Casio project root
(e.g. `~/Work/Projects/casio-uk`):

```bash
# Identify the project's php-fpm container (Warden names it <project>-php-fpm-1)
docker ps --format '{{.Names}}' | grep php-fpm

# Run the report inside that container, mounting the ACQC checkout at /acqc
docker run --rm \
  -v /ABSOLUTE/PATH/TO/adobe-commerce-acqc:/acqc \
  -v "$PWD:/project" -w /project \
  wardenenv/php-fpm:8.3-magento2 \
  php /acqc/bin/acqc phpcs:report \
    vendor/casio vendor/casio-common vendor/casio-uk vendor/casio-us \
    --severity=8 \
    --project-name="Casio UK — vendor/casio*" \
    --output=reports/casio-phpcs.html
```

The report is written to `reports/casio-phpcs.html` **inside the Casio project**, so it
sits alongside the project's other reports and is easy to open in a browser.

---

## 4. Recommended invocations

### Full custom-code audit (all Casio packages)

```bash
php /acqc/bin/acqc phpcs:report \
  vendor/casio vendor/casio-common vendor/casio-uk vendor/casio-us \
  --severity=8 --project-name="Casio UK — vendor/casio*" \
  --output=reports/casio-phpcs-sev8.html
```

- **severity 8** = high-severity coding-standard issues only (discouraged functions,
  direct throws, static functions, security sniffs). Good for a focused, low-noise audit.

### Fuller review (more issues surfaced)

```bash
php /acqc/bin/acqc phpcs:report \
  vendor/casio vendor/casio-common vendor/casio-uk vendor/casio-us \
  --severity=5 --project-name="Casio UK — vendor/casio*" \
  --output=reports/casio-phpcs-sev5.html
```

- **severity 5** surfaces additional annotation/PHPDoc and formatting findings.

### A single package

```bash
php /acqc/bin/acqc phpcs:report vendor/casio-uk --severity=5
# → reports/casio-uk-phpcs-report.html (auto-named)
```

---

## 5. Severity levels (Casio convention)

| `--severity` | Use it for                                   | Typical volume |
| -------------- | -------------------------------------------- | -------------- |
| `8`          | Pre-release audit — high-severity issues only | Low            |
| `5`          | Regular review — standards + annotations      | Medium         |
| `1`          | Everything PHPCS can report                   | High           |

> ACQC maps PHPCS severities onto its own scale: PHPCS severity ≥ 7 **warnings** show as
> **errors**, and PHPCS severity ≥ 7 **errors** show as **critical**. This is why a
> severity-8 run can show "1 critical / 14 errors" rather than raw PHPCS numbers.

---

## 6. Reading the report

The report opens with:

- **Readiness badge** — `READY TO SHIP` / `RISKY` / `BLOCKED`. Any **critical** makes it
  at least `RISKY`.
- **8 score rings** — Overall, Reliability, Security, Performance, Maintainability,
  Compliance, API Quality, Code Quality. PHPCS coding-standard findings land in
  **Maintainability** and **Code Quality**; the other dimensions stay high because PHPCS
  does not probe them.
- **Violations table** — each finding with its file, module (Casio package), line, rule
  id (e.g. `Magento2.Functions.DiscouragedFunction.*`) and message.

> The overall **score** uses a penalty-per-1000-violations formula, so a small number of
> findings across thousands of files still rounds near 100 even while the readiness badge
> flags `RISKY`. Treat the **readiness badge and the critical/error counts** as the gate,
> not the headline score.

---

## 7. Notes & gotchas

- **Only PHPCS runs** — this command intentionally skips ACQC's other analyzers
  (security/performance/orchestrator). For a full multi-analyzer audit use `acqc report`.
- **Standard version** — ACQC bundles `magento/magento-coding-standard` **v41**. If the
  Casio project pins a different version in its own `composer.json`, results can differ
  slightly from running the project's own `vendor/bin/phpcs`.
- **`reports/` directory** — created automatically if missing.

#!/bin/sh
# >>> ACQC pre-commit hook — managed by `acqc hooks:install`. Do not edit; re-run install instead. >>>
# acqc-hook-version: 1
#
# Blocks the commit when STAGED files contain issues with a failing severity
# (profile "pre-commit": blocker, critical, error).
#
# Environment overrides:
#   ACQC_SKIP=1      skip the check for this commit (same effect as git commit --no-verify for ACQC only)
#   ACQC_STRICT=1    also block when ACQC itself fails (exit 2 / not found) instead of warning
#   ACQC_BIN=path    use a different acqc binary
#   ACQC_PHP=path    use a different PHP binary
#   ACQC_PROFILE=x   use a different profile (default: __ACQC_PROFILE__)
#   ACQC_FAIL_ON=a,b override failing severities (e.g. critical)

ACQC_INSTALLED_BIN='__ACQC_BIN__'
ACQC_INSTALLED_PHP='__ACQC_PHP__'
# Installed with --strict => fail closed by default (ACQC_STRICT=0 still overrides).
ACQC_STRICT="${ACQC_STRICT:-__ACQC_STRICT__}"

hook_dir=$(dirname "$0")

# 1. Chain a pre-existing hook that was preserved at install time.
if [ -x "$hook_dir/pre-commit.local" ]; then
    "$hook_dir/pre-commit.local" "$@" || exit $?
fi

if [ "${ACQC_SKIP:-0}" = "1" ]; then
    echo "ACQC: pre-commit check skipped (ACQC_SKIP=1)." >&2
    exit 0
fi

acqc_tool_problem() {
    # $1 = message
    if [ "${ACQC_STRICT:-0}" = "1" ]; then
        echo "ACQC: $1 — commit blocked (ACQC_STRICT=1)." >&2
        exit 1
    fi
    echo "ACQC WARNING: $1 — commit allowed without quality check." >&2
    exit 0
}

repo_root=$(git rev-parse --show-toplevel 2>/dev/null) || acqc_tool_problem "not inside a git work tree"

# 2. Locate ACQC: $ACQC_BIN > <repo>/vendor/bin/acqc > path recorded at install.
if [ -n "${ACQC_BIN:-}" ]; then
    acqc_bin=$ACQC_BIN
elif [ -f "$repo_root/vendor/bin/acqc" ]; then
    acqc_bin="$repo_root/vendor/bin/acqc"
else
    acqc_bin=$ACQC_INSTALLED_BIN
fi
[ -f "$acqc_bin" ] || acqc_tool_problem "acqc not found at '$acqc_bin' (set ACQC_BIN or re-run acqc hooks:install)"

acqc_php=${ACQC_PHP:-$ACQC_INSTALLED_PHP}
command -v "$acqc_php" >/dev/null 2>&1 || acqc_php=php
command -v "$acqc_php" >/dev/null 2>&1 || acqc_tool_problem "PHP not found (set ACQC_PHP)"

# 3. Run the audit on the staged snapshot. stdin is closed so nothing can prompt.
if [ -n "${ACQC_FAIL_ON:-}" ]; then
    "$acqc_php" "$acqc_bin" audit "$repo_root" --staged --profile="${ACQC_PROFILE:-__ACQC_PROFILE__}" --format=compact --fail-on="$ACQC_FAIL_ON" </dev/null
else
    "$acqc_php" "$acqc_bin" audit "$repo_root" --staged --profile="${ACQC_PROFILE:-__ACQC_PROFILE__}" --format=compact </dev/null
fi
status=$?

case $status in
    0) exit 0 ;;
    1) exit 1 ;;
    *) acqc_tool_problem "acqc exited with status $status" ;;
esac
# <<< ACQC pre-commit hook <<<

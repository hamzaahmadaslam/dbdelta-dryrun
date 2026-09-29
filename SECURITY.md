# Security policy

## Reporting a vulnerability

Please report security problems privately through GitHub: open the repository's **Security** tab and choose
**Report a vulnerability**. Do not open a public issue for a security problem.

You will get a reply within seven days. Fixes are released as a new version with a note in the changelog.

## What this project does with your data

- It runs inside WP-CLI against the database of the WordPress install you run it on. It creates, alters and drops
  only its own scratch tables (a prefix starting with `dbdd` unless you choose another), refuses to reuse a table
  that already exists, and refuses to run where the environment type is "production" unless you pass
  `--allow-production`.
- It uses no API key and makes no network requests: no telemetry, no update checks.

## Supported versions

Only the latest release receives fixes.

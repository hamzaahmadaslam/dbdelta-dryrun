# dbdelta-dryrun

A WP-CLI command that finds the `CREATE TABLE` statements `dbDelta()` never stops altering, and says why.

Many plugins call `dbDelta()` on every request, or on every version check, trusting it to change nothing once the
table matches. It compares each column line of the SQL with what the database reports, and when the two are
written differently it runs an `ALTER TABLE` to "fix" the column. If the server then reports the column the same
way as before, the next call runs the same `ALTER TABLE` again: a schema change, with its metadata lock, on every
call. WordPress.org support threads describe it with MariaDB integer widths and upper-case SQL, one of them with
periodic deadlocks.

dbdelta-dryrun creates the tables under a scratch prefix, runs `dbDelta()` against them with every write blocked,
lets it apply its changes once, asks again, and drops the scratch tables. Each statement that is still there is
reported with the column, the cause and the fix. It needs a database, because the answer depends on the server:
the same SQL converges on MySQL 8.4 and loops on MariaDB.

## What it reports

| Cause                  | What happens                                                                                   | Fix                                         |
| ---------------------- | ---------------------------------------------------------------------------------------------- | ------------------------------------------- |
| `int-display-width`    | `int unsigned` in the SQL, `int(10) unsigned` from the server. WordPress ignores the width on MySQL 8.0.17 and later, not on MariaDB | Write the width: `int(10) unsigned`         |
| `enum-case`            | `dbDelta()` lower-cases the SQL's type, not the server's, so `enum('Open','Closed')` never matches | Lower-case values, or keep the column out    |
| `space-in-type`        | The type is read up to the first space: `decimal(10, 2)` becomes `decimal(10,`                 | `decimal(10,2)`                             |
| `type-alias`           | `boolean` is stored as `tinyint(1)`, `integer` as `int`, `json` as `longtext` on MariaDB        | Write the type the server reports           |
| `default-format`       | `DEFAULT '0'` on a `decimal(5,2)` is stored as `0.00`, and defaults are compared as text        | `DEFAULT '0.00'`                            |
| `default-quoted`, `default-mismatch` | The server reports the default differently from the SQL                           | Write it as stored                          |
| `two-columns-one-line` | `lat float, lng float` on one line: the type is read as `float,`                              | One column per line                         |
| `not-a-column`         | A `CONSTRAINT` or `FOREIGN KEY` line is read as a column, added and failed on every call        | Add constraints once, outside `dbDelta()`   |
| `index-syntax`         | `KEY email (email) USING BTREE`: anything after the column list stops the index being parsed   | Nothing after the closing parenthesis       |
| `index-unnamed`, `index-column-case` | An index without a name, or a column named in another case                        | Name every index; match the column's case   |
| `if-not-exists`        | `CREATE TABLE IF NOT EXISTS x` is read as a table called `IF`, so the columns are never compared and the CREATE runs every time | Plain `CREATE TABLE`                         |
| `type-mismatch`, `column-missing`, `index-mismatch` | Anything else, with what the SQL says and what the server reports          | Shown in the report                         |

A statement that disappears after `dbDelta()` has applied it once is listed as "stops after one run": harmless on a
site that runs it once, and reported so the SQL can match what the site already has. Exit code 1 means a statement
runs on every call, a table could not be created, or (with `--strict`) anything at all was reported.

## Install

```sh
wp package install https://github.com/hamzaahmadaslam/dbdelta-dryrun.git
```

Or without installing, from a checkout: `wp --require=path/to/dbdelta-dryrun/command.php dbdelta-dryrun check …`.
PHP 7.4 or later, WP-CLI, and a WordPress install on the database server you want to test against. No other
dependencies.

## Usage

```sh
# A schema file. Start table names with {prefix}; write {charset_collate} where the plugin appends
# $wpdb->get_charset_collate().
wp dbdelta-dryrun check schema.sql

# The SQL a plugin passes to dbDelta(): calls the function with dbDelta() stopped before it runs anything.
wp dbdelta-dryrun check --from='Acme\Install::create_tables'
```

| Option               | Meaning                                                                            |
| -------------------- | ---------------------------------------------------------------------------------- |
| `--from=<callable>`  | Check what this function or static method passes to `dbDelta()`, instead of files. Other writes the function makes still happen |
| `--prefix=<prefix>`  | The scratch prefix. Default `dbdd`, six random characters and an underscore        |
| `--format=json`      | Print the report as JSON                                                           |
| `--strict`           | Also exit 1 on statements that stop after one run                                  |
| `--keep`             | Leave the scratch tables in place to inspect them                                  |
| `--allow-production` | Run where `wp_get_environment_type()` is `production`                              |

A table name that starts with the site's own table prefix is moved to the scratch prefix too, so SQL copied from a
plugin works as it is.

## What it does to your database

It creates tables named with the scratch prefix, lets `dbDelta()` alter them once, and drops them before it exits,
also when something fails (`--keep` leaves them). It refuses to start if a scratch table already exists, so it never
changes a table it did not create. Every other statement `dbDelta()` would run during the check is blocked through
WordPress's `query` filter. It refuses to run where the environment type is `production` (which is WordPress's
default when nothing sets it), because even scratch tables do not belong in a live database: use a development or
staging copy on the same database server version, or set `WP_ENVIRONMENT_TYPE`.

It makes no network requests and uses no API key.

## Example

`tests/fixtures/pitfalls.sql` holds nine small synthetic tables, each written with one of the mistakes above. On
MariaDB 11.8.6 with WordPress 7.1.2:

```text
dbdelta-dryrun: 9 tables from tests/fixtures/pitfalls.sql, MariaDB 11.8.6, WordPress 7.1.2
10 statements dbDelta() runs on every call, 0 that stop after one run, 0 tables not created.

Runs on every call (10)
  {prefix}acme_default.rate  [default-format]
    ALTER TABLE {prefix}acme_default ALTER COLUMN `rate` SET DEFAULT '0'
    The SQL gives DEFAULT '0' and the database reports '0.00'. The values are equal as numbers, but dbDelta() compares them as text.
    Fix: Write the default as the database stores it: DEFAULT '0.00'.
  {prefix}acme_width.id  [int-display-width]
    ALTER TABLE {prefix}acme_width CHANGE COLUMN `id` id int unsigned NOT NULL AUTO_INCREMENT
    The SQL gives the type as "int unsigned" and the database reports "int(10) unsigned". Only the display width differs. WordPress ignores that difference on MySQL 8.0.17 and later, but not on MariaDB 11.8.6.
    Fix: Write the width the server reports, "int(10) unsigned": MySQL 8.0.17 and later ignore it, and it matches MariaDB.
  …
```

The full output is in `examples/`. On MySQL 8.4 the same file gives seven statements: the two integer widths and the
`json` column converge there. The integration tests check both counts on every push, against MySQL 8.4, MariaDB
10.11 and MariaDB 11.8.

## In CI

Run it in the job that already starts a database for your plugin's tests:

```yaml
- name: dbDelta converges
  env:
    WP_ENVIRONMENT_TYPE: development
  run: |
    wp package install https://github.com/hamzaahmadaslam/dbdelta-dryrun.git
    wp --path="$WP_PATH" dbdelta-dryrun check --from='Acme\Install::create_tables'
```

Run it once per database server your plugin supports: the results differ between MySQL and MariaDB.

## Limits

- It checks `CREATE TABLE` statements only. `INSERT` and `UPDATE` statements passed to `dbDelta()` run on every call
  by design; they are listed as not checked.
- The causes are read from the statement `dbDelta()` produced and the column the server reported. A cause it cannot
  name is still reported, with both sides shown.
- `--from` runs the plugin's function. `dbDelta()` is stopped, but anything else the function writes (options,
  capabilities, rows) is written, which is one more reason to use a scratch site.
- It tells you what the SQL should say. Changing the plugin's SQL is up to you.

## Tests

`php tests/run.php` runs the unit tests (no database). `WP_PATH=/path/to/wordpress bash tests/integration.sh` runs
the integration tests against the database of that install.

## License

MIT. Made by [Hamza Ahmad Aslam](https://hamzaahmadaslam.com), WordPress and web performance engineer.

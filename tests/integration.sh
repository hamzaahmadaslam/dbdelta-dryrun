#!/usr/bin/env bash
# Integration test against a real database server. Needs PHP with mysqli, WP-CLI, and WP_PATH pointing at a
# WordPress install whose database may hold scratch tables for a moment.
#   WP_PATH=/path/to/wordpress tests/integration.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
root="$(dirname "$here")"
: "${WP_PATH:?Set WP_PATH to a WordPress install}"
export WP_ENVIRONMENT_TYPE=development
extra=()
if [ "$(id -u)" = "0" ]; then extra+=(--allow-root); fi

run() { wp --path="$WP_PATH" "${extra[@]}" --require="$root/command.php" "$@"; }
pass=0
fail=0
check() {
  if [ "$2" = "$3" ]; then
    pass=$((pass + 1)); echo "ok - $1"
  else
    fail=$((fail + 1)); echo "not ok - $1"; echo "  expected: $2"; echo "  actual:   $3"
  fi
}
# Reads a JSON report on stdin and prints "status:cause:target" lines, sorted.
causes() { php -r '$r = json_decode(stream_get_contents(STDIN), true); $o = array(); foreach ($r["results"] as $x) { $o[] = $x["status"] . ":" . $x["cause"] . ":" . $x["target"]; } sort($o); echo implode(",", $o);'; }

server="$(wp --path="$WP_PATH" "${extra[@]}" eval 'global $wpdb; echo $wpdb->db_server_info();')"
if echo "$server" | grep -qi mariadb; then engine=mariadb; else engine=mysql; fi
echo "# server: $server ($engine)"

set +e
out="$(run dbdelta-dryrun check "$here/fixtures/converges.sql" --format=json)"; code=$?
set -e
check "a schema written for dbDelta() converges (exit code)" 0 "$code"
check "a schema written for dbDelta() converges (no statements)" "" "$(echo "$out" | causes)"

common=(
  "loops:default-format:{prefix}acme_default.rate"
  "loops:enum-case:{prefix}acme_enum.state"
  "loops:if-not-exists:{prefix}acme_exists"
  "loops:index-syntax:{prefix}acme_index (KEY email (email) USING BTREE)"
  "loops:space-in-type:{prefix}acme_spaces.price"
  "loops:two-columns-one-line:{prefix}acme_oneline.lat"
  "loops:type-alias:{prefix}acme_alias.enabled"
)
# MariaDB reports integer display widths and stores JSON as LONGTEXT; MySQL 8.0.17 and later do neither.
mariadb_only=(
  "loops:int-display-width:{prefix}acme_width.id"
  "loops:int-display-width:{prefix}acme_width.total"
  "loops:type-alias:{prefix}acme_json.settings"
)
if [ "$engine" = mariadb ]; then
  expected="$(printf '%s\n' "${common[@]}" "${mariadb_only[@]}" | LC_ALL=C sort | paste -sd, -)"
else
  expected="$(printf '%s\n' "${common[@]}" | LC_ALL=C sort | paste -sd, -)"
fi
set +e
out="$(run dbdelta-dryrun check "$here/fixtures/pitfalls.sql" --format=json)"; code=$?
set -e
check "every pitfall is found with its cause ($engine)" "$expected" "$(echo "$out" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $o = array(); foreach ($r["results"] as $x) { $o[] = $x["status"] . ":" . $x["cause"] . ":" . $x["target"]; } sort($o, SORT_STRING); echo implode(",", $o);')"
check "pitfalls exit 1" 1 "$code"

set +e
text="$(run dbdelta-dryrun check "$here/fixtures/pitfalls.sql")"
set -e
check "the text report names the server" 1 "$(echo "$text" | grep -c "^dbdelta-dryrun: 9 tables from .*pitfalls.sql, " || true)"

leftover="$(wp --path="$WP_PATH" "${extra[@]}" eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"dbdd%\""));')"
check "scratch tables are dropped" 0 "$leftover"

set +e
out="$(run --require="$here/fixtures/installer.php" dbdelta-dryrun check --from=acme_dryrun_install --format=json)"; code=$?
set -e
if [ "$engine" = mariadb ]; then
  check "--from reads the SQL a plugin passes to dbDelta()" "loops:int-display-width:{prefix}acme_from.id" "$(echo "$out" | causes)"
else
  check "--from reads the SQL a plugin passes to dbDelta()" "" "$(echo "$out" | causes)"
fi
created="$(wp --path="$WP_PATH" "${extra[@]}" eval 'global $wpdb; echo count($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . "acme_from")));')"
check "--from stops dbDelta() before it creates the plugin's own table" 0 "$created"

set +e
WP_ENVIRONMENT_TYPE=production run dbdelta-dryrun check "$here/fixtures/converges.sql" >/dev/null 2>&1; code=$?
set -e
check "refuses to run on production" 1 "$code"

wp --path="$WP_PATH" "${extra[@]}" eval 'global $wpdb; $wpdb->query("CREATE TABLE dbddtaken_acme_events (id int)");'
set +e
msg="$(run dbdelta-dryrun check "$here/fixtures/converges.sql" --prefix=dbddtaken_ 2>&1)"; code=$?
set -e
wp --path="$WP_PATH" "${extra[@]}" eval 'global $wpdb; $wpdb->query("DROP TABLE dbddtaken_acme_events");'
check "never touches a table that already exists" 1 "$code"
check "says which table exists" 1 "$(echo "$msg" | grep -c "dbddtaken_acme_events already exists" || true)"

echo "# pass $pass"
echo "# fail $fail"
[ "$fail" -eq 0 ]

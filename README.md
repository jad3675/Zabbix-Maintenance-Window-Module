# Maintenance Windows

A Zabbix frontend module. Paste a list of hosts, verify them, drop them all
into a maintenance window. Regular Zabbix Users can use it, which is the whole
point, since `maintenance.create` is Admin/Super admin only.

Tested target: **Zabbix 7.0 / 7.2 / 7.4** (manifest v2).

---

## Install

```bash
# 7.2 and newer
cd /usr/share/zabbix/ui/modules
# 7.0 and older
# cd /usr/share/zabbix/modules

cp -r /path/to/maintwin .
cd maintwin
cp config.php.example config.php
$EDITOR config.php

chown root:apache config.php        # nginx / www-data as appropriate
chmod 640 config.php
```

Containerised frontend: the module directory has to be a volume mount into
`/usr/share/zabbix/ui/modules/maintwin` inside the frontend container, and
`config.php` needs to be readable by the container's web user, not the host's.

Then: **Administration → General → Modules → Scan directory**, enable
*Maintenance Windows*. A **Maintenance Windows** entry appears under Monitoring
with two children:

- **Schedule** — paste, verify, set the window
- **In flight** — what this module has placed, with Extend, Edit and End
- **Devices in Maintenance** — every host Zabbix currently has suppressed,
  whoever put it there, exportable as CSV

Two real pages rather than tabs, so each has its own URL and menu highlight and
can be bookmarked or pasted into a ticket. Edit on the In flight page hands off
to the Schedule page with `?edit=<maintenanceid>`; the form is defined once and
lives there.

## The service token

Host lookups run in-process as the logged-in user. The three maintenance calls
(`create`, `get`, `delete`) go out over HTTP to `api_jsonrpc.php` with a
service token, because a plain User cannot make them.

1. Create a Super admin service user, e.g. `svc-maintwin`.
2. Give it a role whose **API methods** list is set to *Allow list* containing
   only: `maintenance.create`, `maintenance.get`, `maintenance.delete`,
   `usergroup.get`. That last one is only needed if you use
   `allowed_user_groups`.
3. Users → API tokens → create a token for that user, no expiry or a long one.
4. Put the token in `config.php`, or in a file and set `token_file`.

Point `api_url` at loopback. The frontend talking to itself over the network
for this is pointless and gives you a TLS problem you do not need.

## What it actually does

**Matching.** One line is one host. Newlines separate rows; commas, semicolons
and tabs separate columns within a row, and the columns are treated as
alternative identifiers for that same host, not as separate hosts.

Two nested orderings, and they are not the same thing:

- **By column, outermost.** Column 1 is authoritative. Columns 2, 3 and so on
  are only consulted when the earlier ones miss. So `core-sw-01,10.20.30.1`
  resolves on the name; if that name has been decommissioned, the row falls
  through to the IP and still lands on one host, as one row.
- **By kind, within a column.** Each column value is tried against technical
  name, then visible name, then interface IP, then interface DNS.

If a later column resolves to a *different* host than column 1 did, column
order still wins, but the row is flagged and the note names the other host.
That is a CSV that has drifted from reality, and you want to know before the
window goes in rather than after.

A row where no column matches is one "not found", not one per column. No column
mapping to configure.

`filter` in the Zabbix API is an exact SQL match, which is case-sensitive on
PostgreSQL. Anything unmatched after the exact pass gets a second pass through
`search` with a case-insensitive comparison in PHP, because nobody pastes
hostnames in the case the CMDB happens to use.

Rows come back tagged: matched, not found, ambiguous (one column value,
several hosts), duplicate (an earlier row already claimed that host), header
row. Disabled hosts, hosts already in maintenance, and rows whose columns
disagree are flagged but not blocked.

A header row is only skipped once nothing in it resolves, so a host
legitimately named `device` is not silently dropped.

**Creating.** Four schedule types: one time, daily, weekly, monthly.

The Zabbix timeperiod object overloads its fields depending on
`timeperiod_type`, and getting that wrong is the classic bug where the API
returns success and the host never enters maintenance. What the module writes:

| type | fields |
|---|---|
| one time (0) | `start_date` = absolute epoch. `start_time` is ignored and is not sent. |
| daily (2) | `start_time` (seconds past midnight), `every` = every N days |
| weekly (3) | `start_time`, `every` = every N weeks, `dayofweek` bitmask |
| monthly (4) | `start_time`, `month` bitmask, then **either** `day` 1-31 **or** `dayofweek` bitmask plus `every` 1-5 (5 meaning last) |

The two monthly modes are mutually exclusive. Sending both `day` and
`dayofweek` is how you get a window that fires on days nobody asked for, so
the builder only ever emits one of them.

`active_since`/`active_till` are the outer envelope, which is why a recurring
window needs a date range and a one-time window does not. The recurrence never
fires outside it.

`active_since` and `active_till` are floored to the minute, and the window is
named with the configured prefix plus a short random suffix so two people
scheduling "Patching" at the same moment do not collide on the uniqueness
constraint.

`hosts: [{hostid}]` is used on 7.0+; `hostids` on older versions. 7.2 removed
the deprecated `hostids`/`groupids` names outright, so this matters. The
version is read once from `apiinfo.version`.

**Editing.** Cards carry three actions.

*Extend* bumps a running one-time window by 15m to 8h from a dropdown. This is
the thing people actually want at 2am when the change overruns. It moves both
`period` and `active_till`; move only one and the envelope truncates the window
and the extension silently does nothing.

*Edit* loads the window back into the same three-step form: hosts into the
paste box, schedule into the schedule fields, name and ticket and note into
their boxes. Change whatever, save. The reverse mapping is unit-tested for
round-trip stability, so loading and re-saving an untouched window is a no-op.

Two things `Edit` refuses. A window with more than one time period was edited
by hand in the native UI, and rewriting it from a form that can only express
one period would silently throw the others away, so those are listed but not
editable. And on save, hosts already in the window that the editing user cannot
see are preserved rather than filtered out. Passing the existing host list
through the visibility filter would quietly evict hosts belonging to someone
else's permission scope, which is a fun outage to debug.

Every extend and edit appends a line to the description's `History:` field.

**Ending.** Deletes the maintenance entry. Hosts leave maintenance within a
minute, since the server recalculates maintenance every minute. For a recurring
window this cancels every future occurrence, and the confirm dialog says so.

## Permission model

The elevated token could do anything to any maintenance window, so it is
fenced in three places:

1. **Host resolution is never elevated.** Every `host.get` and
   `hostinterface.get` runs as the logged-in user. A host they cannot read
   does not resolve, so it cannot be put into maintenance.
2. **Hostids from the browser are re-resolved before use.** `Create` throws
   away anything the user cannot read, and reports how many it dropped. A
   crafted POST gets you nothing.
3. **Only this module's own windows are visible or deletable.** Windows are
   found by name prefix, and `End` refuses any window whose name does not
   start with that prefix. Without that check, an elevated token plus a
   guessable maintenanceid lets anyone with page access delete a change
   window someone else set up by hand.

The "windows in flight" list also trims each window's host list down to hosts
the viewer can read, and hides windows where that leaves nothing, so the page
does not leak host names.

Optional in `config.php`: `allowed_user_groups` restricts the module to named
user groups; `own_windows_only` stops users ending each other's windows.

The menu entry is visible to everyone. The permission check lives in the
controllers, so a user outside `allowed_user_groups` gets a denied page rather
than a hidden link. Fix that with a menu-level check if it bothers you.

## PHP version notes

Written against PHP 8.0 through 8.5. Two things bite on 8.5 specifically and
are already handled: nullable parameters are declared `?string` rather than
relying on the implicit form, and `curl_close()` is not called (it has been a
no-op since 8.0 and was deprecated outright in 8.5).

If the loader page still shows deprecation notices, check whether they name a
file under `modules/` or under Zabbix's own `include/` before blaming this
module. Zabbix 7.4 does not officially certify PHP 8.5, so its own code emits
some.

## Devices in Maintenance

The other two pages are about this module's own records. This one is not, and
that is the point.

It is built from `host.get` filtered on `maintenance_status`, which Zabbix
maintains itself, rather than from `maintenance.get`. So it catches everything
the module has no record of: a window scheduled by hand in Data collection, a
window with a host-group target that swept in hosts nobody listed individually,
a recurring window somebody set up two years ago and forgot about. The Source
column marks each row as *this tool* or *external*, and there is a checkbox to
show only the latter, which is the "what did the admins do" view.

Because it queries hosts as the logged-in user, it is permission-scoped for
free: nobody sees a suppressed host they could not already see elsewhere.

Columns: host (technical name shown underneath when it differs), IP, tags,
collection mode, window name, when the host entered maintenance, when the
window ends, source. Click a header to sort. The filter box matches host name,
IP, window name and tags at once.

Window names and end times need `maintenance.get`, which is Admin-only, so they
come through the service token. If the token is broken the page still renders
with those two columns blank rather than failing outright, since everything
else on it is useful without them.

**CSV export** writes what is on screen, filter and sort included. Exporting
the unfiltered set after someone has narrowed the list would be a nasty
surprise. The file gets a UTF-8 BOM so Excel does not mangle tag values, RFC
4180 quoting, and an apostrophe in front of any field starting with `=`, `+`,
`-` or `@`, because those are executed as formulas by Excel and Sheets and
these files get mailed around.

## Renaming, and the window prefix

`name_prefix` (default `[MW]`) is the one internal string users see: it sits on
the front of every window name in Data collection → Maintenance. The module
also uses it to find its own work, and refuses to edit or delete anything that
does not carry it.

So changing it orphans anything already out there: windows written under the
old value go invisible on the In flight page and cannot be ended from it. They
still exist and still suppress alerts, they just have to be cleared out by hand
in Data collection → Maintenance. Change the prefix only when nothing is in
flight.

Note that `config.php` is yours and an upgrade never overwrites it. If the
default in `config.php.example` changes between versions, your live value stays
whatever you set it to. The In flight page prints the prefix currently in
force, which is the quickest way to check which one you are actually running.

The CSS classes and DOM ids are still `ml-` prefixed from an earlier name.
Purely internal, not worth the churn of renaming.

## Known rough edges

- **The menu item shows for all users** (see above).
- **Audit log attribution.** Zabbix records the maintenance create/delete
  against the service account, not the person who clicked. The requesting
  user, timestamp and ticket are written into the maintenance description,
  which is where you will have to look. There is no way around this short of
  giving every operator real Admin rights.
- **One time period per window.** The form expresses a single recurrence rule.
  A window needing several (say, weekly on Sunday *and* the last Friday of the
  quarter) has to be built in Data collection → Maintenance. The module will
  display such a window but refuses to edit it.
- **No maintenance tags.** Zabbix can scope a with-data-collection window to
  problems matching given tags. Not exposed here; every problem on the host is
  suppressed. Straightforward to add if you want it.
- **Expired windows are not cleaned up.** Zabbix keeps them forever. Tick
  "Include expired" and clear them out, or add a cron job that deletes
  prefixed windows whose `active_till` is more than N days old.
- **Timezones.** Start times are read in the timezone of the logged-in user's
  profile, because Zabbix has already set the PHP timezone for the request by
  the time the controller runs. If a user's profile timezone differs from what
  they think they are looking at, that is a Zabbix profile problem, not a
  module problem, but it will show up here first.
- **CSRF.** The AJAX actions call `disableCsrfValidation()` (guarded by
  `method_exists`, since it only exists on 6.4+). The actions are all
  session-authenticated and permission-checked, but if you want belt and
  braces, plumb a `CCsrfTokenHelper` token through the view and validate it.

## Files

```
maintwin/
├── manifest.json               actions, namespace, assets
├── Module.php                  menu entry
├── config.php.example          copy to config.php
├── actions/
│   ├── Base.php                config, permissions, token parsing, host resolution
│   ├── Schedule.php            timeperiod builder, describer and reverse mapping
│   ├── ZbxApi.php              service-token JSON-RPC client
│   ├── View.php                both pages (getAction() decides which)
│   ├── Verify.php              resolve a pasted list
│   ├── Create.php              build and submit the window
│   ├── Update.php              load, extend, edit
│   ├── InMaint.php             every suppressed host, from host status
│   ├── MaintList.php           windows in flight
│   └── End.php                 delete a window
├── views/
│   └── maintwin.view.php
└── assets/
    ├── js/maintwin.js
    └── css/maintwin.css
```

Assets load on every frontend page, so the JS bails out immediately unless
`.ml-page` is present and every CSS selector is `.ml-` prefixed. Colours are
translucent greys and inherited text so the page survives all four Zabbix
themes without sniffing which one is active.

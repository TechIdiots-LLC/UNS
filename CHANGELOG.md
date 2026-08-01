# Changelog

## master
### ✨ Features and improvements
- _...Add new stuff here..._

### 🐞 Bug fixes
- _...Add new stuff here..._


## 3.1.0
### ✨ Features and improvements
- **Client groups.** A group is a named collection of clients with its own URL list. Membership is many-to-many, so one screen can sit in several groups at once - a building, and a role such as "outdoor". A group either **adds** its URLs to each member's normal rotation, or **replaces** them entirely for a takeover; where a client lands in more than one active replace group, the highest **priority** wins. Groups can be parked without being deleted, and removing a client or a group clears its membership so a screen registered later under a recycled name cannot inherit somebody else's groups.
- **Emergency mode can target a group or a single client.** Previously it was all or nothing: a weather warning for one building put every screen in the estate onto the emergency URLs. The global switch is unchanged and still overrides everything, but alongside it any group or client can be put into emergency mode on its own, from the group page, the client page, or a new panel on the Emergency Messages screen that lists everything currently live. Emergency URLs are scoped the same way, and a target with no URLs of its own falls back to the shared list.
- **Emergency mode now expires by itself.** Every targeted emergency, and the global switch when the monitor raises it, records an expiry taken from the alert's own CAP `<expires>` where there is one. The client page honours it, so a monitor that stops running mid-alert no longer strands every display on it indefinitely - previously nothing stood emergency mode down except the monitor running again.
- **Alert routing.** The emergency monitor can send an alert to particular screens rather than all of them, configured under **Emergency Messages → Alert Routing**: "an alert whose *field* *matches* *value* raises emergency mode for *this group or client*", with a minimum severity. Routing reads CAP `category`, `certainty`, `areaDesc` and `geocode` (SAME, FIPS6 and UGC) - the fields you actually address a locality by, none of which were parsed before - as well as event, severity, urgency, sender and headline. CAP allows those to repeat, so an alert covering three counties reaches a rule naming any one of them, and each destination gets its own message so two groups can show two different alerts at once. With no rules configured the monitor drives the global switch exactly as it did in 3.0.0, and once rules exist the global switch is only raised by a rule that explicitly targets every client, so a targeted alert cannot black out the estate. The monitor only ever clears emergencies it raised itself, so a takeover switched on by hand is never cancelled by the next feed poll.
- **A test suite, run in CI on PHP 7.4, 8.1, 8.3 and 8.4.** `php Tests/run-tests.php` stands up a throwaway install - a copy of the application, a SQLite database built from the shipped schema, and a web server - and drives it over HTTP, covering what a display is served, group resolution, emergency precedence and expiry, the monitor's CAP parsing and routing, and upgrading a database that predates each feature. CI also lints every PHP file and compiles every Smarty template. It needs nothing installed, matching the fact that UNS ships without Composer.
- New tables (`client_groups`, `client_group_members`, `group_links`, `emerg_targets`, `emerg_routes`) and the two new `emerg` columns are created on demand the first time they are used, so **existing installs need no upgrade step**.

### 🐞 Bug fixes
- **Emergency mode served nothing at all on a fresh install.** The client page selected emergency URLs against an `emerg.cl_id` column that has never existed in any of the three schema files and that no code path has ever written - it dates to the original 1.0 import. Turning emergency mode on returned HTTP 500 with an empty body on SQLite, and left the display with `no_urls` on MySQL. The same condition was also mis-grouped, so the branch ignored whether an emergency URL was enabled.
- A rotating display **could show the same page twice in a row**. The check for "the URL served last time" ordered only by a timestamp with one-second resolution, so any two requests landing in the same second left the previous URL arbitrary.
- The client page emitted a PHP warning on **every request for an unknown client**, or one with no URLs, because the emergency flag was read from an array element those paths do not return. Present since the original import.
- The `uns_ver` value seeded into a manually-created database still read `2.0.0`; it is now kept in step with the release.


## 3.0.0
### ✨ Features and improvements
- Pages render through **Smarty 5.8.4** instead of HTML echoed from inside PHP. The library is vendored under `Server/www/lib/smarty` (UNS has no Composer setup) and loaded through the PSR-4 stub Smarty ships for that purpose. Smarty 5 supports PHP 7.2+, so this does not cost the PHP 7.4 support from 2.0.0.
- `admin/index.php` is down from ~3,800 lines to ~2,400. Every screen now has a template under `Server/www/templates/`, and output escaping is on by default.
- **`Scripts/EmergencyMonitor`** replaces the Windows-only AutoIt `RaveRssEmergencyTriggerTask`. It is a plain PHP script that runs under cron or Task Scheduler, understands CAP 1.1/1.2 as well as RSS and Atom, and reads the database settings from the UNS install rather than keeping its own copy. CAP alerts carry their own expiry, cancellation and status, so drills no longer take over displays and there is no display-window guesswork. It can also publish the alert text to displays as a custom message.
- Emergency monitor settings are configured from **UNS Options** in the admin panel, stored in a new `uns_config` table that is created on demand - existing installs need no upgrade step.
- `configs/vars.php` and `configs/conn.php` ship as `*.sample.php`. The live files are no longer tracked, so a checkout cannot overwrite a deployed configuration and nobody can publish their database credentials by committing them.
- The template cache is created and checked before the installer asks for any settings, and lives outside the document root wherever possible with Apache and IIS deny rules.

### 🐞 Bug fixes
- Custom message **wrapper flags were applied to the wrong message**. The checkbox was named `wrapper[]`, and an unticked checkbox is not submitted at all, so ticking one message set the flag on a different one and cleared the intended record.
- Saving the options page could convert a SQLite or SQL Server install to MySQL, because `$driver` was out of scope when `conn.php` was rewritten. It also demanded a database password, which a SQLite install does not have - so UNS Options could never be saved there.
- **Redirect Page Timeout** and **Default URL Refresh time** were hardcoded in the options form, so saving any unrelated setting silently reset both.
- The archived URL list printed the raw `url~refresh` separator; it and the saved list now share one partial so they cannot diverge again.
- One of 45 copy-pasted redirect blocks contained a stray `$proto.` inside the JavaScript string and pointed at a nonexistent URL. They are now a single helper.
- The schema loader split files on every `;`, including semicolons inside `--` comments, which silently dropped a table from a fresh install.
- The logout link put the username straight into HTML without escaping.


## 2.0.0
### ✨ Features and improvements
- PHP 8 compatibility throughout, and **runs on PHP 7.4 through 8.x** (the app was written for PHP 5 and would not run at all on a current PHP install). PDO's default error mode differs between 7.4 and 8.0, so it is pinned explicitly and both behave identically.
- Database portability: MySQL, Microsoft SQL Server, and SQLite are all supported via PDO, selectable during install
- Chrome extension modernized to Manifest V3
- **Installer hardening.** It checks up front that it can write what it needs, places a SQLite database outside the document root where possible, ships Apache and IIS deny rules for it, and finishes with post-install steps tailored to the platform and database chosen.
- The database account and the built-in admin account are created with **randomised names and passwords**, shown in the form so they can be recorded or replaced.
- The built-in admin account name is **configurable** (`$admin_user`) instead of hardcoded as `unsadmin`, and any internal user can now log in - previously only the built-in account could, despite the Add User form collecting a password.
- Guards against locking yourself out: the built-in admin cannot be disabled, and an administrator cannot be removed or demoted, while they are the only account that can both log in and manage users.

### 🐞 Bug fixes
- Fixed a session-cookie privilege-escalation bug that let anyone with a valid login edit their own cookie to act as the built-in admin account
- Fixed command injection and an arbitrary-file-write in the database backup/restore feature
- Converted ~100 raw SQL string-concatenated queries to prepared statements
- Upgraded password storage from md5() to password_hash()/password_verify(), with automatic opportunistic upgrade of existing logins
- The installer no longer creates a database before checking it can write configs/vars.php and conn.php, which used to leave an orphaned database and admin account behind on a failed run
- The admin footer showed "vunknown" on any normal deployment, because it looked for the VERSION file at a path that only resolved inside a git checkout
- Blank lines in the "Add URLs" box were stored as empty URL entries, appearing as blank rows in clients and saved lists
- Copy / Save To List / Remove raised a PHP 8 TypeError when no URLs were ticked, and Save To List silently stored an empty list
- A time zone that is not a PHP identifier (eg. "EDT") was silently replaced with UTC during install with no warning
- The user permissions page never showed the built-in admin account, and the Enable/Disable button was labelled from the stored flag rather than the action - so it offered "Enable" while the account was working, and pressing it disabled the account you were logged in as
- The footer and About page now credit both the original author and the current maintainer

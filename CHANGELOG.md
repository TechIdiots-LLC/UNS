# Changelog

## master
### ✨ Features and improvements
- _...Add new stuff here..._

### 🐞 Bug fixes
- _...Add new stuff here..._


## 3.0.0
### ✨ Features and improvements
- Pages render through **Smarty 5.8.4** instead of HTML echoed from inside PHP. The library is vendored under `Server/www/lib/smarty` (UNS has no Composer setup) and loaded through the PSR-4 stub Smarty ships for that purpose. Smarty 5 supports PHP 7.2+, so this does not cost the PHP 7.4 support below.
- `admin/index.php` is down from ~3,800 lines to ~2,400. Every screen now has a template under `Server/www/templates/`, and output escaping is on by default.
- **Runs on PHP 7.4 through 8.x.** PDO's default error mode differs between the two, so it is pinned explicitly and both behave identically.
- **`Scripts/EmergencyMonitor`** replaces the Windows-only AutoIt `RaveRssEmergencyTriggerTask`. It is a plain PHP script that runs under cron or Task Scheduler, understands CAP 1.1/1.2 as well as RSS and Atom, and reads the database settings from the UNS install rather than keeping its own copy. CAP alerts carry their own expiry, cancellation and status, so drills no longer take over displays and there is no display-window guesswork. It can also publish the alert text to displays as a custom message.
- Emergency monitor settings are configured from **UNS Options** in the admin panel, stored in a new `uns_config` table that is created on demand - existing installs need no upgrade step.
- **Installer hardening.** It checks up front that it can write everything it needs, places a SQLite database and the template cache outside the document root where possible, ships Apache and IIS deny rules for both, and finishes with post-install steps tailored to the platform and database chosen.
- The database account and the built-in admin account are created with **randomised names and passwords**, shown in the form so they can be recorded or replaced.
- The built-in admin account name is **configurable** (`$admin_user`) instead of hardcoded as `unsadmin`, and any internal user can now log in - previously only the built-in account could, despite the Add User form collecting a password.
- Guards against locking yourself out: the built-in admin cannot be disabled, and an administrator cannot be removed or demoted, while they are the only account that can both log in and manage users.
- `configs/vars.php` and `configs/conn.php` ship as `*.sample.php`. The live files are no longer tracked, so a checkout cannot overwrite a deployed configuration and nobody can publish their database credentials by committing them.

### 🐞 Bug fixes
- Custom message **wrapper flags were applied to the wrong message**. The checkbox was named `wrapper[]`, and an unticked checkbox is not submitted at all, so ticking one message set the flag on a different one and cleared the intended record.
- Only the built-in admin could authenticate; every other internal user fell through to a blank page.
- The **Enable/Disable Built in Admin** button was labelled from the stored flag rather than the action, so it offered "Enable" while the account was working - and pressing it disabled the account you were logged in as.
- The user permissions page never showed the built-in admin account at all.
- **Redirect Page Timeout** and **Default URL Refresh time** were hardcoded in the options form, so saving any unrelated setting silently reset both.
- Saving the options page could convert a SQLite or SQL Server install to MySQL, because `$driver` was out of scope when `conn.php` was rewritten. It also demanded a database password, which a SQLite install does not have - so UNS Options could never be saved there.
- Compiled templates and archived URL lists: the archive list printed the raw `url~refresh` separator, and the two tables now share one partial so they cannot diverge again.
- One of 45 copy-pasted redirect blocks contained a stray `$proto.` inside the JavaScript string and pointed at a nonexistent URL. They are now a single helper.
- The schema loader split files on every `;`, including semicolons inside `--` comments, which silently dropped a table from a fresh install.
- The installer no longer creates a database before checking it can write `configs/vars.php` and `conn.php`, which used to leave an orphaned database and admin account behind on a failed run.
- The admin footer showed "vunknown" on any normal deployment, because it looked for the `VERSION` file at a path that only resolved inside a git checkout.
- Blank lines in the "Add URLs" box were stored as empty URL entries, appearing as blank rows in clients and saved lists.
- Copy / Save To List / Remove raised a PHP 8 TypeError when no URLs were ticked, and Save To List silently stored an empty list.
- A time zone that is not a PHP identifier (eg. "EDT") was silently replaced with UTC during install with no warning.
- The logout link put the username straight into HTML without escaping.
- The footer and About page now credit both the original author and the current maintainer.


## 2.0.0
### ✨ Features and improvements
- PHP 8 compatibility throughout (the app was written for PHP 5 and would not run at all on a current PHP install)
- Database portability: MySQL, Microsoft SQL Server, and SQLite are all supported via PDO, selectable during install
- Chrome extension modernized to Manifest V3

### 🐞 Bug fixes
- Fixed a session-cookie privilege-escalation bug that let anyone with a valid login edit their own cookie to act as the built-in admin account
- Fixed command injection and an arbitrary-file-write in the database backup/restore feature
- Converted ~100 raw SQL string-concatenated queries to prepared statements
- Upgraded password storage from md5() to password_hash()/password_verify(), with automatic opportunistic upgrade of existing logins

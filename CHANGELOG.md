# Changelog

## master
### ✨ Features and improvements
- _...Add new stuff here..._

### 🐞 Bug fixes
- _...Add new stuff here..._


## 2.0.0
### ✨ Features and improvements
- Runs on PHP 7.4 through 8.x (the app was written for PHP 5 and would not run at all on a current PHP install). PDO's default error mode differs between 7.4 and 8.0, so it is pinned explicitly and both behave identically.
- Database portability: MySQL, Microsoft SQL Server, and SQLite are all supported via PDO, selectable during install
- Chrome extension modernized to Manifest V3
- Installer hardening: it now checks up front that it can write what it needs, places a SQLite database outside the web root where possible, ships Apache and IIS deny rules for the database, and finishes with post-install hardening steps tailored to the platform and database you chose
- The database account and the built-in admin account are both created with randomised names and passwords, shown in the form so they can be recorded or replaced
- The built-in admin account name is configurable (`$admin_user` in configs/vars.php) instead of being hardcoded as "unsadmin"

### 🐞 Bug fixes
- Fixed a session-cookie privilege-escalation bug that let anyone with a valid login edit their own cookie to act as the built-in admin account
- Fixed command injection and an arbitrary-file-write in the database backup/restore feature
- Converted ~100 raw SQL string-concatenated queries to prepared statements
- Upgraded password storage from md5() to password_hash()/password_verify(), with automatic opportunistic upgrade of existing logins
- The installer no longer creates a database before checking it can write configs/vars.php and conn.php, which used to leave an orphaned database and admin account behind on a failed run
- The admin footer showed "vunknown" on any normal deployment, because it looked for the VERSION file at a path that only resolved inside a git checkout
- Blank lines in the "Add URLs" box were stored as empty URL entries, which then appeared as blank rows in clients and in saved lists
- Copy / Save To List / Remove raised a PHP 8 TypeError when no URLs were ticked, and Save To List silently stored an empty list
- A time zone that is not a PHP identifier (eg. "EDT") was silently replaced with UTC during install with no warning
- The footer and About page now credit both the original author and current maintainer

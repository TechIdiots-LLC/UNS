# Changelog

## master
### ✨ Features and improvements
- _...Add new stuff here..._

### 🐞 Bug fixes
- _...Add new stuff here..._


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

UNS Emergency Monitor
=====================

Watches an alert feed and turns UNS emergency mode on and off to match.

This replaces Scripts/RaveRssEmergencyTriggerTask, which was a compiled AutoIt
program and therefore Windows only, talked to MySQL directly (so it could not be
used with the SQL Server or SQLite support added in 2.0.0), kept a second copy of
the database credentials in a plaintext INI, and located the alert timestamp by
its position in the XML rather than by name.

This one is a plain PHP script, so it runs anywhere UNS itself runs, and it reads
the database settings from the UNS install - there are no credentials to repeat.


What it understands
-------------------
  - CAP 1.1 / 1.2 documents  (OASIS Common Alerting Protocol)
  - RSS and Atom feeds       (including the Rave "channel" feeds)
  - RSS/Atom feeds whose newest item links to a CAP document

The format is detected automatically; you only give it a URL.

CAP is worth moving to if your alerting system offers it. An RSS item only has a
timestamp, so the monitor has to guess how long the alert should stay up (the
$display_minutes window). A CAP alert states it:

  <expires>    when to stand down - used instead of the guess
  <msgType>    Cancel clears emergency mode immediately
  <status>     Test and Exercise are ignored, so drills do not take over displays
  <severity>   alerts below $min_severity are ignored


Setup
-----
1. Configure it in the admin panel, under UNS Options -> Emergency Alert Monitor.
   Set the alert feed URL there and save. The settings are stored in the database
   (the uns_config table), so they are covered by the UNS database backup, and
   there is no configuration file to edit or keep in step.

   Leave the feed URL empty to switch the monitor off; it will exit quietly.

   The monitor finds the database by reading the UNS install's own configs, so
   no credentials are repeated anywhere. If you copied this script somewhere
   other than Scripts/EmergencyMonitor/ next to the Server/ folder, copy
   monitor.conf.example.php to monitor.conf.php and set $uns_root in it.

2. Check it before scheduling anything. --dry-run reports what it would do
   without touching the database:

     php uns-emergency-monitor.php --dry-run --verbose

3. Schedule it to run every minute.

   Linux (crontab -e). Run it as a user that can read configs/conn.php - the
   web server user is usually the simplest choice:

     * * * * * /usr/bin/php /path/to/Scripts/EmergencyMonitor/uns-emergency-monitor.php >/dev/null 2>&1

   Windows (Task Scheduler), as a task repeating every minute:

     Program:   C:\php\php.exe
     Arguments: "C:\path\to\Scripts\EmergencyMonitor\uns-emergency-monitor.php"

   Set $log_file in the config if you want a record; the script also writes to
   stdout, which cron will mail you and Task Scheduler records.


What it changes in UNS
----------------------
  - settings.emerg is set to 1 or 0 to match the alert.

  - With $publish_message = true (the default) the alert text is written into a
    custom message named by $message_name, and an emergency URL pointing at that
    message is added and enabled. The same message row is reused for every alert
    rather than accumulating one per alert, and only that one emergency URL is
    ever touched - emergency URLs you added by hand are left alone.

    When the alert clears, the emergency URL is disabled but kept, so the
    previous alert text remains visible in the admin panel for reference.

  Set $publish_message = false to only flip the flag, exactly like the old
  AutoIt script did, and manage the emergency URL list yourself.


Behaviour when the feed cannot be reached
-----------------------------------------
The monitor reports the problem and leaves emergency mode exactly as it is,
rather than assuming either state. Clearing a real alert because of a network
blip, or leaving a stale alert up because a feed went away, are both worse than
doing nothing for one run. Exit codes: 0 ok, 1 config or database problem,
2 feed problem.


Retiring the old script
-----------------------
If you were running RaveRssEmergencyTriggerTask, remove its scheduled task
before enabling this one - two schedulers writing settings.emerg will fight,
since the old one only ever understood the RSS timestamp window.

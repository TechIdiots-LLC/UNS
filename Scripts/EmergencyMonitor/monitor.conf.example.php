<?php
#    monitor.conf.example.php - optional overrides for uns-emergency-monitor.php
#
#    You usually do NOT need this file.
#
#    The feed URL and how alerts are handled are set from the admin panel, under
#    UNS Options -> Emergency Alert Monitor, and stored in the database. The
#    monitor reads them from there, and gets its database settings from the UNS
#    install itself - so nothing has to be configured twice.
#
#    Copy this to monitor.conf.php only if you need one of the overrides below.
#
#    Copyright (C) 2010  Phillip Ferland / Random Intervals
#    Copyright (C) 2026  Andrew Calcutt / TechIdiots LLC
#
#    SPDX-License-Identifier: GPL-2.0-or-later
#
#    This program is free software; you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation; either version 2 of the License, or
#    (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program; if not, write to the Free Software Foundation,
#    Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.

# Path to the UNS web root - the folder holding shared.php and configs/.
# Only needed when this script is not sitting in Scripts/EmergencyMonitor/ next to
# the Server/ folder it belongs to, eg. if you copied it somewhere else on the box.
# $uns_root = '/srv/www/virtual/uns.example.net/demo';

# Optional log file. The monitor always writes to stdout as well, which cron mails
# and Task Scheduler records.
# $log_file = '/var/log/uns-emergency-monitor.log';

# Detailed per-run output. The --verbose switch does the same thing for one run.
# $verbose = true;

# Network timeout in seconds when fetching a feed.
# $http_timeout = 15;

# Name of the custom message the alert text is published into, and how often
# displays showing it should refresh.
# $message_name = 'Emergency Alert (automatic)';
# $message_refresh = 30;

# --- Overriding the admin panel settings -----------------------------------
# Anything set here wins over UNS Options. Handy for pointing a test run at a
# different feed without disturbing the live configuration:
#
#   $feed_url = 'https://example.org/test-cap.xml';
#   $display_minutes = 30;
#   $publish_message = false;
#   $allowed_status = array('Actual', 'Exercise');
#   $min_severity = 'Severe';
#   $max_items = 5;
#   $follow_cap_links = true;

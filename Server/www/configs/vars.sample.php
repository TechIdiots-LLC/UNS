<?php
$name_title     = "URL";                    			# Name of your Install, Will be displayed on all papes
$host           = "your.uns.server/"; 			# The HTTP server the clients will connect to.
$root           = "uns/";                   			# Folder UNS lives in
$timeout        = (3600);                   			# Cookie Time out
$SSL            = 1;                        			# Cookie SSL only?
$domain         = "example.local";   					# LDAP Domain to connect to for user authentication
$port           = 3268;                     			# LDAP Port
$TZ             = 'EST';                    			# Local Time Zone
$page_timeout   = 0;                        			# Refresh time for page to forward in seconds.
$refresh        = 30;                       			# Time for client pages to refresh.
$seed           = 'CHANGE_ME_TO_A_RANDOM_STRING';   # Only used for internal user logins, to hash the password and store that. Change this to a random string.
$LDAP           = 1;                        			# If this flag is set, internal users will be overridden, except for the Admin.
$max_archives   = 10;                       			# The Maximum number of Archived URL lists that will be kept before the oldest is killed
$max_conn_hist  = 10;                       			# The Maximum number of Connection histories that will be kept per client.
$lpt_set_app    = '';     								# Bin for the LPT LED blinker
$lpt_read_app   = ''; 									# Bin for LPT value reader
$led_blink      = 0;                         			# Variable to turn on the LPT LED blinking
$mysql_dump_bin = 'mysqldump';							# Name or location of the mysqldump binary, used by the admin backup/restore feature

?>
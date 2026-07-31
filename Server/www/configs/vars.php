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

# The Template variables for RSS feeds
$template_head_rss = '<html>
    <head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>Powered by UNS</title>
        <link rel="stylesheet" href="../configs/rss_styles.css">
    </head>
    <body class="body">';
$template_foot_rss = '
    </body>
</html>';


# The Template variables for Custom Messages
$template_head_cmsg = '<html>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<title>Powered by UNS</title>
		<link rel="stylesheet" href="../configs/cmsg_styles.css">
	</head>
	<body style="background-color: #C0C0C0">
		<table style="width: 80%; height: 100%;" align="center">
			<tr>
				<td class="cmsgheader" style="height: 67px">
					<img alt="Logo" src="../html/logo.png" width="462" height="70">
				</td>
			</tr>
			<tr class="InfoCell">
				<td valign="top"><br>
					<table style="width: 80%" align="center">
						<tr>
							<td>';
$template_foot_cmsg = 
'							</td>

						</tr>
					</table>

				</td>
			</tr>
		</table>
	</body>
</html>';

?>
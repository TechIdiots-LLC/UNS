{* UNS Options editor.

   One large settings form. The fields are deliberately written out rather than
   generated from a table: they are heterogeneous (text, checkbox, select, some
   disabled unless a related box is ticked) and a generic field loop would be harder
   to read than the markup itself.

   Every value comes from configs/vars.php or configs/conn.php via the PHP block in
   admin/index.php, except the emergency monitor settings, which live in the
   uns_config table. *}
                <script type="text/javascript">
                    function endisable( ) {
                        document.forms['edit_options'].elements['ldap_domain'].disabled =! document.forms['edit_options'].elements['ldap'].checked;
                        document.forms['edit_options'].elements['ldap_port'].disabled =! document.forms['edit_options'].elements['ldap'].checked;
                    }
                    function endisable_led( ) {
                        document.forms['edit_options'].elements['lpt_binary'].disabled =! document.forms['edit_options'].elements['leds'].checked;
                        document.forms['edit_options'].elements['portctl'].disabled =! document.forms['edit_options'].elements['leds'].checked;
                    }
                </script>
                <div align="center">
                    <table border="1">
                        <tr class="client_table_head">
                            <td width="50%" align="center">
                                <a href="?func=backup_options">Backup Options</a>
                            </td>
                            <td align="center">
                                <form enctype="multipart/form-data" name="backup_restore_options" action="?func=restore" method="POST">
                                    <input type="hidden" name="MAX_FILE_SIZE" value="1000000"/>
                                    <input type="file" name="restore_sql" ACCEPT="text/plain" /><br />
                                    <input type="submit" value="Restore Database" />
                                </form>
                            </td>
                        </tr>
                    </table>
                    <form name="edit_options" action="?func=edit_opt_proc" method="POST">
                        <table border="1">
                            <tr class="client_table_head"><th colspan="2">UNS Options Editor</th></tr>

                            <tr class="client_table_head"><th colspan="2">SQL Settings</th></tr>
                            <tr class="client_table_body">
                                <td width="250px">SQL Host</td>
                                <td width="200px"><input type="text" name="sql_host" style="width:100%" value="{$sql_host}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>UNS SQL Username</td>
                                <td><input type="text" name="uns_sql_usr" style="width:100%" value="{$sql_user}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>UNS SQL Password
                                    <br /><font size="1">Leave blank to keep the current password. A SQLite install has none.</font></td>
                                <td><input type="password" name="uns_sql_pwd" style="width:100%" value=""/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Database Name</td>
                                <td><input type="text" name="db_name" style="width:100%" value="{$db_name}"/></td>
                            </tr>

                            <tr class="client_table_head"><th colspan="2">UNS Variables</th></tr>
                            <tr class="client_table_body">
                                <td>Instance Name</td>
                                <td><input type="text" name="uns_name" style="width:100%" value="{$uns_name}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Hostname</td>
                                <td><input type="text" name="hostname" style="width:100%" value="{$hostname}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>HTTP root for UNS</td>
                                <td><input type="text" name="root" style="width:100%" value="{$http_root}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Session Timeout <font size="1">( Seconds )</font></td>
                                <td><input type="text" name="timeout" style="width:100%" value="{$timeout}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>SSL Admin Folder?</td>
                                <td><input type="checkbox" name="ssl" value="1"{if $ssl} checked{/if}/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Use LDAP?</td>
                                <td><input type="checkbox" name="ldap" value="1"{if $ldap} checked{/if} onchange="endisable()"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>LDAP Domain</td>
                                <td><input type="text" name="ldap_domain" style="width:100%" value="{$ldap_domain}"{if !$ldap} disabled{/if}/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>LDAP Port</td>
                                <td><input type="text" name="ldap_port" style="width:100%" value="{$ldap_port}"{if !$ldap} disabled{/if}/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Redirect Page Timeout <font size="1">( 0 = instant redirect )</font></td>
                                <td><input type="text" name="page_timeout" style="width:100%" value="{$page_timeout}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Default URL Refresh time</td>
                                <td><input type="text" name="refresh" style="width:100%" value="{$refresh}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Max Number of Archived links per Client</td>
                                <td><input type="text" name="max_arch" style="width:100%" value="{$max_arch}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Max Number of Connection History per Client.</td>
                                <td><input type="text" name="max_conns" style="width:100%" value="{$max_conns}"/></td>
                            </tr>

                            <tr class="client_table_head">
                                <td colspan="2" align="center">Emergency Alert Monitor
                                    <br /><font size="1">Used by the scheduled script in Scripts/EmergencyMonitor.
                                    Leave the feed URL empty to switch it off.</font></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Alert feed URL
                                    <br /><font size="1">CAP, RSS or Atom - the format is detected automatically</font></td>
                                <td><input type="text" name="emerg_feed_url" style="width:100%" value="{$ef_url}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Display time (minutes)
                                    <br /><font size="1">Only used when the feed gives no expiry. CAP alerts carry their own.</font></td>
                                <td><input type="text" name="emerg_display_minutes" style="width:100%" value="{$ef_minutes}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Publish the alert text to displays?
                                    <br /><font size="1">Writes the alert into a custom message and points an emergency URL at it</font></td>
                                <td><input type="checkbox" name="emerg_publish_message" value="1"{if $ef_publish} checked{/if}/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>CAP status values to act on
                                    <br /><font size="1">Comma separated. Adding Test or Exercise lets drills take over displays.</font></td>
                                <td><input type="text" name="emerg_allowed_status" style="width:100%" value="{$ef_status}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Minimum CAP severity</td>
                                <td>
                                    <select name="emerg_min_severity" style="width:100%">
{foreach $severities as $sev}
                                        <option value="{$sev}"{if $sev == $ef_severity} selected{/if}>{$sev}</option>
{/foreach}
                                    </select>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Feed entries to check
                                    <br /><font size="1">Several alerts can be in force at once, so more than the newest is checked</font></td>
                                <td><input type="text" name="emerg_max_items" style="width:100%" value="{$ef_max}"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>Follow feed links to CAP documents?
                                    <br /><font size="1">For feeds whose entries link to CAP rather than embedding it</font></td>
                                <td><input type="checkbox" name="emerg_follow_cap_links" value="1"{if $ef_follow} checked{/if}/></td>
                            </tr>

                            <tr class="client_table_head"><th colspan="2">LED / Hardware</th></tr>
                            <tr class="client_table_body">
                                <td>Use LEDs?</td>
                                <td><input type="checkbox" name="leds" value="1"{if $leds} checked{/if} onchange="endisable_led()"/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>LPT LED blinker binary</td>
                                <td><input type="text" name="lpt_binary" style="width:100%" value="{$lpt_binary}"{if !$leds} disabled{/if}/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>LPT value reader binary</td>
                                <td><input type="text" name="portctl" style="width:100%" value="{$portctl}"{if !$leds} disabled{/if}/></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>mysqldump binary
                                    <br /><font size="1">Used by the database backup feature</font></td>
                                <td><input type="text" name="mysql_dump" style="width:100%" value="{$mysql_dump}" /></td>
                            </tr>

                            <tr class="client_table_tail">
                                <td align="center" colspan="2"><input type="submit" value="Submit" /></td>
                            </tr>
                        </table>
                    </form>
                </div>

{* Emergency messages shown on every client while emergency mode is on.

     $emerg_on   whether global emergency mode is currently enabled
     $urls       [{id, url, label, refresh, enabled}] - label names the RSS feed or
                 custom message when the URL points back at this UNS install
     $refresh    default refresh for newly added URLs *}
                <script type="text/javascript">
                function SetAllCheckBoxes(FormName, FieldName, CheckValue)
                {
                        if(!document.forms[FormName])
                                return;
                        var objCheckBoxes = document.forms[FormName].elements[FieldName];
                        if(!objCheckBoxes)
                                return;
                        var countCheckBoxes = objCheckBoxes.length;
                        if(!countCheckBoxes)
                                objCheckBoxes.checked = CheckValue;
                        else
                                for(var i = 0; i < countCheckBoxes; i++)
                                        objCheckBoxes[i].checked = CheckValue;
                }
                </script>
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th colspan="6">
                            <form name="emerg_toggle" action="?func=emerg_set" method="POST">
                                <input type="hidden" name="toggle" value="{if $emerg_on}0{else}1{/if}">
                                <input type="submit" style="font-size:18;" value="{if $emerg_on}Disable{else}Enable{/if} Global Emergency Messages?">
                                <br /><font size="4">This will disable normal messages on all Clients.</font>
                            </form>
                        </th>
                    </tr>
                </table>
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th colspan="4">Targeted Emergencies
                            <br /><font size="1">Emergency mode for one group or one client, leaving every
                            other screen on its normal rotation. The global switch above overrides these.</font></th>
                    </tr>
{if $targets|@count == 0}
                    <tr>
                        <td align="center" colspan="4">Nothing is targeted right now.</td>
                    </tr>
{else}
                    <tr class="client_table_head">
                        <th>Scope</th><th>Name</th><th>State</th><th width="1px">Turn off</th>
                    </tr>
{foreach $targets as $t}
                    <tr class="client_table_body">
                        <td align="center">{$t.scope}</td>
                        <td>{$t.name}</td>
                        <td align="center">
{if $t.live}<b>ON</b>{if $t.until > 0} until {$t.until|date_format:"%Y-%m-%d %H:%M"}{/if}
{else}off{/if}
                            <font size="1">({$t.source})</font>
                        </td>
                        <td align="center">
{if $t.live}
                            <form name="target_off_{$t.id}" action="?func=emerg_target_set" method="POST">
                                <input type="hidden" name="scope" value="{$t.scope}:{$t.target}">
                                <input type="hidden" name="on" value="0">
                                <input type="submit" value="Clear">
                            </form>
{/if}
                        </td>
                    </tr>
{/foreach}
{/if}
                    <tr class="client_table_tail">
                        <td colspan="4" align="center">
                            <form name="target_on" action="?func=emerg_target_set" method="POST">
                                Put
                                <select name="scope">
{foreach $scopes as $sc}
{if $sc.value != 'all::'}
                                    <option value="{$sc.value}">{$sc.text}</option>
{/if}
{/foreach}
                                </select>
                                into emergency mode for
                                <input type="text" name="minutes" style="width:45px" value="0"> minutes
                                <font size="1">(0 = until cleared)</font>
                                <input type="hidden" name="on" value="1">
                                <input type="submit" value="Go">
                            </form>
                        </td>
                    </tr>
                </table>
                <hr />
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th colspan="6">Emergency Messages</th>
                    </tr>
                    <tr class="client_table_head">
                        <th width="50px">Enabled?</th><th width="700px">URL</th><th width="120px">Shown to</th><th width="90px">Refresh Time</th><th width="90px">Options</th>
                    </tr>
{if $urls|@count == 0}
                    <tr>
                        <td align="center" colspan="5">No URLS, add some.</td>
                    </tr>
{else}
                    <form name="client_edit" action="?func=update_emerg" method="POST">
{foreach $urls as $u}
                    <tr class="client_table_body">
                        <td align="center">{if $u.enabled}&#x2713;{else}&#x2717;{/if}</td>
                        <td>
                            <a class="links" href="{$u.url}" target="_blank">{$u.url}</a>{if $u.label != ''} ({$u.label}){/if}
                        </td>
                        <td align="center">{$u.scope_label}</td>
                        <td align="center">
                            <input type="hidden" name="url_id[]" value="{$u.id}">
                            <input type="text" name="refresh_t[]" style="width: 49px" value="{$u.refresh}">
                        </td>
                        <td align="center">
                            <input type="hidden" name="url_t[]" value="{if $u.enabled}0{else}1{/if}">
                            <input type="checkbox" name="urls[]" value="{$u.id}">
                        </td>
                    </tr>
{/foreach}
{/if}
                    <tr class="client_table_tail">
                        <td colspan="2"></td>
                        <td align="center">
                            <input type="submit" name="refresh" value="Update">
                        </td>
                        <td>
                            <table align="center">
                                <tr>
                                    <td align="center">
                                        <input type="submit" name="delete" value="Delete"><br />
                                        <input type="submit" name="toggle" value="Enable/Disable">
                                    </td>
                                    <td align="center">
                                        <input type="button" onclick="SetAllCheckBoxes('client_edit', 'urls[]', true);" value="Check"><br />
                                        <input type="button" onclick="SetAllCheckBoxes('client_edit', 'urls[]', false);" value="Uncheck">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="6">
                            <form name="save_new" action="?func=add_emerg" method="POST">
                            <table>
                                <tr>
                                    <td valign="center">URLs:</td>
                                    <td><textarea name="URLS" cols="80" rows="10">http://</textarea></td>
                                </tr>
                                <tr>
                                    <td valign="center">Refresh Times for all:</td>
                                    <td><input type="text" name="refresh" value="{$refresh}"></td>
                                </tr>
                                <tr>
                                    <td valign="center">Shown to:</td>
                                    <td>
                                        <select name="scope">
{foreach $scopes as $sc}
                                            <option value="{$sc.value}">{$sc.text}</option>
{/foreach}
                                        </select>
                                        <font size="1">A group or client with no URLs of its own falls back to the All clients list.</font>
                                    </td>
                                </tr>
                                <tr>
                                    <td></td>
                                    <td><input type='submit' value='Add URLs'></td>
                                </tr>
                            </table>
                            </form>
                        </td>
                    </tr>
                </table>

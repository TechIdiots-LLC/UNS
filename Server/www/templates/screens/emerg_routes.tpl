{* Alert routing: which incoming alerts put which screens into emergency mode.

     $routes      [{id, name, where, field, op, value, sev, enabled}]
     $scopes      [{value:"scope:target", text}] - where an alert can be sent
     $fields/$ops maps of match field and operator, from shared.php
     $severities  CAP severity ladder

   These rules are read by the monitor script in Scripts/EmergencyMonitor. With no
   rules at all it drives the global emergency switch, exactly as it did before
   routing existed. *}
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <form name="route_list" action="?func=update_routes" method="POST">
                        <th colspan="6">Alert Routing Rules</th>
                    </tr>
                    <tr class="client_table_head">
                        <th>Name</th><th>Sends to</th><th>When</th>
                        <th>Min severity</th><th>Enabled</th><th width="1px">Select</th>
                    </tr>
{if $routes|@count == 0}
                    <tr>
                        <td align="center" colspan="6">
                            No rules yet. Until one exists, an alert in force turns on
                            <b>global</b> emergency mode for every client.
                        </td>
                    </tr>
{else}
{foreach $routes as $r}
                    <tr class="client_table_body">
                        <td>{if $r.name != ''}{$r.name}{else}<i>(unnamed)</i>{/if}</td>
                        <td>{$r.where}</td>
                        <td>{$fields[$r.field]|default:$r.field} {$ops[$r.op]|default:$r.op}
                            {if $r.op != 'any'}<b>{$r.value}</b>{/if}</td>
                        <td align="center">{$r.sev}</td>
                        <td align="center">{if $r.enabled}&#x2713;{else}&#x2717;{/if}</td>
                        <td align="center"><input type="checkbox" name="routes[]" value="{$r.id}"/></td>
                    </tr>
{/foreach}
{/if}
                    <tr class="client_table_tail">
                        <td colspan="4" align="center">
                            <font size="1">Ticking a rule and pressing Update turns it on or off.
                            Remove deletes the ticked rules.</font>
                        </td>
                        <td align="center"><input type="submit" value="Update"></td>
                        <td align="center"><input type="submit" name="remove" value="Remove"></td>
                    </tr>
                    </form>
                </table>
                <br />
                <table border="1px" width="100%">
                    <tr class="client_table_head"><th colspan="2">Add a Rule</th></tr>
                    <form name="route_add" action="?func=add_route" method="POST">
                    <tr class="client_table_body">
                        <td width="250px">Name <font size="1">(for your own reference)</font></td>
                        <td><input type="text" name="name" style="width:100%" value=""></td>
                    </tr>
                    <tr class="client_table_body">
                        <td>Send the alert to</td>
                        <td>
                            <select name="scope" style="width:100%">
{foreach $scopes as $s}
                                <option value="{$s.value}">{$s.text}</option>
{/foreach}
                            </select>
                        </td>
                    </tr>
                    <tr class="client_table_body">
                        <td>When this field</td>
                        <td>
                            <select name="field" style="width:100%">
{foreach $fields as $key => $label}
                                <option value="{$key}">{$label}</option>
{/foreach}
                            </select>
                        </td>
                    </tr>
                    <tr class="client_table_body">
                        <td>Test</td>
                        <td>
                            <select name="op" style="width:100%">
{foreach $ops as $key => $label}
                                <option value="{$key}">{$label}</option>
{/foreach}
                            </select>
                        </td>
                    </tr>
                    <tr class="client_table_body">
                        <td>This value
                            <br /><font size="1">e.g. Tornado, Met, or a SAME code such as 029095</font></td>
                        <td><input type="text" name="value" style="width:100%" value=""></td>
                    </tr>
                    <tr class="client_table_body">
                        <td>Minimum severity
                            <br /><font size="1">The alert must be at least this severe as well</font></td>
                        <td>
                            <select name="min_severity" style="width:100%">
{foreach $severities as $sev}
                                <option value="{$sev}">{$sev}</option>
{/foreach}
                            </select>
                        </td>
                    </tr>
                    <tr class="client_table_tail">
                        <td colspan="2" align="center"><input type="submit" value="Add Rule"></td>
                    </tr>
                    </form>
                </table>
                <br />
                <table border="1px" width="100%">
                    <tr class="client_table_head"><th>How routing behaves</th></tr>
                    <tr class="client_table_body">
                        <td>
                            An alert is checked against every enabled rule. Each rule that
                            matches puts its group or client into emergency mode for as long
                            as the alert is in force; when the alert ends or expires, the
                            monitor clears it again.
                            <br /><br />
                            The monitor only ever clears emergencies it raised itself, so an
                            emergency you switch on by hand stays on until you switch it off.
                            <br /><br />
                            An alert matching no rule at all changes nothing. If you want a
                            catch-all, add a rule sending <b>All clients</b> with the test set
                            to <b>anything</b> and a minimum severity.
                        </td>
                    </tr>
                </table>

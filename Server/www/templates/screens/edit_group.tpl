{* One group: its settings, which clients belong to it, and its URL list.

     $group    {id, name, description, mode, priority, active}
     $clients  [{client, friendly, member}] - every registered client, ticked if in
               this group
     $urls     [{id, url, label, refresh, enabled}]
     $refresh  default refresh for a newly added URL

   The membership checkboxes carry the client name as their value rather than
   relying on position, so the posted set *is* the membership - an unticked box
   simply doesn't appear, which cannot misalign anything. *}
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
                        <th colspan="2">Group: {$group.name}
                            <br /><font size="1"><a class="links" href="?func=client_groups">back to all groups</a></font>
                        </th>
                    </tr>
                    <form name="group_settings" action="?func=save_group&amp;group={$group.id}" method="POST">
                    <tr class="client_table_body">
                        <td width="250px">Name</td>
                        <td><input type="text" name="name" style="width:100%" value="{$group.name}"/></td>
                    </tr>
                    <tr class="client_table_body">
                        <td>Description</td>
                        <td><input type="text" name="description" style="width:100%" value="{$group.description}"/></td>
                    </tr>
                    <tr class="client_table_body">
                        <td>Mode
                            <br /><font size="1">Add joins the group's URLs to each client's own list;
                            Replace shows only the group's URLs</font></td>
                        <td>
                            <select name="mode" style="width:100%">
                                <option value="add"{if $group.mode != 'replace'} selected{/if}>Add to the client's list</option>
                                <option value="replace"{if $group.mode == 'replace'} selected{/if}>Replace the client's list</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="client_table_body">
                        <td>Priority
                            <br /><font size="1">Highest wins when a client is in more than one active Replace group</font></td>
                        <td><input type="text" name="priority" style="width:100%" value="{$group.priority}"/></td>
                    </tr>
                    <tr class="client_table_body">
                        <td>Active
                            <br /><font size="1">Un-tick to park the group without deleting it</font></td>
                        <td><input type="checkbox" name="active" value="1"{if $group.active} checked{/if}/></td>
                    </tr>
                    <tr class="client_table_tail">
                        <td colspan="2" align="center"><input type="submit" value="Save Settings"></td>
                    </tr>
                    </form>
                </table>
                <br />
                <table border="1px" width="100%">
                    <form name="group_members" action="?func=save_group_members&amp;group={$group.id}" method="POST">
                    <tr class="client_table_head">
                        <th colspan="2">Clients in this Group</th>
                    </tr>
{if $clients|@count == 0}
                    <tr>
                        <td align="center" colspan="2">There are no clients yet.</td>
                    </tr>
{else}
{foreach $clients as $c}
                    <tr class="client_table_body">
                        <td width="1px" align="center">
                            <input type="checkbox" name="clients[]" value="{$c.client}"{if $c.member} checked{/if}/>
                        </td>
                        <td>{$c.friendly}</td>
                    </tr>
{/foreach}
{/if}
                    <tr class="client_table_tail">
                        <td align="center">
                            <input type="button" onclick="SetAllCheckBoxes('group_members', 'clients[]', true);" value="Check"><br />
                            <input type="button" onclick="SetAllCheckBoxes('group_members', 'clients[]', false);" value="Uncheck">
                        </td>
                        <td align="center"><input type="submit" value="Save Membership"></td>
                    </tr>
                    </form>
                </table>
                <br />
                <table border="1px" width="100%">
                    <tr class="client_table_head"><th colspan="4">Group URL List</th></tr>
                    <tr class="client_table_head">
                        <th width="50px">Enabled?</th><th>URL</th><th width="90px">Refresh Time</th><th width="90px">Options</th>
                    </tr>
{if $urls|@count == 0}
                    <tr>
                        <td align="center" colspan="4">No URLs in this group yet.</td>
                    </tr>
{else}
                    <form name="group_urls" action="?func=update_group_urls&amp;group={$group.id}" method="POST">
{foreach $urls as $u}
                    <tr class="client_table_body">
                        <td align="center">{if $u.enabled}&#x2713;{else}&#x2717;{/if}</td>
                        <td>
                            <a class="links" href="{$u.url}" target="_blank">{$u.url}</a>{if $u.label != ''} ({$u.label}){/if}
                        </td>
                        <td align="center">
                            <input type="hidden" name="url_id[]" value="{$u.id}">
                            <input type="text" name="refresh_t[]" style="width: 49px" value="{$u.refresh}">
                        </td>
                        <td align="center">
                            <input type="checkbox" name="urls[]" value="{$u.id}">
                        </td>
                    </tr>
{/foreach}
                    <tr class="client_table_tail">
                        <td colspan="2" align="center">
                            <font size="1">Ticking a URL and pressing Update flips it between enabled
                            and disabled. Remove deletes the ticked URLs.</font>
                        </td>
                        <td align="center"><input type="submit" value="Update"></td>
                        <td align="center"><input type="submit" name="remove" value="Remove"></td>
                    </tr>
                    </form>
{/if}
                    <tr class="client_table_tail">
                        <td colspan="4" align="center">
                            <form name="group_url_add" action="?func=add_group_url&amp;group={$group.id}" method="POST">
                                URL: <input type="text" name="url" style="width:400px;" value="http://">
                                Refresh: <input type="text" name="refresh" style="width:45px;" value="{$refresh}">
                                <input type="submit" value="Add URL">
                            </form>
                        </td>
                    </tr>
                </table>

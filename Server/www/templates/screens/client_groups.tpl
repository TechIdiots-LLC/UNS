{* Client groups: the list of groups, plus a form to create one.

     $groups  [{id, name, mode, priority, active, members, urls}]

   A group collects clients and gives them a shared URL list. Membership is
   many-to-many, so a screen can be in several groups at once. *}
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <form name="group_list" action="?func=remove_group" method="POST">
                        <th colspan="7">Client Groups</th>
                    </tr>
                    <tr class="client_table_head">
                        <th>Name</th><th>Mode</th><th>Priority</th><th>Active</th>
                        <th>Clients</th><th>URLs</th><th width="1px">Remove</th>
                    </tr>
{if $groups|@count == 0}
                    <tr>
                        <td align="center" colspan="7">
                            No groups yet. Add one below, then put clients in it.
                        </td>
                    </tr>
{else}
{foreach $groups as $g}
                    <tr class="client_table_body">
                        <td><a class="links" href="?func=edit_group&amp;group={$g.id}">{$g.name}</a></td>
                        <td align="center">{if $g.mode == 'replace'}Replace{else}Add{/if}</td>
                        <td align="center">{$g.priority}</td>
                        <td align="center">{if $g.active}&#x2713;{else}&#x2717;{/if}</td>
                        <td align="center">{$g.members}</td>
                        <td align="center">{$g.urls}</td>
                        <td align="center"><input type="checkbox" name="remove[]" value="{$g.id}"/></td>
                    </tr>
{/foreach}
{/if}
                    <tr class="client_table_tail">
                        <td colspan="6"></td>
                        <td align="center"><input type="submit" value="Remove"></td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="7" align="center">
                            <form name="group_add" action="?func=add_group" method="POST">
                                New group name:
                                <input type="text" name="name" style="width:300px;" value="">
                                <input type="submit" value="Add Group">
                            </form>
                        </td>
                    </tr>
                </table>
                <br />
                <table border="1px" width="100%">
                    <tr class="client_table_head"><th>How groups affect a client</th></tr>
                    <tr class="client_table_body">
                        <td>
                            <b>Add</b> - the group's URLs join the normal rotation of every client in it.
                            <br />
                            <b>Replace</b> - while the group is active, its clients show only the group's
                            URLs and ignore their own list. Use this for a takeover.
                            <br /><br />
                            A client can be in several groups. If it lands in more than one active
                            <b>Replace</b> group, the highest <b>Priority</b> wins. Un-ticking
                            <b>Active</b> parks a group without deleting it.
                        </td>
                    </tr>
                </table>

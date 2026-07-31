{* A collapsible table of stored URL lists - used for both Saved Lists and a client's
   Archived Links, which differ only in heading and remove action.

   Parameters:
     heading        table heading
     rows           [{id, name, date, urls_raw, entries:[{url, refresh}]}]
     client_id      current client, for the restore form
     id_prefix      prefix for the expand/collapse element ids, so the two tables on
                    the page do not collide
     remove_action  form action for the remove button

   Both tables previously duplicated this markup, and only the Saved Lists copy had
   been fixed to split the stored "url~refresh" pairs into columns - the archive copy
   still printed the raw separator. Sharing one partial fixes that by construction. *}
                <table border="1px" class="all_tables">
                    <tr class="client_table_head"><th colspan="5">{$heading}</th></tr>
                    <tr class="client_table_head">
                        <th>+/-</th><th>Name</th><th>Date</th><th>Options</th>
                    </tr>
{foreach $rows as $row}
                    <tr class="client_table_body">
                        <td onclick="expandcontract('{$id_prefix}Row{$row@index}','{$id_prefix}ClickIcon{$row@index}')"
                            id="{$id_prefix}ClickIcon{$row@index}" style="cursor: pointer; cursor: hand;">+</td>
                        <td>{$row.name}</td>
                        <td>{$row.date}</td>
                        <td>
                            <table>
                                <tr>
                                    <td>
                                        <form name="saved" action="?func=edit_urls&amp;client={$client_id}&amp;cl_func=restore" method="POST">
                                            <input type="hidden" name="urls" value="{$row.urls_raw}">
                                            <input type='submit' value='Restore'>
                                        </form>
                                    </td>
                                    <td>
                                        <form name="saved" action="{$remove_action nofilter}" method="POST">
                                            <input type="hidden" name="id" value="{$row.id}">
                                            <input type='submit' value='Remove'>
                                        </form>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tbody id="{$id_prefix}Row{$row@index}" style="display:none">
                        <tr>
                            <td colspan="4">
                                <table border="1" width="100%">
{if $row.entries|@count == 0}
                                    <tr class="client_table_body">
                                        <td><i>This list is empty - no URLs were selected when it was saved.</i></td>
                                    </tr>
{else}
{foreach $row.entries as $e}
                                    <tr class="client_table_body">
                                        <td>{$e.url}</td>
                                        <td align="center" style="width:120px;">refresh: {$e.refresh}</td>
                                    </tr>
{/foreach}
{/if}
                                </table>
                            </td>
                        </tr>
                    </tbody>
{/foreach}
                </table>

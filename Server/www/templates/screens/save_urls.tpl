{* "Save To List" on the client view: save the ticked URLs as a new named list, or
   append them to an existing one. Both forms are shown together.

     $client_id    the client being saved from
     $saved_lists  [{id, name}] existing lists, for the append form
     $urls_imp     the ticked URL ids, joined with "|", passed straight through *}
                    <form name="save_new" action="?func=edit_urls&amp;client={$client_id}&amp;cl_func=save_new" method="POST">
                    <table>
                        <tr>
                            <th>Save to List:</th>
                        </tr>
                        <tr>
                            <td valign="center">Name:</td>
                            <td>
                                <input type="text" name="name" value="">
                                <input type="hidden" name="urls" value="{$urls_imp}">
                            </td>
                        </tr>
                        <tr>
                            <td valign="center">Details:</td>
                            <td><textarea name="details" cols="40" rows="10"></textarea></td>
                        </tr>
                        <tr>
                            <td align="center"><input type='submit' name="submit" value='submit'></td>
                        </tr>
                    </table>
                        <hr />
                    </form>
                    <form name="save_append" action="?func=edit_urls&amp;client={$client_id}&amp;cl_func=save_append" method="POST">
                    <table>
                        <tr>
                            <th>Append to List:</th>
                        </tr>
                        <tr>
                            <td>
                                <select name="saved" style="width:100%;" size="10">
{foreach $saved_lists as $s}
                                    <option value="{$s.id}">{$s.name}</option>
{/foreach}
                                </select>
                                <input type="hidden" name="urls" value="{$urls_imp}">
                            </td>
                        </tr>
                        <tr>
                            <td align="center"><input type='submit' name="submit" value='submit'></td>
                        </tr>
                    </table>
                    </form>

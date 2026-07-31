{* "Copy" on the client view: pick the clients to copy the ticked URLs to.

     $client_id  the client being copied from
     $clients    [{client, friendly}] every other client
     $urls_imp   the ticked URL ids, joined with "|", passed straight through *}
                    <form name="client_copy" action="?func=edit_urls&amp;client={$client_id}&amp;cl_func=copy2_proc" method="POST">
                    <table>
                        <tr>
                            <th>Choose Clients to Copy URLs to:</th>
                        </tr>
                        <tr>
                            <td>
                                <select name="copy_clients[]" style="width:100%;" size="10" multiple="multiple">
{foreach $clients as $c}
                                    <option value="{$c.client}">{$c.friendly}</option>
{/foreach}
                                </select>
                                <input type="hidden" name="urls" value="{$urls_imp}">
                            </td>
                        </tr>
                        <tr>
                            <td align="center">
                                <input type='submit' name="submit" value='submit'>
                            </td>
                        </tr>
                    </table>
                    </form>

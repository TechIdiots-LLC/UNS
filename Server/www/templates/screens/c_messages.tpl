{* Custom messages: an editable list plus an add form.

     $messages  [{id, name, body, wrapper, url}]
     $reg_url   client-facing base URL, used to build each message's link

   Message bodies are authored as HTML by an administrator, so the textarea contents
   are printed with "nofilter" - escaping them would show markup instead of letting it
   be edited. *}
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <form name="save_new" action="?func=c_messages&amp;mode=edit_messg" method="POST">
                        <th colspan="5">Custom Messages</th>
                    </tr>
                    <tr class="client_table_head">
                        <th colspan="5"><input type='submit' name="update_body" value="Update All Messages"></th>
                    </tr>
                    <tr class="client_table_head">
                        <th style="width:1px;">+/-</th><th>Name</th><th>Message URL</th><th width="1px">Options</th>
                    </tr>
{if $messages|@count == 0}
                    <tr>
                        <td align="center" colspan="5">There are no custom messages yet.</td>
                    </tr>
{else}
{foreach $messages as $m}
                    <tr class="client_table_body">
                        <td onclick="expandcontract('mesgRow{$m@index}','mesgClickIcon{$m@index}')"
                            id="mesgClickIcon{$m@index}" style="cursor: pointer; cursor: hand;">+
                        </td>
                        <td style="width:25%;">
                            <input type="hidden" name="id[]" value="{$m.id}"/>
                            <input type="text" name="name[]" style="width:90%;" value="{$m.name}"/>
                        </td>
                        <td>
                            <a class="links" href="{$m.url}" target="_blank">{$m.url}</a>
                        </td>
                        <td style="width:1%;" align="center">
                            <input type="checkbox" name="remove_[]" value="{$m.id}"/>
                        </td>
                    </tr>
                    <tbody id="mesgRow{$m@index}" style="display:none">
                    <tr>
                        <td colspan="5">
                            <textarea name="body[]" rows="10" style="width:90%">{$m.body nofilter}</textarea>
                            <br />
                            {* Indexed explicitly, not "wrapper[]". An unticked checkbox is not
                               submitted at all, so a bare wrapper[] arrives packed - tick only
                               the second of three messages and it posts wrapper[0], applying
                               the flag to the first message instead. *}
                            Use the UNS Wrapper? <input type="checkbox" name="wrapper[{$m@index}]" value="1"{if $m.wrapper} Checked{/if}/>
                            <br />
                        </td>
                    </tr>
                    </tbody>
{/foreach}
{/if}
                    <tr class="client_table_tail">
                        <td align="center" colspan="3"></td>
                        <td align="center">
                            <table>
                                <tr>
                                    <td align="center" valign="center">
                                        <input type='submit' name="remove" value='Remove'>
                                    </td>
                                    <td align="center" valign="center">
                                        <input type="button" onclick="SetAllCheckBoxes('save_new', 'remove_[]', true);" value="Check"><br />
                                        <input type="button" onclick="SetAllCheckBoxes('save_new', 'remove_[]', false);" value="Uncheck">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="5" align="center">
                            <form name="save_new1" action="?func=c_messages&amp;mode=add_messg" method="POST">
                            <table>
                                <tr>
                                    <td valign="center">Name:</td>
                                    <td><input type="text" name="name_n" style="width:100%;" value=""></td>
                                </tr>
                                <tr>
                                    <td valign="center">Message:<br /><font size="1">In HTML</font></td>
                                    <td><textarea name="body_n" cols="100" rows="10">[Put Message Here]</textarea></td>
                                </tr>
                                <tr>
                                    <td align="center">Use the UNS Wrapper?</td>
                                    <td><input type="checkbox" name="wrapper" value="1" Checked/></td>
                                </tr>
                                <tr>
                                    <td colspan="2" align="center"><input type='submit' value='Add Message'></td>
                                </tr>
                            </table>
                            </form>
                        </td>
                    </tr>
                </table>

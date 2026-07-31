{* One client: its name, URL list, saved lists and archived lists.

   Data prepared in admin/index.php:
     $client_id     the client's hash id
     $friendly      display name
     $friendly_id   row id, for the rename form
     $client_url    the URL a display points at
     $led_blink     whether LED support is on
     $led_selected  currently selected LED group
     $links         [{id, url, label, refresh}] - label names the RSS feed or custom
                    message when the URL points back at this UNS install
     $refresh       default refresh for newly added URLs
     $saved_lists   [{id, name, date, urls_raw, entries:[{url, refresh}]}]
     $archives      same shape, per-client archived lists

   urls_raw is posted straight back to the restore handler, which expects the stored
   "url~refresh|url~refresh" form. *}
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
                function expandcontract(tbodyid,ClickIcon)
                {
                        if (document.getElementById(ClickIcon).innerHTML == "+")
                        {
                                document.getElementById(tbodyid).style.display = "";
                                document.getElementById(ClickIcon).innerHTML = "-";
                        }else{
                                document.getElementById(tbodyid).style.display = "none";
                                document.getElementById(ClickIcon).innerHTML = "+";
                        }
                }
                </script>

                <table border="1px" align="center">
                    <tr valign="center" class="client_table_head">
                        <td>Client Name:</td>
                        <td>
                            <table width="100%">
                                <tr>
                                    <td width="80%">
                                    <br />
                                        <form name="client_rename" action="?func=rename_client&amp;client={$client_id}" method="POST">
                                            <input type="text" name="client_name" style="width:400px;" value="{$friendly}"/>
                                            <input type="hidden" name="client_id" value="{$friendly_id}"/>
                                            <input type="submit" value="Rename"/>
                                        </form>
                                    </td>
                                    <td>
{if $led_blink}
                                        LED Group:<br/>
                                        <form name="client_led" action="?func=client_led_set" method="POST">
                                            <input type="hidden" name="cl_id" value="{$client_id}"/>
                                            <select name="cl_led_id" onchange='this.form.submit()'>
{for $i=1 to 6}
                                                <option value="{$i}"{if $led_selected == $i} selected='yes'{/if}>LED {$i}</option>
{/for}
                                            </select>
                                        </form>
{/if}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr class="client_table_body">
                        <td>Client URL:</td>
                        <td><a class="links" href="{$client_url}" target="_blank">{$client_url}</a></td>
                    </tr>
                </table>
                <hr />

                <form name="client_edit" action="?func=edit_urls&amp;client={$client_id}&amp;cl_func=edit_proc" method="POST">
                <table border="1px" width="100%">
                    <tr class="client_table_head"><th colspan="4">Messages</th></tr>
                    <tr class="client_table_head">
                        <th>URL</th><th>Set Refresh</th><th width="120px">Select</th>
                    </tr>
{if $links|@count == 0}
                    <tr class="client_table_body">
                        <td align="center" colspan="4">There are no URLs added yet.</td>
                    </tr>
{else}
{foreach $links as $l}
                    <tr class="client_table_body">
                        <td>
                            <a class="links" href="{$l.url}" target="_blank">{$l.url}</a>{if $l.label != ''} ({$l.label}){/if}
                        </td>
                        <td align="center">
                            <input type='text' style="width:45px;" name="refresh_time[]" value='{$l.refresh}'>
                            <input type="hidden" name="URLid[]" value="{$l.id}">
                        </td>
                        <th><input type="checkbox" name="urls[]" value="{$l.id}"></th>
                    </tr>
{/foreach}
{/if}
                    <tr class="client_table_tail">
                        <td align="center">
                            <input type='submit' name="copy2" value='Copy'>
                            <input type='submit' name="save_list" value='Save To List'>
                            <input type='submit' name="remove" value='Remove'>
                        </td>
                        <td align="center">
                            <input type='submit' name="refresh" value='Set all'>
                        </td>
                        <td align="center">
                            <input type="button" onclick="SetAllCheckBoxes('client_edit', 'urls[]', true);" value="Check">
                            <input type="button" onclick="SetAllCheckBoxes('client_edit', 'urls[]', false);" value="Uncheck">
                        </td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="4">
                            <form name="save_new" action="?func=edit_urls&amp;client={$client_id}&amp;cl_func=add_url_batch" method="POST">
                            <table style="width: 100%">
                                <tr>
                                    <td style="width: 200px" valign="center">URLs:</td>
                                    <td>
                                        <textarea name="URLS" rows="10" style="border:1px; solid #999999; width:90%; margin:5px 0; padding:3px;">http://</textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <td valign="center">Refresh Times for all:</td>
                                    <td><input type="text" name="refresh" value="{$refresh}"></td>
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
                <hr />

                {* Saved lists and archives render identically apart from their remove
                   action, so they share one include. *}
                {include file="partials/url_list_table.tpl"
                         heading="Saved Lists" rows=$saved_lists client_id=$client_id
                         id_prefix="Saved" remove_action="?func=edit_urls&amp;client=`$client_id`&amp;cl_func=remove"}
                <hr />
                {include file="partials/url_list_table.tpl"
                         heading="Clients Archived Links" rows=$archives client_id=$client_id
                         id_prefix="Arc" remove_action="?func=rm_arc_urls&amp;client=`$client_id`"}

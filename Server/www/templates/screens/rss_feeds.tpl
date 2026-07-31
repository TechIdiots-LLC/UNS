{* RSS feeds: an editable list plus an add form. Same shape as c_messages.

     $feeds     [{id, name, url, maxlines, feed_url}]
     $maxlines_default  prefilled "Max Lines" for a new feed *}
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <form name="save_new" action="?func=rss_feeds&amp;mode=edit_rss" method="POST">
                        <th colspan="6">RSS Feeds</th>
                    </tr>
                    <tr class="client_table_head">
                        <th colspan="6"><input type='submit' name="update_rss" value="Update All Feeds"></th>
                    </tr>
                    <tr class="client_table_head">
                        <th>+/-</th><th>Name</th><th>Max lines</th><th>RSS Feed URL</th><th>Options</th>
                    </tr>
{if $feeds|@count == 0}
                    <tr>
                        <td align="center" colspan="5">There are no RSS Feeds yet.</td>
                    </tr>
{else}
{foreach $feeds as $f}
                    <tr class="client_table_body">
                        <td onclick="expandcontract('mesgRow{$f@index}','mesgClickIcon{$f@index}')"
                            id="mesgClickIcon{$f@index}" style="cursor: pointer; cursor: hand;">+</td>
                        <td style="width:25%;">
                            <input type="hidden" name="id[]" value="{$f.id}"/>
                            <input type="text" name="name[]" style="width:90%;" value="{$f.name}"/>
                        </td>
                        <td>
                            <input type="text" name="maxlines[]" style="width:45px;" value="{$f.maxlines}"/>
                        </td>
                        <td>
                            <a class="links" href="{$f.feed_url}" target="_blank">{$f.feed_url}</a>
                        </td>
                        <td align="center">
                            <input type="checkbox" name="remove_[]" value="{$f.id}"/>
                        </td>
                    </tr>
                    <tbody id="mesgRow{$f@index}" style="display:none">
                    <tr>
                        <td colspan="6">
                            <input type="text" name="body[]" style="width:100%" value="{$f.url}" />
                            <br />
                            <br />
                        </td>
                    </tr>
                    </tbody>
{/foreach}
{/if}
                    <tr class="client_table_tail">
                        <td align="center" colspan="4"></td>
                        <td align="center">
                            <table width="100%">
                                <tr>
                                    <td align="center">
                                        <input type='submit' name="remove" value='Remove'>
                                    </td>
                                    <td align="center">
                                        <input type="button" onclick="SetAllCheckBoxes('save_new', 'remove_[]', true);" value="Check"><br />
                                        <input type="button" onclick="SetAllCheckBoxes('save_new', 'remove_[]', false);" value="Uncheck">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="6" align="center">
                            <form name="save_new1" action="?func=rss_feeds&amp;mode=add_rss" method="POST">
                            <table>
                                <tr>
                                    <td valign="center">Name:</td>
                                    <td><input type="text" name="name_n" style="width:400px;" value=""></td>
                                </tr>
                                <tr>
                                    <td valign="center">RSS URL:</td>
                                    <td><input type="text" name="url_n" style="width:400px;" value="http://"></td>
                                </tr>
                                <tr>
                                    <td valign="center">Max Lines:</td>
                                    <td><input type="text" name="maxlines_n" style="width:45px" value="{$maxlines_default}"></td>
                                </tr>
                                <tr>
                                    <td colspan="2" align="center"><input type='submit' value='Add RSS'></td>
                                </tr>
                            </table>
                            </form>
                        </td>
                    </tr>
                </table>

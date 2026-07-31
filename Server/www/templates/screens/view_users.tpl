{* User permissions screen.

   Everything here is driven by data prepared in admin/index.php:

     $ldap            whether LDAP mode is on, which swaps the Domain / Password column
     $admin_user      name of the built-in admin account
     $builtin_off     true when the built-in admin is disabled
     $builtin_id      internal_users id of the built-in admin, for the password reset
     $users           the other accounts, each with:
                        id, username, domain, internal_id, has_password
                        perms - list of {set, field, label, allowed} toggle buttons

   The six permission buttons per user used to be six near-identical blocks of
   hand-written form markup; they are one loop now. *}
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th>Username</th>
                        <th>{if $ldap}Domain{else}Password{/if}</th>
                        <th>Permissions</th>
                        <th>Options</th>
                    </tr>

                    {* The built-in admin gets its own row: it has no editable permissions
                       and must never be removable, but hiding it made the account
                       invisible on the page that manages users. *}
                    <tr class="client_table_body">
                        <td align="Center">
                            <b>{$admin_user}</b>
                            <br /><font size="1">built-in admin</font>
                        </td>
                        <td align="Center"><font size="1">set at install; use "Reset Admin Password" below</font></td>
                        <td align="Center">Full access</td>
                        <td align="Center">
                            {if $builtin_off}<font color="red">Disabled</font>{else}<font color="green">Enabled</font>{/if}
                            <br /><font size="1">use the button below to change</font>
                        </td>
                    </tr>

{if $users|@count == 0}
                    <tr class="client_table_body">
                        <td colspan="6" align="center">There are no Users, lets add some</td>
                    </tr>
{else}
{foreach $users as $u}
                    <tr class="client_table_body">
                        <td align="Center">{$u.username}</td>
{if $u.has_password}
                        <td align="Center">
                            <form action="?func=edit_user&amp;set=reset_pwd" method="POST">
                                <input type="hidden" name="id" value="{$u.internal_id}"/>
                                <input type="password" name="password" value=""/>
                                <input type="submit" value="Reset Password" />
                            </form>
                        </td>
{else}
                        <td align="Center">{$u.domain}</td>
{/if}
                        <td width="500px">
                            <table>
                                <tr>
{foreach $u.perms as $p}
                                    <td align="center">
                                        <form action="?func=edit_user&amp;set={$p.set}" method="POST">
                                            <input type="hidden" name="id" value="{$u.id}"/>
                                            <input type="hidden" name="{$p.field}" value="{if $p.allowed}0{else}1{/if}"/>
                                            <input type="submit" value="{if $p.allowed}Deny{else}Allow{/if} {$p.label}" />
                                        </form>
                                    </td>
{if $p@iteration is div by 3 && !$p@last}
                                </tr>
                                <tr>
{/if}
{/foreach}
                                </tr>
                            </table>
                        </td>
                        <td align="Center">
                            <form action="?func=remove_user" method="POST">
                                <input type="hidden" name="id" value="{$u.id}"/>
                                <input type="submit" value="Remove" />
                            </form>
                        </td>
                    </tr>
{/foreach}
{/if}

                    <tr class="client_table_tail">
                        <td colspan="6" align="Center">
                            <br />
                            <form name="client_add" action="?func=add_user" method="POST">
                            <table border="1px">
                                <tr class="client_table_body">
                                    <td>Username:</td>
                                    <td><input type="text" name="user_N" /></td>
                                </tr>
{if $ldap}
                                <tr class="client_table_body">
                                    <td>Domain:</td>
                                    <td><input type="text" name="domain_N" /></td>
                                </tr>
{else}
                                <tr class="client_table_body">
                                    <td>Password:</td>
                                    <td>
                                        <input type="hidden" name="internal_user" value="internal_user" />
                                        <input name="pwd_N" type="password" />
                                    </td>
                                </tr>
{/if}
                                <tr>
                                    <td colspan="2" align="center" class="client_table_body">
                                        <input type="submit" value="Add User" />
                                    </td>
                                </tr>
                            </table>
                            </form>

                            <table border="1px">
                                <tr class="client_table_body">
                                    <td align="Center">
                                        <form action="?func=edit_user&amp;set=reset_pwd" method="POST">
                                            <input type="hidden" name="id" value="{$builtin_id}" />
                                            <input type="password" name="password" value="" />
                                            <input type="submit" value="Reset Admin Password" />
                                        </form>
                                    </td>
                                    <td>
                                        {* built_in_admin is a "disabled" flag: 1 means the built-in
                                           account cannot log in. Label the button with the action it
                                           performs, not the flag - reading it straight from the flag
                                           made it offer "Enable" while the account was working. *}
                                        <form action="?func=toggle_builtin" method="POST">
                                            <input type="hidden" name="toggle_admin" value="{if $builtin_off}0{else}1{/if}"/>
                                            <input type="submit" value="{if $builtin_off}Enable{else}Disable{/if} Built in Admin" />
                                        </form>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

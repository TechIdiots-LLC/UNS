{* Opening furniture for the admin panel: the side bar of links, and the permission bar
   across the top of the main cell.

   Both used to be built as arrays of HTML strings in PHP ($side_bar[] / $nav_bar[]),
   one near-identical if/else per permission. They are now driven by data:

     $side_links  list of {href, text} for the permissions this user actually has
     $nav_items   list of {label, allowed} for every permission, allowed or not
     $username    used for the logout link

   This partial deliberately leaves its table open - the screen content follows, and
   partials/panel_close.tpl closes it. *}
    <table border="1px" width="100%">
        <tr>
            <td class="side_bar" valign="top" width="16%">
{if $side_links|@count == 0}
                <p>No Permissions :-(</p>
{else}
{foreach $side_links as $link}
                <p><a href="{$link.href}" class="side_links">{$link.text}</a></p>
{/foreach}
{/if}
                <p><a href="?func=logout" class="side_links">Logout ({$username})</a></p>
            </td>
            <td valign="top" class="main_cell">
                <table border="1px" width="100%">
                    <tr class="nav_bar">
{foreach $nav_items as $item}
                        <td align="center" class="navtd">{$item.label}: <br /><font color="{if $item.allowed}lawngreen{else}red{/if}">{if $item.allowed}Allowed{else}Denied{/if}</font></td>
{/foreach}
                    </tr>
                </table>

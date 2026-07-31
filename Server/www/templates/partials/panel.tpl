{* The admin panel: side bar, permission bar, and the screen content inside them.

   Driven by data rather than HTML built in PHP:
     $side_links  list of {href, text} for the permissions this user has
     $nav_items   list of {label, allowed} for every permission, allowed or not
     $username    for the logout link
     $screen      the current screen's markup, captured with output buffering

   $screen is generated markup rather than user input, so it is printed with
   "nofilter". As individual screens get their own templates this shrinks away. *}
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
{$screen nofilter}
            </td>
        </tr>
    </table>

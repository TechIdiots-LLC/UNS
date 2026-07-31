{* Admin login form.

   Deliberately keeps the footer off, matching the behaviour before this was a
   template: login_form() used to emit its own minimal page and exit. *}
{extends file="layout.tpl"}

{block name="title"}UNS Admin Panel{/block}

{block name="footer"}{/block}

{block name="content"}
{if $message != ''}        <p align="center" style="color:red;">{$message}</p>
{/if}
    <form method="POST" action="?login=1">
    <table class="navtd" align="center" border="1px" style="color:000; width:30%;">
        <tr align="center" class="client_table_head">
            <td>
                USERNAME:<br /><font size="1">(domain\user)</font>
            </td>
            <td>
                <input type="text" style="width:400px;" name="user">
            </td>
        </tr><!-- username -->
        <tr align="center" class="client_table_body">
            <td>
                PASSWORD:
            </td>
            <td>
                <input type="password" name="pass" style="width:400px;">
            </td>
        </tr><!-- password -->
        <tr align="center" class="client_table_tail">
            <td colspan="2" align="center">
                <input type="submit" value="Login" name="B1">
            </td>
        </tr><!-- submit -->
    </table>
    </form>
{/block}

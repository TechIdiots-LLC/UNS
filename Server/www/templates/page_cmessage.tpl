{* Custom message page shown on client displays.

   Replaces the $template_head_cmsg / $template_foot_cmsg strings that used to live in
   configs/vars.php. The message body is authored as HTML in the admin panel, so it is
   printed unescaped with "nofilter" - the same as before this was a template. *}
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>{$uns_title|default:'Powered by UNS'}</title>
        <link rel="stylesheet" href="../configs/cmsg_styles.css">
    </head>
    <body style="background-color: #C0C0C0">
        <table style="width: 80%; height: 100%;" align="center">
            <tr>
                <td class="cmsgheader" style="height: 67px">
                    <img alt="Logo" src="../html/logo.png" width="462" height="70">
                </td>
            </tr>
            <tr class="InfoCell">
                <td valign="top"><br>
                    <table style="width: 80%" align="center">
                        <tr>
                            <td>
{$body nofilter}
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>

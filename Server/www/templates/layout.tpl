{* Base page shell for the admin side.

   Child templates override the blocks they care about:
     title       - contents of <title>
     stylesheet  - href of the stylesheet link
     body_class  - class attribute on <body>
     content     - the page itself
     footer      - defaults to the standard UNS footer; override with an empty
                   block to leave it off, as the login page does. *}
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>{block name="title"}{$uns_title|default:'URL Notification System'}{/block}</title>
        <link rel="stylesheet" href="{block name="stylesheet"}../configs/styles.css{/block}">
    </head>
    <body class="{block name="body_class"}main_body{/block}">
{block name="content"}{/block}
{block name="footer"}{include file="partials/footer.tpl"}{/block}
    </body>
</html>

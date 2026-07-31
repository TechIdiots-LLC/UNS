{* RSS feed page shown on client displays.

   Replaces the $template_head_rss / $template_foot_rss strings that used to live in
   configs/vars.php as HTML embedded in a PHP config file. The feed body is built by
   gen_rss() and passed in already marked up, so it is printed unescaped with "nofilter";
   everything else goes through Smarty's escaping. *}
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>{$uns_title|default:'Powered by UNS'}</title>
        <link rel="stylesheet" href="../configs/rss_styles.css">
    </head>
    <body class="body">
{$body nofilter}
    </body>
</html>

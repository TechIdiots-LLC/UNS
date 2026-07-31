{* Standard admin footer: version and attribution.

   $uns_version comes from uns_smarty(); $footer_date is the release month, which the
   admin panel derives from the modification time of its own index.php. *}
        <div align="center">
            <font size="1">
                Powered by <a class="links" href="http://uns.techidiots.net/ver.htm#1">UNS v{$uns_version}</a><br />
                ( {$footer_date|default:''} ) Phillip Ferland / Random Intervals,
                Andrew Calcutt / TechIdiots LLC
            </font>
        </div>

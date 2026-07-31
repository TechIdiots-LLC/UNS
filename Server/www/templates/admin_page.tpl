{* The logged-in admin page.

   $content is the panel body. It is still assembled by PHP that echoes markup, so it
   is captured with output buffering and printed with "nofilter" - it is generated
   markup, not user input. As individual screens move to their own templates this
   passthrough shrinks and eventually goes away. *}
{extends file="layout.tpl"}

{block name="title"}UNS Admin Panel{/block}

{block name="content"}{$content nofilter}{/block}

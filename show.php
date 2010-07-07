<?php
$url_id = substr($_SERVER['PHP_SELF'], strrpos($_SERVER['PHP_SELF'], '/')+1);
ob_start('ob_gzhandler');
echo file_get_contents('cache/'.$url_id.'/index.html');

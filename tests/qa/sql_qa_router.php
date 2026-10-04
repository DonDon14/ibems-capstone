<?php
// Local web acceptance only; production front controller and clock stay unchanged.
$public=realpath(dirname(__DIR__,2).'/public');
$asset=realpath($public.'/'.ltrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/'));
if($asset && str_starts_with($asset,$public.DIRECTORY_SEPARATOR) && is_file($asset))return false;
require __DIR__.'/sql_month_support.php';
require $public.'/index.php';

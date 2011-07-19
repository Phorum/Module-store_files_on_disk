<?php
// if we are running in the webserver, bail out
if (isset($_SERVER["REMOTE_ADDR"])) {
    echo "This script cannot be run from a browser.";
    return;
}

ini_set('error_reporting', E_ALL);
ini_set('display_errors', TRUE);

define('phorum_page', 'convert_file_storage');

chdir(dirname(__FILE__) . "/../../webroot");
require_once './common.php';
require_once './include/api/file.php';

if (! ini_get('safe_mode')) {
    set_time_limit(0);
    ini_set("memory_limit","64M");
}

print "\nConverting database file storage to disk based storage ...\n";

$files = phorum_api_file_list();

$count_total = count($files);
$size = strlen($count_total);
$count = 0;
foreach ($files as $file) {

    // Retrieving is is enough to get the file converted, because the
    // module has an on-the-fly conversion mechanism implemented.
    phorum_api_file_retrieve(
        $file['file_id'],
        PHORUM_FLAG_GET | PHORUM_FLAG_IGNORE_PERMS
    );

    $count ++;

    $perc = floor(($count/$count_total)*100);
    $barlen = floor(20*($perc/100));
    $bar = "[";
    $bar .= str_repeat("=", $barlen);
    $bar .= str_repeat(" ", (20-$barlen));
    $bar .= "]";
    printf("converting %{$size}d / %{$size}d  %s (%d%%)\r",
           $count, $count_total, $bar, $perc);
}

print "\n\n";

?>

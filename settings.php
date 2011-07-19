<?php

if (!defined("PHORUM_ADMIN")) return;

// save settings
if (isset($_POST['path']))
{
    $path  = trim($_POST['path']);
    $PHORUM["mod_store_files_on_disk"] = array('path' => $path);

    $error = FALSE;

    // Check if a path was entered.
    if ($path == '')
    {
        phorum_admin_error(
            "No path was entered. Please enter the path that has to be " .
            "used for storing Phorum files."
        );
        $error = TRUE;
    }

    // Check if the storage path exists.
    elseif (!file_exists($path) || !is_dir($path)) {
        phorum_admin_error(
            "The path that you entered seems invalid. " .
            "It does not exist or is not a directory."
        );
        $error = TRUE;
    }

    // Check if Phorum can write to the storage path.
    else {
        ini_set('track_errors', 1);
        $dummy = "$path/mod_store_files_on_disk_dummy";
        @rmdir($dummy);
        if (@mkdir($dummy) == FALSE) {
            phorum_admin_error(
                "The path that you entered seems invalid. " .
                "Phorum is unable to create subdirectories beneath it. " .
                "The error returned from the system was: " .
                $php_errormsg
            );
            $error = TRUE;
        }
        @rmdir($dummy);
    }

    // If all was okay, then save the settings.
    if (!$error)
    {
        phorum_db_update_settings(array(
            "mod_store_files_on_disk" => $PHORUM["mod_store_files_on_disk"]
        ));

        phorum_admin_okmsg("Settings updated.");
    }
}

include_once "./include/admin/PhorumInputForm.php";
$frm = new PhorumInputForm ("", "post", "Save");
$frm->hidden("module", "modsettings");
$frm->hidden("mod", "store_files_on_disk");

$frm->addbreak("Edit settings for the \"Store files on disk\" module");
$frm->addmessage(
    "Please, enter the filesystem path (either relative or absolute)
     of the directory that you want to use for storing the Phorum files.
     This path does not need to be below the webserver's document root
     (it is even preferable to have it outside the document root).
     The webserver must be allowed to write to the directory.<br/>
     <br/>
     <strong>Important:</strong> Do not change this path on a running
     system, unless you know what you are doing."
);
$frm->addrow("Path of the file storage directory: ", $frm->text_box("path",$PHORUM["mod_store_files_on_disk"]["path"], 50));
$frm->show();

?>

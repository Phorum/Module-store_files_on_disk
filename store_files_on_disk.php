<?php

if (!defined("PHORUM")) return;

// A special call flag for the phorum_api_file_retrieve() call, to
// prevent infinite recursive calling.
define('PHORUM_FSATTACHMENTS_RETRIEVE_CALL',1024);

function phorum_mod_store_files_on_disk_store($file)
{
    list ($filepath, $oldfilepaths) =
        store_files_on_disk_build_filepath($file);

    $swapfile = $filepath . ".swap";
    $fileparts = explode(DIRECTORY_SEPARATOR,$filepath);
    array_pop($fileparts);
    $dirpart = implode(DIRECTORY_SEPARATOR,$fileparts);

    // Store the file on disk. If this succeeds, then the file data
    // in the file array is emptied, so it will not be stored in the
    // database. If storing the file fails for some reason, then default
    // db file storage will still be used. The on-the-fly conversion
    // mechanism in this module will convert the file once writing
    // succeeds again.

    @ini_set('track_errors', 1);
    $php_errormsg = NULL;
    $error = NULL;

    if (!file_exists($dirpart)) {
        if (store_files_on_disk_mkdir($dirpart) == FALSE) {
            $error = "Cannot create file storage directory \"$dirpart\"";
        }
    }
    if ($error === NULL) {
        $fp = @fopen($swapfile, "w");
        if ($fp !== FALSE) {
            fputs($fp, $file['file_data']);
            if (@fclose($fp) !== FALSE) {
                $verify = @file_get_contents($swapfile);
                if ($verify === $file['file_data']) {
                    if (rename($swapfile, $filepath) !== FALSE) {
                        $file['file_data'] = '';
                    } else {
                        $error = "Moving swapfile \"$swapfile\" to its " .
                                 "final location \"$filepath\" failed";
                    }
                } else {
                    $error = "Verifying data from file \"$swapfile\" failed";
                }
            } else {
                $error = "Error on closing file \"$swapfile\" (disk full?)";
            }
        } else {
            $error = "Unable to open file \"$swapfile\" for writing";
        }
    }

    // Trigger an error if the writing failed.
    if ($error !== NULL)
    {
        if (!empty($php_errormsg)) {
            $error .= " ($php_errormsg)";
        }

        if (file_exists($swapfile)) @unlink($swapfile);

        trigger_error(
            "store_files_on_disk module: failed to write the data for " .
            "file_id {$file['file_id']} to the file system. The data " .
            "will be kept in the database for now. The module will try to " .
            "convert it from database to file storage, the next time that " .
            "this file is requested. The error message is: $error",
            E_USER_WARNING
        );
    }

    return $file;
}

function phorum_mod_store_files_on_disk_retrieve($input)
{
    $PHORUM=$GLOBALS['PHORUM'];

    list($file,$flags) = $input;

    // Prevent infinite recursive calls when we are converting a file
    // from database to disk storage.
    if (!($flags & PHORUM_FSATTACHMENTS_RETRIEVE_CALL))
    {
        list ($filepath, $oldfilepaths) =
            store_files_on_disk_build_filepath($file);

        // If the storage file is available, then fill the file object
        // with its contents.
        if (file_exists($filepath)) {
            $file['file_data'] = file_get_contents($filepath);
        }
        // If the storage file is absent, then we are most probably handling
        // a file that was uploaded before this module was active. In that
        // case, we have to retrieve the file data from the database and
        // store it in a file.
        else
        {
            // Retrieve the file data from the database.
            $file_retrieved = phorum_api_file_retrieve(
                $file['file_id'],
                PHORUM_FLAG_GET |
                PHORUM_FLAG_IGNORE_PERMS |
                PHORUM_FSATTACHMENTS_RETRIEVE_CALL
            );

            // If an old style storage path is available and we didn't manage
            // to retrieve file data from the database, then we need to
            // convert the old file to store it in its new location.
            $delete_old_file = FALSE;
            if (empty($file_retrieved['file_data']))
            {
                foreach ($oldfilepaths as $oldfilepath)
                {
                    if (file_exists($oldfilepath)) {
                        $file_retrieved['file_data'] =
                            file_get_contents($oldfilepath);
                        $delete_old_file = $oldfilepath;
                        break;
                    }
                }
            }

            // If file_data is set, then we have to convert that file_data
            // to file storage. We just have to store the file again through
            // the API to accomplish this.
            if (!empty($file_retrieved['file_data']))
            {
                phorum_api_file_store($file_retrieved);

                $file['file_data'] = $file_retrieved['file_data'];

                // Cleanup the old style storage file and its parent
                // directories as far as possible. This will only work
                // for empty directories, so there's no risk in deleting
                // existing stored files by deleting these directories.
                if ($delete_old_file) {
                    unlink($delete_old_file);
                    $parent = dirname($delete_old_file);
                    while (@rmdir($parent)) $parent = dirname($parent);
                }
            }
        }
    }

    return array($file,$flags);
}

function phorum_mod_store_files_on_disk_delete($file_id)
{
    // Can't use API here, because moderation.php first deletes the
    // message and then the attachments. The File API will after that
    // no longer retrieve the file, because the linked message is
    // not available anymore.
    $file = phorum_db_file_get($file_id);
    if (empty($file)) return $file_id;

    list ($filepath, $oldfilepaths) =
        store_files_on_disk_build_filepath($file);

    if (file_exists($filepath)) {
        unlink($filepath);
        $parent = dirname($filepath);
        while (@rmdir($parent)) $parent = dirname($parent);
    }
    foreach ($oldfilepaths as $oldfilepath) {
        if (file_exists($oldfilepath)) {
            unlink($oldfilepath);
            $parent = dirname($oldfilepath);
            while (@rmdir($parent)) $parent = dirname($parent);
        }
    }

    return $file_id;
}

/**
 * Build the filesystem path that has to be used for storing a given file_id.
 *
 * This function will return two paths.
 *
 * - The file path that is currently in use
 * - A deprecated file path that was used in older versions of this module
 *
 * The old file path is used for conversion purposes.
 *
 * @param array $file
 *     An array containing file data.
 *
 * @return array
 *     An array containing two elements: the file path and the old style
 *     file path.
 */
function store_files_on_disk_build_filepath($file)
{
    if (empty($GLOBALS["PHORUM"]["mod_store_files_on_disk"]["path"])) {
        trigger_error(
            "Store files on disk: the module is active, but no storage " .
            "path was configured! Please configure the module.",
            E_USER_ERROR
        );
    }

    $basedir = $GLOBALS["PHORUM"]["mod_store_files_on_disk"]["path"];
    $md5 = md5($file['file_id']);

    $oldpaths = array();

    // Old path format:
    // This gives us a very good file distribution, but some
    // hosting providers seem to choke on the directory depth when doing
    // backups. Also, some forum admins want to remove old attachments once
    // in a while. This path setup doesn't easily allow to archive old
    // attachments.
    $path_parts_str = wordwrap(substr($md5, 0, 30), 3, DIRECTORY_SEPARATOR, 1);
    $oldpaths[] = $basedir . DIRECTORY_SEPARATOR .
                  $path_parts_str . DIRECTORY_SEPARATOR .
                  $file['file_id'];

    // Old path format:
    // active TZ based, YYYY/MMDD/HH/<file_id>
    // This path is based on strftime(), which is timezone dependent.
    // We switched to gmstrftime() to get rid of this dependency, because
    // changing the server timezone resulted in changed (thus broken)
    // file paths.
    $date_part = strftime('%Y/%m%d/%H', $file['add_datetime']);  
    $oldpaths[] = $basedir . DIRECTORY_SEPARATOR .
                  $date_part . DIRECTORY_SEPARATOR .
                  $file['file_id'];

    // New path format:
    // GMT based, YYYY/MMDD/HH/<file_id>
    $date_part = gmstrftime('%Y/%m%d/%H', $file['add_datetime']);  
    $newpath = $basedir . DIRECTORY_SEPARATOR .
               $date_part . DIRECTORY_SEPARATOR .
               $file['file_id'];

    return array($newpath, $oldpaths);
}

// recursively creates a directory-tree
function store_files_on_disk_mkdir($path)
{
    if (empty($path)) return FALSE;
    if (is_dir($path)) return TRUE;
    if (!store_files_on_disk_mkdir(dirname($path))) return FALSE;
    if (@mkdir($path) === FALSE) return FALSE;
    return TRUE;
}

?>

<?php

/* vim: set expandtab tabstop=4 shiftwidth=4: */
// +------------------------------------------------------------------------------------------------------+
// | PHP version 5                                                                                        |
// +------------------------------------------------------------------------------------------------------+
// | Copyright (C) 2016 Outils-Réseaux (accueil@outils-reseaux.org)                                       |
// +------------------------------------------------------------------------------------------------------+
// | This library is free software; you can redistribute it and/or                                        |
// | modify it under the terms of the GNU Lesser General Public                                           |
// | License as published by the Free Software Foundation; either                                         |
// | version 2.1 of the License, or (at your option) any later version.                                   |
// |                                                                                                      |
// | This library is distributed in the hope that it will be useful,                                      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of                                       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU                                    |
// | Lesser General Public License for more details.                                                      |
// |                                                                                                      |
// | You should have received a copy of the GNU Lesser General Public                                     |
// | License along with this library; if not, write to the Free Software                                  |
// | Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA                            |
// +------------------------------------------------------------------------------------------------------+
//
/*
 * Fichier de traduction en francais de l'extension Ferme
 *
 *@package       ferme
 *@author        Florian Schmitt <mrflos@gmail.com>
 *@copyright     2016 Outils-Réseaux
 */

$GLOBALS['translations'] = array_merge(
    $GLOBALS['translations'],
    [
        'FERME_IMPORT' => 'Import',
        'FERME_RENAME' => 'Rename',
        'FERME_GENERATE_MODEL_FROM_URL' => 'Use an URL to generate a model',
        'FERME_URL_IMPORT_INFO' => 'Key a YesWiki page URL in',
        'FERME_ALL_WIKIS_ADMIN' => 'Wikis management',
        'FERME_UPDATING' => 'Updating ',
        'FERME_WIKI' => 'Wiki ',
        'FERME_UPDATED' => ' has properly been updated',
        'FERME_FILE' => 'File ',
        'FERME_NOT_FOUND' => ' not found',
        'FERME_ADMIN_REQUIRED' => 'You must be part of @admins group to manage wikis',
        'FERME_REPORT' => 'Report',
        'FERME_INSERTION_ERROR' => 'Error for insertion n°{num} from file {file} : {errorMsg}',
        'FERME_INSERTION' => 'Insertion n°{num} : {nbRows} line(s) affected',

        'FERME_SELECT_ALL' => 'Select all',
        'FERME_UPGRADE_SELECTED_WIKIS' => 'Upgrade selected wikis',
        'FERME_UPGRADE_SELECTED_INTRO' => 'The following wikis will be upgraded one by one. The process stops if any error occurs.',
        'FERME_UPGRADE_CLOSE' => 'Close',
        'FERME_UPGRADE_PENDING' => 'Pending',
        'FERME_UPGRADING_STATUS' => 'In progress...',
        'FERME_UPGRADE_SUCCESS' => 'Success',
        'FERME_UPGRADE_ERROR' => 'Error',
        'FERME_DELETE_SELECTED_WIKIS' => 'Delete selected wikis',
        'FERME_DELETE_SELECTED_WARNING' => 'This cannot be undone. The following wikis and all their data will be permanently deleted.',
        'FERME_DELETE_CONFIRM_BTN' => 'Confirm deletion',
        'FERME_DELETING_STATUS' => 'Deleting...',
        'FERME_DELETE_SUCCESS' => 'Deleted',
        'FERME_DELETE_ERROR' => 'Error',

        'FERME_CANCEL' => 'Cancel',
        'FERME_SEARCH_SCANNING' => 'Scanning server, this may take a moment...',
        'FERME_SEARCH_ALREADY_IN_BAZAR' => 'Already in farm',
        'FERME_SEARCH_IMPORTED_STATUS' => 'Imported',
        'FERME_SEARCH_SQL_OK' => 'SQL OK',
        'FERME_SEARCH_SQL_ERROR' => 'SQL error',
        'FERME_SEARCH_TABLES_MISSING' => 'Missing tables',
        'FERME_SEARCH_NETWORK_ERROR' => 'Error during scan',

        // version / admin status labels
        'FERME_VERSION_DIFFERENT' => 'main version differs from source wiki',
        'FERME_VERSION_UP_TO_DATE' => 'up to date with source wiki',
        'FERME_UPDATE_TO' => 'Update to',
        'FERME_ADMIN_PRESENT' => 'present',
        'FERME_ADMIN_ABSENT' => 'absent',
        'FERME_ADMIN_ADD_ACCOUNT' => 'add account',
        'FERME_ADMIN_REMOVE_ACCOUNT' => 'remove account',
        'FERME_ADMIN_ADD_SELECTED' => 'Add admin account',
        'FERME_ADMIN_REMOVE_SELECTED' => 'Remove admin account',
        'FERME_ADMIN_ADD_SELECTED_INTRO' => 'The farm super admin account will be created on the following wikis, one by one. On those that already have it, the password is reset to the one in the farm config.',
        'FERME_ADMIN_REMOVE_SELECTED_INTRO' => 'The farm super admin account will be deleted from the following wikis, one by one.',
        'FERME_ADMIN_ADDED_STATUS' => 'Added',
        'FERME_ADMIN_REMOVED_STATUS' => 'Removed',
        'FERME_ADMIN_ERROR_STATUS' => 'Error',

        // for edit config
        'EDIT_CONFIG_HINT_BAZAR_FARM_ID' => 'Farm form\'s id',
        'EDIT_CONFIG_GROUP_FERME' => 'Farm',

        // command line
        'FERME_CLI_NOT_A_DIRECTORY' => 'Not a directory:',
        'FERME_CLI_MASTER_EXCLUDED' => 'The farm master cannot be a target.',
        'FERME_CLI_NO_WIKI_IN' => 'No wakka.config.php in',
        'FERME_CLI_NO_CONFIG_FILE' => 'Configuration file not found:',
        'FERME_CLI_EMPTY_CONFIG_FILE' => 'Empty or unreadable configuration:',
        'FERME_CLI_CANNOT_WRITE' => 'Cannot write:',
        'FERME_CLI_CANNOT_CREATE_DIR' => 'Cannot create directory:',
        'FERME_CLI_NO_SMTP_IN_MASTER' => 'The farm does not send its mail over SMTP (contact_mail_func and contact_smtp_host), nothing to push.',
        'FERME_CLI_NO_WIKI_FOUND' => 'No yeswiki found under',
        'FERME_CLI_FAILED_WIKIS' => 'Failed wikis:',
        'FERME_CLI_DRY_RUN' => 'dry-run',
        'FERME_CLI_DRY_RUN_NOTHING_WRITTEN' => 'dry-run, nothing written',
        'FERME_CLI_OPT_PATH' => 'Path to scan (default: the farm root)',
        'FERME_CLI_OPT_DEPTH' => 'Depth of the scan',
        'FERME_CLI_OPT_WIKI' => 'Act on this wiki folder only',
        'FERME_CLI_OPT_DRY_RUN' => 'Show what would be done without writing anything',

        'EDIT_CONFIG_HINT_YESWIKI-FARM-ADMIN-EMAIL' => 'Email used on imported entries when the wiki has no admin with an email',
        'EDIT_CONFIG_HINT_YESWIKI-FARM-ARCHIVE-URL' => 'Url of a YesWiki zip release, otherwise the master wiki files are used',
        'EDIT_CONFIG_HINT_YESWIKI-FARM-BACKUP-DIR' => 'Backup folder, relative to the master wiki',

        'FERME_IMPORTED_REFERENT' => 'To be filled in (imported)',
        'FERME_CLI_LIST_DESCRIPTION' => 'List the wikis found on the server and their state.',
        'FERME_CLI_LIST_HELP' => "Scans a folder for wakka.config.php, then for each wiki tells whether the farm knows it, whether its database answers and whether all its tables are there.\nWith --import, an entry is created in the farm form for the wikis that have none.",
        'FERME_CLI_OPT_FORMAT' => 'Output format: table, json or csv',
        'FERME_CLI_OPT_IMPORT' => 'Create an entry for the wikis missing from the farm form',
        'FERME_CLI_OPT_EMAIL' => 'Email for imported entries, when the wiki has no admin with an email',
        'FERME_CLI_UNKNOWN_FORMAT' => 'Unknown format:',
        'FERME_CLI_COL_FOLDER' => 'Folder',
        'FERME_CLI_COL_URL' => 'Url',
        'FERME_CLI_COL_VERSION' => 'Version',
        'FERME_CLI_COL_BAZAR' => 'Entry',
        'FERME_CLI_COL_DATABASE' => 'Database',
        'FERME_CLI_COL_ADMIN' => 'Admin',
        'FERME_CLI_YES' => 'yes',
        'FERME_CLI_NO' => 'no',
        'FERME_CLI_DB_OK' => 'ok',
        'FERME_CLI_DB_MISSING_TABLES' => 'missing tables:',
        'FERME_CLI_DB_ERROR' => 'error',
        'FERME_CLI_LIST_SUMMARY' => 'Wikis on the server',
        'FERME_CLI_WIKIS_FOUND' => 'Wikis found',
        'FERME_CLI_IN_BAZAR' => 'With an entry',
        'FERME_CLI_NOT_IN_BAZAR' => 'Without an entry',
        'FERME_CLI_IMPORTED' => 'Entries created',
        'FERME_CLI_WOULD_IMPORT' => 'Entries to create',
        'FERME_CLI_DB_FAILURES' => 'Database failures',
        'FERME_CLI_MISSING_CONFIG_KEY' => 'Key missing from wakka.config.php:',

        'FERME_CLI_CONFIG_DESCRIPTION' => 'Set or remove keys in the wakka.config.php of every wiki.',
        'FERME_CLI_CONFIG_HELP' => "Loads the wakka.config.php of each wiki, applies the changes and writes it back in the format YesWiki uses.\nValues are strings, except true, false, null, int:465 and json:{\"a\":1} which keep their type. Nested keys use dots: yeswiki-farm-extra-config.contact_from\nWith --smtp, the farm's own contact_* settings are copied into every wiki, into the yeswiki-farm-extra-config of the wikis that are farms themselves, and into the farm's own so the wikis it creates later inherit them.",
        'FERME_CLI_OPT_SET' => 'key=value to write, repeatable',
        'FERME_CLI_OPT_UNSET' => 'key to remove, repeatable',
        'FERME_CLI_OPT_SMTP' => 'Copy the farm contact_* settings into every wiki',
        'FERME_CLI_OPT_NOBACKUP' => 'Do not copy wakka.config.php to the backup folder before writing it',
        'FERME_CLI_NOTHING_TO_DO' => 'Nothing to do: give --smtp, --set key=value or --unset key',
        'FERME_CLI_BAD_SET' => '--set expects key=value, got',
        'FERME_CLI_NOTHING_TO_CHANGE' => 'nothing to change',
        'FERME_CLI_CONFIG_INTRO' => 'Wikis found to configure:',
        'FERME_CLI_CONFIG_SUMMARY' => 'Configuration',
        'FERME_CLI_CHANGED' => 'Changed',
        'FERME_CLI_UNCHANGED' => 'Unchanged',
        'FERME_CLI_FAILED' => 'Failed',
        'FERME_CLI_MASTER_CONFIG' => 'Farm config',
        'FERME_CLI_BACKUPS_IN' => 'Backups in',

        'FERME_NO_FARM_ADMIN_CONFIGURED' => 'No yeswiki-farm-admin-name or yeswiki-farm-admin-pass in the configuration.',
        'FERME_SUPER_USER_ADDED' => 'Super user added to the wiki',
        'FERME_SUPER_USER_REMOVED' => 'Super user removed from the wiki',
        'FERME_CLI_ADMIN_DESCRIPTION' => 'Create or update a user and its group in every wiki.',
        'FERME_CLI_ADMIN_HELP' => "Connects to each wiki database with that wiki's own credentials, creates the user or resets its password, and adds it to the group asked for.\nPasswords are hashed with bcrypt, or md5 on the wikis whose password column is still too narrow.\nWithout --user, --password and --email, the farm's own yeswiki-farm-admin-* values are used.",
        'FERME_CLI_OPT_USER' => 'User name (default: yeswiki-farm-admin-name)',
        'FERME_CLI_OPT_PASSWORD' => 'Password to set (default: yeswiki-farm-admin-pass)',
        'FERME_CLI_OPT_USER_EMAIL' => 'Email of the user (default: yeswiki-farm-admin-email)',
        'FERME_CLI_OPT_GROUP' => 'Group to add the user to',
        'FERME_CLI_OPT_REMOVE' => 'Take the user out of the group and delete it',
        'FERME_CLI_ADMIN_ADD_INTRO' => 'User and group to set on the wikis found:',
        'FERME_CLI_ADMIN_REMOVE_INTRO' => 'User to remove from the wikis found:',
        'FERME_CLI_ADMIN_SUMMARY' => 'Users',
        'FERME_CLI_BAD_USER_NAME' => 'Not a valid user name (3 to 80 chars, no < > \\ / and not starting with ! # @):',
        'FERME_CLI_EMPTY_PASSWORD' => 'Empty password: give --password or set yeswiki-farm-admin-pass',
        'FERME_CLI_EMPTY_GROUP' => 'Empty group name',
        'FERME_CLI_BAD_EMAIL' => 'Not a valid email:',
        'FERME_CLI_NO_PASSWORD_COLUMN' => 'No password column in table',
        'FERME_CLI_COL_USER' => 'User',
        'FERME_CLI_COL_GROUP' => 'Group',
        'FERME_CLI_STATE_CREATED' => 'created',
        'FERME_CLI_STATE_UPDATED' => 'updated',
        'FERME_CLI_STATE_ADDED' => 'added',
        'FERME_CLI_STATE_MEMBER' => 'already member',
        'FERME_CLI_STATE_REMOVED' => 'removed',
        'FERME_CLI_STATE_ABSENT' => 'absent',
        'FERME_CLI_COUNT_USER_CREATED' => 'Users created',
        'FERME_CLI_COUNT_USER_UPDATED' => 'Users updated',
        'FERME_CLI_COUNT_USER_REMOVED' => 'Users deleted',
        'FERME_CLI_COUNT_USER_ABSENT' => 'Users already absent',
        'FERME_CLI_COUNT_GROUP_CREATED' => 'Groups created',
        'FERME_CLI_COUNT_GROUP_ADDED' => 'Added to the group',
        'FERME_CLI_COUNT_GROUP_MEMBER' => 'Already in the group',
        'FERME_CLI_COUNT_GROUP_REMOVED' => 'Removed from the group',
        'FERME_CLI_COUNT_GROUP_ABSENT' => 'Not in the group',
    ]
);

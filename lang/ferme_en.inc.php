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
        'FERME_SEARCH_HTTP_OK' => 'Page OK',
        'FERME_SEARCH_HTTP_ERROR' => 'Page KO',
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
    ]
);

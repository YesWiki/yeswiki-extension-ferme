<?php
/*vim: set expandtab tabstop=4 shiftwidth=4: */
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
/**
* Fichier de traduction en francais de l'extension Ferme
*
*@package       ferme
*@author        Florian Schmitt <mrflos@gmail.com>
*@copyright     2016 Outils-Réseaux
*/

$GLOBALS['translations'] = array_merge(
    $GLOBALS['translations'],
    array(
    'FERME_IMPORT' => 'Import',
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

    // for edit config
    'EDIT_CONFIG_HINT_BAZAR_FARM_ID' => 'Farm form\'s id',
    'EDIT_CONFIG_GROUP_FERME' => 'Farm',
    )
);

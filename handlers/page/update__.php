<?php
/**
 * Handler called after the 'update' handler. to install the farm database template and create default pages
 * needed ones.
 *
 * @category YesWiki
 * @package  ferme
 * @author   Adrien Cheype <adrien.cheype@gmail.com>
 * @author   Florian Schmitt <mrflos@lilo.org>
 * @license  https://www.gnu.org/licenses/agpl-3.0.en.html AGPL 3.0
 * @link     https://yeswiki.net
 */

// Verification de securite
if (!defined("WIKINI_VERSION")) {
    die("acc&egrave;s direct interdit");
}

/**
 * Constants to define the Oui Non list
 */
!defined('OUINON_LIST_JSON') && define('OUINON_LIST_JSON', '{"label":{"oui":"Oui","non":"Non"},"titre_liste":"Oui Non"}');

/**
 * Constants to define the contents of the LMS forms
 */
!defined('FARM_FORM_NOM') && define('FARM_FORM_NOM', 'Ferme Yeswiki');
!defined('FARM_FORM_DESCRIPTION') && define('FARM_FORM_DESCRIPTION', 'Formulaire de création de yeswikis dans la ferme');
!defined('FARM_FORM_TEMPLATE') && define('FARM_FORM_TEMPLATE', 'texte***bf_titre***Titre du wiki***255***255*** *** ***text***1*** *** *** * *** * *** *** ***
texte***bf_description***Description courte***255***255*** *** ***text***1*** *** *** * *** * *** *** ***
image***bf_image***Image représentative pour le listing des wikis***180***195***180***195***right***1*** ***Votre image sera retaillée et centrée pour s\'adapter à la taille demandée*** * *** * *** *** ***
texte***bf_referent***Nom de la personne référente du wiki***255***255*** *** ***text***1*** *** *** * *** * *** *** ***
champs_mail***bf_mail***Email de la personne référente du wiki*** *** *** ***form*** ***1***1*** *** * *** * *** *** ***
yeswiki***bf_dossier-wiki***Adresse URL du wiki***bf_mail*** *** *** *** ***1*** *** *** *** *** *** ***
labelhtml***<div class="alert alert-info">*** *** ***
labelhtml***Les données personnelles collectées (nom et adresse mail) ne seront pas diffusées. Leur utilisation se limite à un usage interne pour diffuser des informations importantes (cf. <a href="'.$this->href('', 'UtilisationDonnees').'" target="_blank">Politique de gestion des données</a>).<br />Vous pouvez demander la suppression de votre wiki et de vos données en passant par <a href="'.$this->href('', 'Contact').'">le formulaire de contact</a>.*** *** ***
labelhtml***</div>*** *** ***
radio***ListeOuiNon***Je donne mon accord pour que ces données soient collectées*** *** *** *** *** ***1*** *** *** * *** * *** *** ***
');

/**
 * Check if a form exists and if not, add it to the nature table
 * @param $plugin_output_new the buffer in which to write
 * @param $formId the ID of the form
 * @param $formName the name of the form
 * @param $formeDescription the description of the form
 * @param $formTemplate the template which describe the fields of the form
 */
if (!function_exists('checkAndAddForm')) {
    function checkAndAddForm(&$plugin_output_new, $formId, $formName, $formeDescription, $formTemplate)
    {
        // test if the FARM form exists, if not, install it
        $result = $GLOBALS['wiki']->Query('SELECT 1 FROM ' . $GLOBALS['wiki']->config['table_prefix'] . 'nature WHERE bn_id_nature = '
            . $formId . ' LIMIT 1');
        if (mysqli_num_rows($result) == 0) {
            $plugin_output_new .= 'ℹ️ Adding <em>' . $formName . '</em> form into <em>' . $GLOBALS['wiki']->config['table_prefix']
                . 'nature</em> table.<br />';

            $GLOBALS['wiki']->Query('INSERT INTO ' . $GLOBALS['wiki']->config['table_prefix'] . 'nature (`bn_id_nature` ,`bn_ce_i18n` ,'
                . '`bn_label_nature` ,`bn_template` ,`bn_description` ,`bn_sem_context` ,`bn_sem_type` ,`bn_sem_use_template` ,'
                . '`bn_condition`)'
                . ' VALUES (' . $formId . ', \'fr-FR\', \'' . mysqli_real_escape_string($GLOBALS['wiki']->dblink, $formName) . '\', \''
                . mysqli_real_escape_string($GLOBALS['wiki']->dblink, $formTemplate) . '\', \''
                . mysqli_real_escape_string($GLOBALS['wiki']->dblink, $formeDescription) . '\', \'\', \'\', 1, \'\')');

            $plugin_output_new .= '✅ Done !<br />';
        } else {
            $plugin_output_new .= '✅ The <em>' . $formName . '</em> form already exists in the <em>' .
                $GLOBALS['wiki']->config['table_prefix'] . 'nature</em> table.<br />';
        }
    }
}
$output = '';
if ($this->UserIsAdmin()) {
    $output .= '<strong>Extension Ferme</strong><br/>';

    // Structure de répertoire désirée
    $customWikiModelDir = 'custom/wiki-models/';
    if (!is_dir($customWikiModelDir)) {
        if (!mkdir($customWikiModelDir, 0777, true)) {
            die('Folder creation failed...');
        } else {
            $output .= "ℹ️ Creating the folder <em>$customWikiModelDir</em> for the wiki models<br/>✅Done !<br />";
        }
    } else {
        $output .= "✅ The folder <em>$customWikiModelDir</em> for the wiki models exists.<br />";
    }


    // if the OuiNon Lms list doesn't exist, create it
    if (!$this->LoadPage('ListeOuiNon')) {
        $output .= 'ℹ️ Adding the <em>Oui Non</em> list<br />';
        // save the page with the list value
        $this->SavePage('ListeOuiNon', OUINON_LIST_JSON);
        // in case, there is already some triples for 'ListOuinonLms', delete them
        $this->DeleteTriple('ListeOuiNon', 'http://outils-reseaux.org/_vocabulary/type', null);
        // create the triple to specify this page is a list
        $this->InsertTriple('ListeOuiNon', 'http://outils-reseaux.org/_vocabulary/type', 'liste', '', '');
        $output .= '✅ Done !<br />';
    } else {
        $output .= '✅ The <em>Oui Non</em> list already exists.<br />';
    }

    // test if the FARM form exists, if not, install it
    checkAndAddForm($output, $GLOBALS['wiki']->config['bazar_farm_id'], FARM_FORM_NOM, FARM_FORM_DESCRIPTION, FARM_FORM_TEMPLATE);

    // if the PageMenuLms page doesn't exist, create it with a default version
    if (!$this->LoadPage('AdminWikis')) {
        $output .= 'ℹ️ Adding the <em>AdminWikis</em> page<br />';
        $this->SavePage('AdminWikis', '{{grid}}'."\n".
        '{{col size="2"}}{{nav class="nav-admin-wikis nav nav-pills nav-stacked" links="AjouterWiki,AdminWikis, ContactWikis, ModelesWiki" titles="Nouveau wiki, Wikis, Emails usagers, Modèles de wiki"}}{{end elem="col"}}'."\n".
        '{{col size="10"}}'."\n".
        '{{adminwikis}}'."\n".
        '{{end elem="col"}}'."\n".
        '{{end elem="grid"}}');
        $output .= '✅ Done !<br />';
    } else {
        $output .= '✅ The <em>AdminWikis</em> page already exists.<br />';
    }
    $output .= '<hr />';
}

// add the content before footer
$plugin_output_new = str_replace(
    '<!-- end handler /update -->',
    $output.'<!-- end handler /update -->',
    $plugin_output_new
);

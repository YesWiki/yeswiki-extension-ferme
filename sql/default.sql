# YesWiki pages
INSERT INTO `{{prefix}}__pages` (`tag`, `time`, `body`, `body_r`, `owner`, `user`, `latest`, `handler`, `comment_on`) VALUES
('AidE',  now(), '=====Les pages d\'aide=====

	- [[CoursUtilisationYesWiki Cours sur l\'utilisation de YesWiki]]
	- ReglesDeFormatage : résumé des syntaxes permettant la mise en forme du texte.

\"\"<a onclick=\"var iframe = document.getElementById(\'yeswiki-doc\');iframe.src = \'https://yeswiki.net/wakka.php?wiki=DocumentatioN/iframe\';\" class=\"btn btn-default\"><i class=\"glyphicon glyphicon-home\"></i> Accueil de la documentation</a><iframe id=\"yeswiki-doc\" width=\"100%\" height=\"1000\" frameborder=\"0\" class=\"auto-resize\" src=\"https://yeswiki.net/wakka.php?wiki=DocumentatioN/iframe\"></iframe>\"\"
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('BacASable',  now(), ' - si vous cliquez sur \"éditer cette page\"
 - vous pourrez écrire dans cette page comme bon vous semble
 - puis en cliquant sur \"sauver\" vous pourrez enregistrer vos modifications', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('BazaR',  now(), '{{bazar showexportbuttons=\"1\"}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('CoursUtilisationYesWiki',  now(), '======Cours sur l\'utilisation de YesWiki======
====Le principe \"Wiki\"====
Wiki Wiki signifie rapide, en Hawaéen.
==N\'importe qui peut modifier la page==

**Les Wiki sont des dispositifs permettant la modification de pages Web de faéon simple, rapide et interactive.**
YesWiki fait partie de la famille des wiki. Il a la particularité d\'étre trés facile é installer.

=====Mettre du contenu=====
====écrire ou coller du texte====
 - Dans chaque page du site, un double clic sur la page ou un clic sur le lien \"éditer cette page\" en bas de page permet de passer en mode \"édition\".
 - On peut alors écrire ou coller du texte
 - On peut voir un aperéu des modifications ou sauver directement la page modifiée en cliquant sur les boutons en bas de page.

====écrire un commentaire (optionnel)====
Si la configuration de la page permet d\'ajouter des commentaires, on peut cliquer sur : Afficher commentaires/formulaire en bas de chaque page.
Un formulaire apparaitra et vous permettra de rajouter votre commentaire.


=====Mise en forme : Titres et traits=====
--> Voir la page ReglesDeFormatage

====Faire un titre====
======Trés gros titre======
s\'écrit en syntaxe wiki : \"\"======Trés gros titre======\"\"


==Petit titre==
s\'écrit en syntaxe wiki : \"\"==Petit titre==\"\"


//On peut mettre entre 2 et 6 = de chaque coté du titre pour qu\'il soit plus petit ou plus grand//

====Faire un trait de séparation====
Pour faire apparaitre un trait de séparation
----
s\'écrit en syntaxe wiki : \"\"----\"\"

=====Mise en forme : formatage texte=====
====Mettre le texte en gras====
**texte en gras**
s\'écrit en syntaxe wiki : \"\"**texte en gras**\"\"

====Mettre le texte en italique====
//texte en italique//
s\'écrit en syntaxe wiki : \"\"//texte en italique//\"\"

====Mettre le texte en souligné====
__texte en souligné__
s\'écrit en syntaxe wiki : \"\"__texte en souligné__\"\"

=====Mise en forme : listes=====
====Faire une liste é puce====
 - point 1
 - point 2

s\'écrit en syntaxe wiki :
\"\" - point 1\"\"
\"\" - point 2\"\"

Attention : de bien mettre un espace devant le tiret pour que l\'élément soit reconnu comme liste


====Faire une liste numérotée====
 1) point 1
 2) point 2

s\'écrit en syntaxe wiki :
\"\" 1) point 1\"\"
\"\" 2) point 2\"\"

=====Les liens : le concept des \"\"ChatMots\"\"=====
====Créer une page YesWiki : ====
La caractéristique qui permet de reconnaitre un lien dans un wiki : son nom avec un mot contenant au moins deux majuscules non consécutives (un \"\"ChatMot\"\", un mot avec deux bosses).

==== Lien interne====
 - On écrit le \"\"ChatMot\"\" de la page YesWiki vers laquelle on veut pointer.
  - Si la page existe, un lien est automatiquement créé
  - Si la page n\'existe pas, apparait un lien avec crayon. En cliquant dessus on arrive vers la nouvelle page en mode \"édition\".

=====Les liens : personnaliser le texte=====
====Personnaliser le texte du lien internet====
entre double crochets : \"\"[[AccueiL aller é la page d\'accueil]]\"\", apparaitra ainsi : [[AccueiL aller é la page d\'accueil]].

====Liens vers d\'autres sites Internet====
entre double crochets : \"\"[[http://outils-reseaux.org aller sur le site d\'Outils-Réseaux]]\"\", apparaitra ainsi : [[http://outils-reseaux.org aller sur le site d\'Outils-Réseaux]].


=====Télécharger une image, un document=====
====On dispose d\'un lien vers l\'image ou le fichier====
entre double crochets :
 - \"\"[[http://mondomaine.ext/image.jpg texte de remplacement de l\'image]]\"\" pour les images.
 - \"\"[[http://mondomaine.ext/document.pdf texte du lien vers le téléchargement]]\"\" pour les documents.

====L\'action \"attach\"====
En cliquant sur le pictogramme représentant une image dans la barre d\'édition, on voit apparaétre la ligne de code suivante :
\"\"{{attach file=\" \" desc=\" \" class=\"left\" }} \"\"

Entre les premiéres guillemets, on indique le nom du document (ne pas oublier son extension (.jpg, .pdf, .zip).
Entre les secondes, on donne quelques éléments de description qui deviendront le texte du lien vers le document
Les troisiémes guillemets, permettent, pour les images, de positionner l\'image é gauche (left), ou é droite (right) ou au centre (center)
\"\"{{attach file=\"nom-document.doc\" desc=\"mon document\" class=\"left\" }} \"\"

Quand on sauve la page, un lien en point d\'interrogation apparait. En cliquant dessus, on arrive sur une page avec un systéme pour aller chercher le document sur sa machine (bouton \"parcourir\"), le sélectionner et le télécharger.

=====Intégrer du html=====
Si on veut faire une mise en page plus compliquée, ou intégrer un widget, il faut écrire en html. Pour cela, il faut mettre notre code html entre double guillemets.
Par exemple : \"\"<textarea style=\"width:100%;\">&quot;&quot;<span style=\"color:#0000EE;\">texte coloré</span>&quot;&quot;</textarea>\"\"
donnera :
\"\"<span style=\"color:#0000EE;\">texte coloré</span>\"\"


=====Les pages spéciales=====
 - PageHeader
 - PageFooter
 - PageMenuHaut
 - PageMenu
 - PageRapideHaut

 - PagesOrphelines
 - TableauDeBordDeCeWiki


=====Les actions disponibles=====
Voir la page spéciale : ListeDesActionsWikini

**les actions é ajouter dans la barre d\'adresse:**
rajouter dans la barre d\'adresse :
/edit : pour passer en mode Edition
/slide_show : pour transformer la texte en diaporama

===La barre du bas de page permet d\'effectuer diverses action sur la page===
 - voir l\'historique
 - partager sur les réseaux sociaux
...

=====Suivre la vie du site=====
 - Dans chaque page, en cliquant sur la date en bas de page on accéde é **l\'historique** et on peut comparer les différentes versions de la page.

 - **Le TableauDeBordDeCeWiki : ** pointe vers toutes les pages utiles é l\'analyse et é l\'animation du site.

 - **La page DerniersChangements** permet de visualiser les modifications qui ont été apportées sur l\'ensemble du site, et voir les versions antérieures. Pour l\'avoir en flux RSS DerniersChangementsRSS

 - **Les lecteurs de flux RSS** :  offrent une faéon simple, de produire et lire, de faéon standardisée (via des fichiers XML), des fils d\'actualité sur internet. On récupére les derniéres informations publiées. On peut ainsi s\'abonner é différents fils pour mener une veille technologique par exemple.
[[http://www.wikini.net/wakka.php?wiki=LecteursDeFilsRSS Différents lecteurs de flux RSS]]



=====L\'identification=====
====Premiére identification = création d\'un compte YesWiki====
    - aller sur la page spéciale ParametresUtilisateur,
    - choisir un nom YesWiki qui comprend 2 majuscules. //Exemple// : JamesBond
    - choisir un mot de passe et donner un mail
    - cliquer sur s\'inscrire

====Identifications suivantes====
    - aller sur ParametresUtilisateur,
    - remplir le formulaire avec son nom YesWiki et son mot de passe
    - cliquer sur \"connexion\"



=====Gérer les droits d\'accés aux pages=====
 - **Chaque page posséde trois niveaux de contréle d\'accés :**
     - lecture de la page
     - écriture/modification de la page
     - commentaire de la page

 - **Les contréles d\'accés ne peuvent étre modifiés que par le propriétaire de la page**
On est propriétaire des pages que l\'ont créent en étant identifié. Pour devenir \"propriétaire\" d\'une page, il faut cliquer sur Appropriation.

 - Le propriétaire d\'une page voit apparaétre, dans la page dont il est propriétaire, l\'option \"**éditer permissions**\" : cette option lui permet de **modifier les contréles d\'accés**.
Ces contréles sont matérialisés par des colonnes oé le propriétaire va ajouter ou supprimer des informations.
Le propriétaire peut compléter ces colonnes par les informations suivantes, séparées par des espaces :
     - le nom d\'un ou plusieurs utilisateurs : par exemple \"\"JamesBond\"\"
     - le caractére ***** désignant tous les utilisateurs
     - le caractére **+** désignant les utilisateurs enregistrés
     - le caractére **!** signifiant la négation : par exemple !\"\"JamesBond\"\" signifie que \"\"JamesBond\"\" **ne doit pas** avoir accés é cette page

 - **Droits d\'accés par défaut** : pour toute nouvelle page créée, YesWiki applique des droits d\'accés par défaut : sur ce YesWiki, les droits en lecture et écriture sont ouverts é tout internaute.

=====Supprimer une page=====

 - **2 conditions :**
    - **on doit étre propriétaire** de la page et **identifié** (voir plus haut),
    - **la page doit étre \"orpheline\"**, c\'est-é-dire qu\'aucune page ne pointe vers elle (pas de lien vers cette page sur le YesWiki), on peut voir toutes les pages orphelines en visitant la page : PagesOrphelines

 - **On peut alors cliquer sur l\'\'option \"Supprimer\"** en bas de page.



=====Changer le look et la disposition=====
En mode édition, si on est propriétaire de la page, ou que les droits sont ouverts, on peut changer la structure et la présentation du site, en jouant avec les listes déroulantes en bas de page : Théme, Squelette, Style.
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('DerniersChangements',  now(), '{{RecentChanges}}', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('DerniersChangementsRSS',  now(), '{{recentchangesrss}}', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('DerniersCommentaires',  now(), '{{RecentlyCommented}}', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('GererDroits',  now(), '{{nav links=\"GererSite, GererDroits, GererThemes, GererUtilisateurs, GererMisesAJour\" titles=\"Gestion du site, Droits d\'accès aux pages, Thèmes graphiques, Utilisateurs et groupes, Mises à jour / extensions\"}}

===Gérer les droits des pages===
{{gererdroits}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('GererMisesAJour',  now(), '{{nav links=\"GererSite, GererDroits, GererThemes, GererUtilisateurs, GererMisesAJour\" titles=\"Gestion du site, Droits d\'accès aux pages, Thèmes graphiques, Utilisateurs et groupes, Mises à jour / extensions\"}}

===Mises à jour / extensions===
{{update}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('GererSite',  now(), '{{nav links=\"GererSite, GererDroits, GererThemes, GererUtilisateurs, GererMisesAJour\" titles=\"Gestion du site, Droits d\'accès aux pages, Thèmes graphiques, Utilisateurs et groupes, Mises à jour / extensions\"}}

===Gérer les menus et pages spéciales de ce wiki===
 - [[PageMenuHaut Éditer menu horizontal d\'en haut]]
 - [[PageTitre Éditer le titre]]
 - [[PageRapideHaut Éditer le menu roue crantée]]
 - [[PageHeader Éditer le bandeau]]
 - [[PageFooter Éditer le footer]]
------
 - [[PageMenu Éditer le menu vertical (apparaissant sur les thèmes 2 colonnes ou plus)]]
 - [[PageColonneDroite Éditer la colonne de droite (apparaissant sur les thèmes 3 colonnes)]]
------
  - [[ReglesDeFormatage Éditer le mémo de formatage (bouton \"?\" dans la barre d\'édition )]]
------
===Gestion des mots clés ===
{{admintag}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('GererThemes',  now(), '{{nav links=\"GererSite, GererDroits, GererThemes, GererUtilisateurs, GererMisesAJour\" titles=\"Gestion du site, Droits d\'accès aux pages, Thèmes graphiques, Utilisateurs et groupes, Mises à jour / extensions\"}}

===Gérer les thèmes des pages===
{{gererthemes}}
-----
===Gérer le thème par défaut du wiki===
{{setwikidefaulttheme}}', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('GererUtilisateurs',  now(), '{{nav links=\"GererSite, GererDroits, GererThemes, GererUtilisateurs, GererMisesAJour\" titles=\"Gestion du site, Droits d\'accès aux pages, Thèmes graphiques, Utilisateurs et groupes, Mises à jour / extensions\"}}

===Gérer les groupes d\'utilisateurs===
{{editgroups}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('MotDePassePerdu',  now(), '{{lostpassword}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PageColonneDroite',  now(), 'Double cliquer sur ce texte pour éditer cette colonne.











\"\"\"\"
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PageFooter',  now(), '{{section height=\"60\" bgcolor=\"transparent\" class=\"text-center\"}}
(>^_^)> Galope sous [[https://www.yeswiki.net YesWiki]] <(^_^<)
{{end elem=\"section\"}}', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PageHeader',  now(), '{{backgroundimage height=\"150\" bgcolor=\"transparent\" class=\"white text-center\"}}
======Description de mon wiki======
Double cliquer ici pour changer le texte.
{{endbackgroundimage}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PageMenu',  now(), 'Double cliquer sur ce texte pour éditer cette colonne.











\"\"\"\"
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PageMenuHaut',  now(), ' - [[PagePrincipale Accueil]]
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PagePrincipale',  now(), '======Félicitations, votre wiki est installé ! ======

Pour vous approprier votre nouvel outil, voici quelques éléments pour démarrer :

 - pour modifier une page de votre Yeswiki, le double-clic est votre ami !
  - essayez ainsi de modifier la page présente (page d\'accueil). Un double clic n\'importe ou dans la partie centrale de la page vous permettra d\'atteindre le mode édition
  - vous souhaitez modifier le menu horizontal général ? Double cliquez gauche sur ce menu (en dehors des onglets), et vous aurez accès à l\'édition de ce menu. Utiliser les tirets (\" - \") pour créer de nouvelles entrées.

 - le menu d\'administration en haut à droite, accessible depuis la roue crantée (clic gauche) vous permettra :
  - de [[WikiAdmin gérer le site (pages, comptes et groupes utilisateurs,..)]]
  - d’administrer la [[BazaR base de données Bazar]]
  - de consulter les [[TableauDeBord dernières modifications sur le wiki]]
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PageRapideHaut',  now(), '{{moteurrecherche template=\"moteurrecherche_button.tpl.html\"}}
{{buttondropdown icon=\"glyphicon glyphicon-cog\" caret=\"0\"}}
 - {{login template=\"modal.tpl.html\" nobtn=\"1\"}}
 - ------
 - {{button nobtn=\"1\" icon=\"glyphicon glyphicon-question-sign\" text=\"Aide\" link=\"AidE\"}}
 - ------
 - {{button nobtn=\"1\" icon=\"glyphicon glyphicon-wrench\" text=\"Gestion du site\" link=\"GererSite\"}}
 - {{button nobtn=\"1\" icon=\"glyphicon glyphicon-dashboard\" text=\"Tableau de bord\" link=\"TableauDeBord\"}}
 - {{button nobtn=\"1\" icon=\"glyphicon glyphicon-briefcase\" text=\"Base de données\" link=\"BazaR\"}}
{{end elem=\"buttondropdown\"}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PagesOrphelines',  now(), '{{OrphanedPages}}', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('PageTitre',  now(), '{{configuration param=\"wakka_name\"}}', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('ParametresUtilisateur',  now(), '{{UserSettings}}

**Mot de passe perdu**
{{lostpassword}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('RechercheTexte',  now(), '{{TextSearch}}', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('ReglesDeFormatage',  now(), '{{grid}}
{{col size=\"6\"}}
=====Règles de formatage=====
===Accentuation===
\"\"<pre>\"\"**\"\"**Gras**\"\"**
//\"\"//Italique//\"\"//
__\"\"__Souligné__\"\"__
@@\"\"@@Barré@@\"\"@@\"\"</pre>\"\"
===Titres===
\"\"<pre>\"\"======\"\"======Titre 1======\"\"======
=====\"\"=====Titre 2=====\"\"=====
====\"\"====Titre 3====\"\"====
===\"\"===Titre 4===\"\"===
==\"\"==Titre 5==\"\"==\"\"</pre>\"\"
===Listes===
\"\"<pre> - Liste à puce niveau 1
 - Liste à puce niveau 1
  - Liste à puce niveau 2
  - Liste à puce niveau 2
 - Liste à puce niveau 1

 1. Liste énumérée
 2. Liste énumérée
 3. Liste énumérée</pre>\"\"
===Liens===
\"\"<pre>[[http://www.exemple.com Texte à afficher pour le lien externe]]\"\"
\"\"[[PageDeCeWiki Texte à afficher pour le lien interne]]</pre>\"\"
===Lien qui force l\'ouverture vers une page extérieure===
%%\"\"<a href=\"http://exemple.com\" target=\"_blank\">ton texte</a>\"\"%%
===Images===
//\"\"<pre>Pour télécharger une image, utiliser le bouton Joindre/insérer un fichier</pre>\"\".//
===Tableaux===
\"\"<pre>[|
| Colonne 1 | Colonne 2 | Colonne 3 |
| John     | Doe      | Male     |
| Mary     | Smith    | Female   |
|]
</pre>\"\"
===Boutons wiki===
\"\"<pre>{{button class=\"btn btn-danger\" link=\"lienverspage\" icon=\"plus icon-white\" text=\"votre texte\"}}</pre>\"\"
===Placer un bouton qui s\'ouvre dans un autre onglet===
%%\"\"<a href=\"votrelien\" target=\"_blank\" class=\"btn btn-primary btn-large\">votre texte</a>\"\"%%
===Ecrire en html===
\"\"<pre>si vous déposez du html dans la page wiki, 
il faut l\'entourer de &quot;&quot; <bout de html> &quot;&quot; 
pour qu\'il soit interprété</pre>\"\"
===Placer du code en commentaire sur la page===
%%\"\"<!-- en utilisant ce code on peut mettre du texte qui n\'apparait pas sur la page... ce qui permet de laisser des explications par exemple ou même d\'écrire du texte en prépa d\'une publication future -->\"\"%%
{{end elem=\"col\"}}
{{col size=\"6\"}}
=====Code exemples=====
===Insérer un iframe===
//Inclure un autre site, ou un pad, ou une vidéo youtube, etc...//
%%\"\"<iframe width=100% height=\"1250\" src=\"http://exemple.com\" frameborder=\"0\" allowfullscreen></iframe>\"\"%%
===Texte en couleur===
%%\"\"<span style=\"color:#votrecodecouleur;\">votre texte à colorer</span>\"\"%%//Quelques codes couleur => mauve : 990066 / vert : 99cc33 / rouge : cc3333 / orange : ff9900 / bleu : 006699////Voir les codes hexa des couleurs : [[http://fr.wikipedia.org/wiki/Liste_de_couleurs http://fr.wikipedia.org/wiki/Liste_de_couleurs]]//
===Message d\'alerte===
//Avec une croix pour le fermer.//
%%\"\"<div class=\"alert\">
<button type=\"button\" class=\"close\" data-dismiss=\"alert\">×</button>
Attention ! Voici votre message.
</div>\"\"%%
===Label \"important\" ou \"info\"===
\"\"<span class=\"label label-danger\">Important</span>\"\" et \"\"<span class=\"label label-info\">Info</span>\"\"
%%\"\"<span class=\"label label-danger\">Important</span>\"\" et \"\"<span class=\"label label-info\">Info</span>\"\"%%
===Mise en page par colonne===
//le total des colonnes doit faire 12 (ou moins)//
%%{{grid}}
{{col size=\"6\"}}
===Titre de la colonne 1===
Texte colonne 1
{{end elem=\"col\"}}
{{col size=\"6\"}}
===Titre de la colonne 2===
Texte colonne 2
{{end elem=\"col\"}}
{{end elem=\"grid\"}}%%
===Créer des onglets dans une page===
Il est possible de créer des onglets au sein d\'une page wiki en utilisant l\'action {{nav}}. La syntaxe est (elle est à répéter sur toutes les pages concernée par la barre d\'onglet)
\"\"<pre>{{nav links=\"NomPage1, NomPage2, NomPage3Personne\" titles=\"TitreOnglet1, TitreOnglet2, TitreOnglet3\"}}</pre>\"\"
===Formulaires de contact===
\"\"<pre>{{contact mail=\"adresse.mail@exemple.com\"}}</pre>\"\"
===Inclure une page dans une autre===
%%{{include page=\"NomPageAInclure\"}} %%
Pour inclure une page d\'un autre yeswiki : ( Noter le pipe \"\"|\"\" après les premiers \"\"[[\"\" ) %%[[|http://lesite.org/nomduwiki PageAInclure]]%%
===Image de fond avec du texte par dessus===
//Avec possibilité de mettre du texte par dessus//
%%{{backgroundimage height=\"150\" file=\"monbandeau.jpg\" class=\"white text-center doubletitlesize\"}}
=====Texte du titre=====
description
{{endbackgroundimage}}%%
{{end elem=\"col\"}}
{{end elem=\"grid\"}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('TableauDeBord',  now(), '======Tableau de bord======
{{mailperiod}}
{{grid}}
{{col size=\"6\"}}
====Derniers comptes utilisateurs ====
{{Listusers}}
------
====Dernières pages modifiées ====
{{recentchanges}}
------
==== 5 dernières pages commentées ====
{{RecentlyCommented}}
------
{{end elem=\"col\"}}
{{col size=\"6\"}}
==== Index des pages ====
{{pageindex}}
------
==== Pages orphelines ====
{{OrphanedPages}}
------
{{end elem=\"col\"}}
{{end elem=\"grid\"}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', ''),
('WikiAdmin',  now(), '{{redirect page=\"GererSite\"}}
', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '');
# end YesWiki pages


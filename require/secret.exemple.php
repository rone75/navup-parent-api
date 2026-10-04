<?php
// Modèle de require/secret.php : secrets et réglages propres à la machine.
// Copier ce fichier en secret.php (ignoré par git), renseigner les valeurs, puis en production :
//   chmod 640 require/secret.php && chgrp <groupe du pool php-fpm de cette API> require/secret.php

$_PROD = 0;                                   // 1 en production

// Base de données : l'utilisateur restreint de l'appli des parents (sql/000_utilisateur.exemple.sql), jamais celui de navup-api
$_DB = array(
    'hote' => 'localhost',
    'utilisateur' => 'navup_parents',
    'mot_de_passe' => '',
    'base' => 'navup',
);

// Origines autorisées par CORS en production : hôtes exacts du front des parents, servis en https (ex. 'app.navup.fr').
// En développement ($_PROD = 0), les origines localhost sont acceptées d'office.
$_CORS_ORIGINES = array();

// Mails d'erreur SQL : destinataire et expéditeur
$_MAIL_ERREUR = "";
$_MAIL_EXPEDITEUR = "noreply@navup.fr";

// Clé de signature des adresses de médias (audio, pages d'une fiche, PDF) : 32 octets au moins, tirés au hasard
//   php -r 'echo bin2hex(random_bytes(32)) . "\n";'
// La changer rend caduques les adresses déjà données (elles se redemandent toutes seules).
$_CLE_MEDIA = "";

// Médias de la formation : le dossier de navup-api, que cette API lit sans jamais y écrire
$_DOSSIER_MEDIAS = "/var/www/navup-media";

// Adresse publique de cette API, avec la barre finale : elle entre dans les adresses de médias données au front
$_PATH_API = "http://localhost/navup-parent-api/";

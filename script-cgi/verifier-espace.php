#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-espace.php
// Description: contrôle de cohérence des tables de l'appli des parents, en lecture seule (utilisable en production) :
//              - e_acces : chaque mot de passe est un hash bcrypt ;
//              - e_session : chaque jeton gardé est une empreinte, jamais un jeton en clair ;
//              - e_progression : chaque ligne porte sur un sujet de la formation de son compte, et sa position reste
//                dans la durée de l'audio ;
//              - médias : le dossier est lisible, et aucune clé de signature vide n'est en service.
//              Sort avec le code 1 au premier écart, 0 si tout est cohérent.
// Usage:       php script-cgi/verifier-espace.php
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../include/package.mysql.php";
include __DIR__ . "/../include/package.response.php";
include __DIR__ . "/../require/param.php";

date_default_timezone_set('Europe/Paris');
$Response = new Response();
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = __FILE__;

$ecarts = array();
$compter = function ($sql) use ($Mysql) {
    return (int) $Mysql->fetchOne($sql)->nb;
};

$n = $compter("SELECT COUNT(*) AS nb FROM e_acces WHERE mot_de_passe NOT LIKE '$2y$%' OR CHAR_LENGTH(mot_de_passe) < 59");
if ($n > 0) {
    $ecarts[] = "$n mot(s) de passe qui ne sont pas des hash bcrypt";
}
$n = $compter("SELECT COUNT(*) AS nb FROM e_session WHERE jeton NOT REGEXP '^[a-f0-9]{64}$'");
if ($n > 0) {
    $ecarts[] = "$n session(s) dont le jeton gardé n'est pas une empreinte";
}
$n = $compter(
    "SELECT COUNT(*) AS nb FROM e_progression p
     INNER JOIN a_acces a ON a.id_compte = p.id_compte
     INNER JOIN f_sujet s ON s.id_sujet = p.id_sujet
     WHERE s.id_formation <> a.id_formation"
);
if ($n > 0) {
    $ecarts[] = "$n ligne(s) de progression sur un sujet d'une autre formation que celle du compte";
}
$n = $compter(
    "SELECT COUNT(*) AS nb FROM e_progression p
     INNER JOIN f_fichier f ON f.id_sujet = p.id_sujet AND f.role = 'audio' AND f.etat = 'pret' AND f.duree IS NOT NULL
     WHERE p.position > f.duree + 5"
);
if ($n > 0) {
    $ecarts[] = "$n position(s) d'écoute au-delà de la durée de l'audio";
}
if (!isset($_CLE_MEDIA) || strlen((string) $_CLE_MEDIA) < 32) {
    $ecarts[] = "clé de signature des médias absente ou trop courte (require/secret.php)";
}
if (!isset($_DOSSIER_MEDIAS) || !is_dir($_DOSSIER_MEDIAS) || !is_readable($_DOSSIER_MEDIAS)) {
    $ecarts[] = "dossier des médias illisible";
}

$comptes = $compter("SELECT COUNT(*) AS nb FROM e_acces");
$sessions = $compter("SELECT COUNT(*) AS nb FROM e_session WHERE date_expiration > NOW()");
if (count($ecarts) > 0) {
    echo implode("\n", $ecarts) . "\n" . count($ecarts) . " écart(s) dans l'espace des parents.\n";
    exit(1);
}
echo "Espace des parents vérifié ($comptes accès créé(s), $sessions session(s) en cours) : aucun écart.\n";

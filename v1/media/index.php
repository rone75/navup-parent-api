<?php

include "../../include/package.mysql.php";
include "../../include/package.response.php";
include "../../include/package.media.php";
include "../../require/param.php";

// Média de la formation, par son adresse signée ################################
// GET | HEAD ?f=<fichier>&p=<page>&n=<session>&e=<expiration>&s=<signature> : l'audio ou le PDF (p=0), ou l'image de la
// page p d'une fiche. Demandé par une balise <audio>, <img> ou un lien : aucun en-tête Authorization, aucun CORS.
// Tout refus est un 403 sans explication : adresse expirée ou altérée, session fermée, accès fermé, semaine pas
// encore débloquée, sujet dépublié, fichier remplacé. Le front redemande alors le sujet, qui dit ce qu'il en est.
// Aucune écriture : ni limiteur, ni prolongation de session.

date_default_timezone_set('Europe/Paris');

if ($_SERVER['REQUEST_METHOD'] !== "GET" && $_SERVER['REQUEST_METHOD'] !== "HEAD") {
    http_response_code(405);
    exit();
}

$demande = Media::lire($_GET);
if ($demande === null) {
    http_response_code(403);
    exit();
}
list($f, $page, $n) = $demande;

$Response = new Response();
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();
$file_err = getcwd() . "/index.php";

$fichier = Media::autorise($f, $n);
$Mysql->CloseBase($SQL);

$media = $fichier === null ? null : Media::fichier($fichier, $page);
if ($media === null) {
    http_response_code(403);
    exit();
}

Media::envoyer($media[0], $media[1], $media[2]);

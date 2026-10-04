<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.response.php";
include "../../include/package.limite.php";
include "../../include/package.session.php";
include "../../include/package.programme.php";
include "../../include/package.media.php";
include "../../include/package.progression.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$Session = new Session();
$Programme = new Programme();
$Progression = new Progression();

$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";
date_default_timezone_set('Europe/Paris');

// Un sujet du programme ################################
// GET ?id=N → {sujet} : titre, pochette, semaine, la fiche (une adresse signée par page, et le PDF), l'audio (adresse
// signée, durée), où en est l'écoute, les sujets voisins. Les adresses de médias valent quelques heures : le front
// redemande le sujet quand l'une d'elles ne répond plus.
// L'ouverture est notée : le sujet devient « en cours », et c'est lui que « Continuer » reprendra.
// Sujet d'une semaine pas encore débloquée : 400 avec sa date. Inconnu, en brouillon ou d'une autre formation : 404.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $compte = $Session->exigerAcces();
    $id = (isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) && strlen($_GET['id']) < 10) ? (int) $_GET['id'] : 0;

    list($sujet, $refus, $date) = $Programme->accessible($compte, $id);
    if ($refus === 'verrouille') {
        $Response->validationError("Ce sujet n'est pas encore disponible.", array('date_deblocage' => $date));
    }
    if ($sujet === null) {
        $Response->notFound("Ce sujet n'existe pas, ou n'est plus proposé.");
    }

    $Progression->ouvrir($compte->id_compte, $id);

    $Response->success(array('sujet' => $Programme->sujet($compte, $sujet, $Session->session->id_session)));
}

$Response->methodNotAllowed();

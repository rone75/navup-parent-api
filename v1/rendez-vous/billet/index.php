<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.response.php";
include "../../../include/package.limite.php";
include "../../../include/package.session.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$Session = new Session();

$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";
date_default_timezone_set('Europe/Paris');

// Billet de rendez-vous ################################
// POST → {billet} : ce que le parent connecté présente à navup-api (v1/public/rendez-vous/espace/) pour lire ses
// rendez-vous, en prendre un, le déplacer ou l'annuler. Les rendez-vous s'écrivent dans la Tour de contrôle, par ses
// seules méthodes : cette API n'en lit ni n'en écrit aucun, elle atteste seulement de qui demande.
// Exigé : une session vivante et un accès ouvert (403 code 3 avec l'état de l'accès sinon, comme pour les contenus).
// Le billet est tiré au hasard ; seule son empreinte est gardée. Sa durée est un réglage de navup-api, qui le
// revérifie à chaque usage (session encore ouverte, compte ni désactivé ni révoqué) : le front en redemande un
// quand il est refusé.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $compte = $Session->exigerAcces();

    $billet = bin2hex(random_bytes(24));
    $Mysql->execute(
        "INSERT INTO e_billet (jeton, id_compte, id_session) VALUES (?, ?, ?)",
        array(hash('sha256', $billet), (int) $compte->id_compte, (int) $Session->session->id_session),
        'sii'
    );

    $Response->success(array('billet' => $billet), 201);
}

$Response->methodNotAllowed();

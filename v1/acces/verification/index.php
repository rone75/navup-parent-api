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

// Lien d'accès : est-il encore utilisable ? ################################
// POST {jeton} → {prenom, motif: creation|reinitialisation}. Le jeton voyage dans le corps, jamais dans l'adresse
// (il finirait dans le journal d'accès). Un lien inconnu, expiré, annulé ou déjà utilisé reçoit la même réponse.
// Public : limité par adresse IP.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    Limite::exiger('lien', $_LIMITE_LIEN);
    $R = $Session->corps();

    $lien = $Session->lien(isset($R->jeton) ? $R->jeton : null);
    if ($lien === null) {
        $Response->validationError("Ce lien n'est plus valable. Demandez-en un nouveau : il arrive par e-mail en quelques instants.");
    }
    list($jeton, $compte) = $lien;

    $Response->success(array('prenom' => $compte->prenom, 'motif' => $jeton->motif));
}

$Response->methodNotAllowed();

<?php

include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.response.php";
include "../../include/package.limite.php";
include "../../include/package.session.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$Session = new Session();

$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";
date_default_timezone_set('Europe/Paris');

// Création ou remplacement du mot de passe par le lien reçu par e-mail ################################
// POST {jeton, pass} → {token, moi}. Le lien ne sert qu'une fois ; les autres sessions du compte tombent.
// Le mot de passe se choisit quel que soit l'état de l'accès : `moi.acces` dit ensuite au parent où il en est.
// Public : limité par adresse IP.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    Limite::exiger('lien', $_LIMITE_LIEN);
    $R = $Session->corps();

    $lien = $Session->lien(isset($R->jeton) ? $R->jeton : null);
    if ($lien === null) {
        $Response->validationError("Ce lien n'est plus valable. Demandez-en un nouveau : il arrive par e-mail en quelques instants.");
    }
    list($jeton, $compte) = $lien;

    $pass = $Session->decoder(isset($R->pass) ? $R->pass : null);
    $refus = $Session->politique($pass, $compte->email);
    if ($refus !== null) {
        $Response->validationError($refus);
    }

    $token = $Session->consommer($jeton, $compte, $pass);
    if ($token === null) {
        $Response->validationError("Ce lien n'est plus valable. Demandez-en un nouveau : il arrive par e-mail en quelques instants.");
    }

    $Response->success(array('token' => $token, 'moi' => $Session->sortie($Session->compte($compte->id_compte))), 201);
}

$Response->methodNotAllowed();

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

// Changement du mot de passe depuis le profil ################################
// PUT {actuel, nouveau}. Le mot de passe actuel est redemandé ; les autres appareils sont déconnectés.
// Une erreur répond 400, jamais 401 : la session du parent reste bonne.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $compte = $Session->exiger();
    $R = $Session->corps();

    // Les essais ratés comptent comme à la connexion, pour ce compte et cette adresse
    $ip = Limite::ip();
    $cle = Limite::cleEmail('mdp', $compte->email);
    list($nb, $reste) = Limite::lire($cle, $ip);
    if ($nb >= (int) $_LIMITE_CONNEXION[0]) {
        $Response->rateLimitExceeded("Trop d'essais. Réessayez dans " . (int) ceil($reste / 60) . " min.", $reste);
    }

    $actuel = $Session->decoder(isset($R->actuel) ? $R->actuel : null);
    $nouveau = $Session->decoder(isset($R->nouveau) ? $R->nouveau : null);
    if (!$Session->verifier($compte, $actuel)) {
        Limite::compter($cle, $ip, (int) $_LIMITE_CONNEXION[1]);
        $Response->validationError("Votre mot de passe actuel n'est pas le bon.");
    }
    $refus = $Session->politique($nouveau, $compte->email);
    if ($refus !== null) {
        $Response->validationError($refus);
    }
    if ($nouveau === $actuel) {
        $Response->validationError("Choisissez un mot de passe différent de l'actuel.");
    }

    $SQL->begin_transaction();
    $Session->ecrireMotDePasse($compte->id_compte, $nouveau);
    $Session->fermerAutres($compte->id_compte, $Session->session->id_session);
    $SQL->commit();
    Limite::effacer($cle, $ip);

    $Response->success();
}

$Response->methodNotAllowed();

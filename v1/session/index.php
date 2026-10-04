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

// Connexion ################################
// POST {email, pass} → {token, moi}. Un seul message pour un e-mail inconnu, un compte sans mot de passe et un
// mauvais mot de passe, au même temps de réponse. Aucun compte ne se verrouille : après trop d'essais ratés, la
// connexion par mot de passe se ferme un temps (429) et le parent est renvoyé vers le lien par e-mail, qui la rouvre.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    Limite::purger();
    $R = $Session->corps();
    $email = $Session->lireEmail(isset($R->email) ? $R->email : null);
    $pass = $Session->decoder(isset($R->pass) ? $R->pass : null);
    if ($email === null || $pass === null) {
        $Response->validationError("Saisissez votre e-mail et votre mot de passe.");
    }

    $ip = Limite::ip();
    $compteurs = array(
        array(Limite::cleEmail('cnx', $email), $ip, $_LIMITE_CONNEXION),
        array('cnx-ip', $ip, $_LIMITE_CONNEXION_IP),
        array(Limite::cleEmail('cnxe', $email), '', $_LIMITE_CONNEXION_EMAIL),
    );
    foreach ($compteurs as $c) {
        list($nb, $reste) = Limite::lire($c[0], $c[1]);
        if ($nb >= (int) $c[2][0]) {
            $Response->rateLimitExceeded("Trop d'essais. Recevez un lien de connexion par e-mail, ou réessayez dans " . (int) ceil($reste / 60) . " min.", $reste);
        }
    }

    $compte = $Session->compteParEmail($email);
    if (!$Session->verifier($compte, $pass)) {
        foreach ($compteurs as $c) {
            Limite::compter($c[0], $c[1], (int) $c[2][1]);
        }
        $Response->authError("E-mail ou mot de passe incorrect.");
    }

    Limite::effacer($compteurs[0][0], $ip);
    $Session->rehacher($compte, $pass);
    $token = $Session->ouvrir($compte->id_compte);

    $Response->success(array('token' => $token, 'moi' => $Session->sortie($Session->compte($compte->id_compte))), 201);
}

// Le parent connecté ################################
// GET → {moi} : identité, état de l'accès (ouvert, pas_commence, suspendu, ferme) et ses dates, préférences.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $compte = $Session->exiger();

    $Response->success(array('moi' => $Session->sortie($compte)));
}

// Déconnexion ################################

if ($_SERVER['REQUEST_METHOD'] === "DELETE") {

    $Session->exiger();
    $Session->fermer($Session->session->id_session);

    $Response->success();
}

$Response->methodNotAllowed();

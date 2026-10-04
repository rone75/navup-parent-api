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

// Préférences du parent ################################
// PUT {annonce_semaine: true|false} : recevoir, ou non, un e-mail à chaque nouvelle semaine (navup-api le lit).
// PUT {accueil_lu: true} : les écrans de bienvenue ont été lus, ils ne se remontrent plus.
// → {moi}. L'identité (prénom, nom, e-mail) ne se modifie pas ici : elle appartient au dossier, tenu par NavUp.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $compte = $Session->exiger();
    $R = $Session->corps(500);

    if (isset($R->annonce_semaine)) {
        if (!is_bool($R->annonce_semaine)) {
            $Response->validationError("Erreur paramètre ANNONCE_SEMAINE (true ou false)");
        }
        $Mysql->execute("UPDATE e_acces SET annonce_semaine = ?, date_modif = NOW() WHERE id_compte = ?", array($R->annonce_semaine ? 1 : 0, (int) $compte->id_compte), 'ii');
    }
    if (isset($R->accueil_lu) && $R->accueil_lu === true) {
        $Mysql->execute("UPDATE e_acces SET date_accueil = COALESCE(date_accueil, NOW()) WHERE id_compte = ?", array((int) $compte->id_compte), 'i');
    }

    $Response->success(array('moi' => $Session->sortie($Session->compte($compte->id_compte))));
}

$Response->methodNotAllowed();

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

// « Supprimer mon compte » (RGPD, étape 8) ################################
// POST {pass} : après le mot de passe retapé, la demande est déposée pour NavUp (e_demande, que cette API ne fait
// qu'insérer) et l'espace se ferme aussitôt : toutes les sessions, et le mot de passe. navup-api prévient
// l'administrateur, qui efface le dossier depuis la Tour de contrôle dans le délai légal d'un mois ; les pièces
// comptables (achats, paiements) restent le temps que la loi impose.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $compte = $Session->exiger();
    $R = $Session->corps(500);
    $Session->exigerMotDePasse($compte, isset($R->pass) ? $R->pass : null);

    $SQL->begin_transaction();
    $Mysql->execute("INSERT INTO e_demande (id_compte, type) VALUES (?, 'suppression')", array((int) $compte->id_compte), 'i');
    $Session->fermerAutres($compte->id_compte);
    $Mysql->execute("DELETE FROM e_acces WHERE id_compte = ?", array((int) $compte->id_compte), 'i');
    $SQL->commit();

    $Response->success();
}

$Response->methodNotAllowed();

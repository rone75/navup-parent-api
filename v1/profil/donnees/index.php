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

// « Télécharger mes données » (RGPD, étape 8) ################################
// POST {pass} → {billet} : après le mot de passe retapé, un billet « donnees » à présenter à navup-api
// (v1/public/donnees/), qui seule lit le dossier et rend le fichier. Cette API n'y fait qu'insérer le billet.
// Une session suffit : un parent dont l'accès est fermé garde le droit de recevoir ses données.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $compte = $Session->exiger();
    $R = $Session->corps(500);
    $Session->exigerMotDePasse($compte, isset($R->pass) ? $R->pass : null);

    $billet = bin2hex(random_bytes(24));
    $Mysql->execute(
        "INSERT INTO e_billet (jeton, id_compte, id_session, objet) VALUES (?, ?, ?, 'donnees')",
        array(hash('sha256', $billet), (int) $compte->id_compte, (int) $Session->session->id_session),
        'sii'
    );

    $Response->success(array('billet' => $billet), 201);
}

$Response->methodNotAllowed();

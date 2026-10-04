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

// Progression sur un sujet ################################
// PUT {id_sujet, version, position?, termine?} → {progression, conflit, termines, sujets}.
// position : secondes d'écoute (envoyée pendant la lecture, à la pause, au passage en arrière-plan).
// termine : true quand le parent marque le sujet terminé, false quand il revient dessus. Jamais déduit de l'écoute.
// version : celle que le front a lue. Si un autre appareil a écrit depuis, rien n'est écrit : `conflit` est vrai
// et `progression` est celle du serveur, que le front adopte.

if ($_SERVER['REQUEST_METHOD'] === "PUT") {

    $compte = $Session->exigerAcces();
    $R = $Session->corps(1000);

    $id = (isset($R->id_sujet) && is_int($R->id_sujet) && $R->id_sujet > 0) ? $R->id_sujet : 0;
    $version = (isset($R->version) && is_int($R->version) && $R->version >= 0) ? $R->version : null;
    $position = null;
    if (isset($R->position)) {
        if (!is_int($R->position) || $R->position < 0 || $R->position > 86400) {
            $Response->validationError("Position d'écoute illisible.");
        }
        $position = $R->position;
    }
    $termine = null;
    if (isset($R->termine)) {
        if (!is_bool($R->termine)) {
            $Response->validationError("Erreur paramètre TERMINE (true ou false)");
        }
        $termine = $R->termine;
    }
    if ($version === null || ($position === null && $termine === null)) {
        $Response->validationError("Demande illisible.");
    }

    list($sujet, $refus) = $Programme->accessible($compte, $id);
    if ($sujet === null) {
        $Response->validationError($refus === 'verrouille' ? "Ce sujet n'est pas encore disponible." : "Ce sujet n'existe pas, ou n'est plus proposé.");
    }

    // Un sujet jamais ouvert n'a pas de ligne : elle naît ici (version 1), et l'écriture part de celle que le front annonce
    if ($Progression->lire($compte->id_compte, $id) === null) {
        $Progression->ouvrir($compte->id_compte, $id);
        $version = max(1, $version);
    }
    list($progression, $conflit) = $Progression->ecrire($compte->id_compte, $id, $version, $position, $termine);
    list($termines, $sujets) = $Progression->compte($compte);

    $Response->success(array(
        'progression' => $Progression->sortie($progression),
        'conflit' => $conflit,
        'termines' => $termines,
        'sujets' => $sujets,
    ));
}

$Response->methodNotAllowed();

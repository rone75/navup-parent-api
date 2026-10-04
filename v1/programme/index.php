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

// Le programme du parent ################################
// GET → {programme} : la semaine en cours, les sujets terminés sur le total, le sujet à reprendre, la prochaine
// semaine à venir, puis chaque semaine (terminée, disponible, verrouillée + sa date) et ses sujets publiés.
// Accès fermé, suspendu ou pas commencé : 403 avec l'état de l'accès.

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    $compte = $Session->exigerAcces();

    $Response->success(array('programme' => $Programme->vue($compte)));
}

$Response->methodNotAllowed();

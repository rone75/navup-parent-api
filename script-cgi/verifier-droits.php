#!/usr/bin/env php
<?php

//=======================================================================
// File:        script-cgi/verifier-droits.php
// Description: contrôle les droits MariaDB de l'utilisateur de l'appli des parents (lecture seule, utilisable en
//              production) : ce que SHOW GRANTS rend doit être exactement la liste de sql/000_utilisateur.exemple.sql,
//              et les tables internes de la Tour de contrôle doivent lui être refusées.
//              Sort avec le code 1 au premier écart.
// Usage:       php script-cgi/verifier-droits.php
//========================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement\n");
}

include __DIR__ . "/../require/param.php";

// Droits attendus, par objet : ni plus, ni moins
$ATTENDUS = array(
    'a_acces' => 'SELECT',
    'a_semaine' => 'SELECT',
    'a_jeton' => 'SELECT',
    'f_sujet' => 'SELECT',
    'f_fichier' => 'SELECT',
    'e_acces' => 'SELECT, INSERT, UPDATE, DELETE',
    'e_session' => 'SELECT, INSERT, UPDATE, DELETE',
    'e_progression' => 'SELECT, INSERT, UPDATE, DELETE',
    'e_jeton_utilise' => 'SELECT, INSERT, UPDATE, DELETE',
    'e_limite' => 'SELECT, INSERT, UPDATE, DELETE',
);
// Ce que l'appli ne doit jamais pouvoir lire
$INTERDITES = array('d_contact', 'd_declaration', 'd_enfant', 'd_problematique', 'd_note', 'd_evenement', 'd_consentement',
    'u_users', 'u_token', 'u_audit', 'v_vente', 'v_paiement', 'm_message', 's_commande', 'r_rdv', 'i_interaction', 't_tache', 'a_compte', 'f_semaine', 'f_formation');

mysqli_report(MYSQLI_REPORT_OFF);
$sql = @new mysqli($_DB['hote'], $_DB['utilisateur'], $_DB['mot_de_passe'], $_DB['base']);
if ($sql->connect_errno) {
    fwrite(STDERR, "Connexion refusée à l'utilisateur « " . $_DB['utilisateur'] . " ».\n");
    exit(1);
}

$ecarts = array();
$trouves = array();
$res = $sql->query("SHOW GRANTS");
while ($ligne = $res->fetch_row()) {
    $g = $ligne[0];
    if (preg_match('/^GRANT USAGE ON \*\.\* /', $g)) {
        continue;
    }
    if (preg_match('/^GRANT (.+) ON `' . preg_quote($_DB['base'], '/') . '`\.`([a-z_]+)` TO /', $g, $m) && strpos($g, 'WITH GRANT OPTION') === false) {
        $trouves[$m[2]] = $m[1];
    } else {
        $ecarts[] = "droit inattendu : " . preg_replace('/IDENTIFIED BY.*/', '', $g);
    }
}
foreach ($ATTENDUS as $objet => $droits) {
    if (!isset($trouves[$objet])) {
        $ecarts[] = "$objet : droit manquant ($droits)";
    } elseif ($trouves[$objet] !== $droits) {
        $ecarts[] = "$objet : « " . $trouves[$objet] . " » au lieu de « $droits »";
    }
}
foreach (array_diff(array_keys($trouves), array_keys($ATTENDUS)) as $objet) {
    $ecarts[] = "$objet : droit en trop (" . $trouves[$objet] . ")";
}
foreach ($INTERDITES as $table) {
    if ($sql->query("SELECT 1 FROM `$table` LIMIT 1") !== false) {
        $ecarts[] = "$table : lisible, alors qu'elle doit être refusée";
    }
}

if (count($ecarts) > 0) {
    echo implode("\n", $ecarts) . "\n" . count($ecarts) . " écart(s) sur les droits de « " . $_DB['utilisateur'] . " ».\n";
    exit(1);
}
echo "Droits de « " . $_DB['utilisateur'] . " » vérifiés (" . count($ATTENDUS) . " objets ouverts, " . count($INTERDITES) . " tables refusées) : aucun écart.\n";

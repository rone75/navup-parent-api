<?php
// Configuration de l'API des parents NavUp, versionnée et sans secret.
// Les identifiants et réglages propres à la machine sont dans require/secret.php (hors dépôt, modèle : secret.exemple.php).
// Ce qui décide de l'accès (dates du compte, déblocage des semaines) n'est pas ici : c'est dans la base, écrit par navup-api.

// Sessions (table e_session)
$_SESSION_JOURS = 30;            // expiration glissante : repoussée à chaque visite (au plus une fois par heure)
$_SESSION_MAX_JOURS = 90;        // durée de vie absolue d'une session, quelle que soit l'activité
$_SESSION_MAX_PAR_COMPTE = 5;    // appareils connectés en même temps (les sessions les plus anciennes tombent)

// Mot de passe d'un parent : une longueur, pas de règle de composition (le maximum de 72 octets est la limite de bcrypt)
$_MDP_LONGUEUR_MIN = 10;

// Limiteur (table e_limite) : array(nombre maximal, fenêtre en minutes). Aucun compte ne se verrouille :
// seule la connexion par mot de passe se ferme un temps, le lien reçu par e-mail reste toujours utilisable.
$_LIMITE_CONNEXION = array(5, 15);         // essais ratés pour un e-mail depuis une adresse IP
$_LIMITE_CONNEXION_IP = array(20, 15);     // essais ratés depuis une adresse IP, tous e-mails confondus
$_LIMITE_CONNEXION_EMAIL = array(30, 60);  // essais ratés pour un e-mail, toutes adresses confondues
$_LIMITE_LIEN = array(20, 15);             // liens d'accès présentés depuis une adresse IP

// Médias : durée d'une fenêtre de signature, en secondes. Une adresse vaut de une à deux fenêtres (6 à 12 h) ;
// la fenêtre est fixe pour que la même adresse soit redonnée et que le cache du navigateur serve.
$_MEDIA_FENETRE = 21600;

if (!is_file(__DIR__ . '/secret.php')) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Configuration manquante : copier require/secret.exemple.php en require/secret.php\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo ")]}',\n" . json_encode(array('success' => false, 'message' => "Une erreur interne s'est produite. Veuillez réessayer plus tard."));
    exit();
}

require __DIR__ . '/secret.php';

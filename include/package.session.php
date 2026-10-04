<?php

//=======================================================================
// File:        package.session.php
// Description: compte d'un parent vu de son espace : état de son accès, mot de passe, sessions, lien d'accès.
//              Le compte, ses dates et son identité se lisent dans la vue a_acces (écrite par navup-api) ;
//              le mot de passe (e_acces), les sessions (e_session) et l'usage d'un lien (e_jeton_utilise) sont à
//              cette API. Un lien d'accès est créé par navup-api (a_jeton) : ici, on le vérifie et on le consomme.
//              Se connecter n'est pas avoir accès : un compte suspendu, pas commencé ou fermé ouvre une session,
//              et les endpoints de contenu refusent en disant pourquoi (exigerAcces).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Session
{
    // Hash bcrypt factice : vérifié quand l'e-mail est inconnu ou sans mot de passe, pour un temps de réponse constant
    const DUMMY_HASH = '$2y$12$P12edLgpjiI/7bnsflfMY.TlVvZht/Zrc.ruBeMpd4pr3lBu43fEy';

    // Longueur maximale d'un mot de passe en octets : bcrypt ignore tout ce qui dépasse
    const OCTETS_MAX = 72;

    // Trop répandus pour protéger quoi que ce soit, malgré leur longueur
    const TROP_COURANTS = array(
        '1234567890', '0123456789', '12345678910', 'azertyuiop', 'qwertyuiop', 'motdepasse', 'motdepasse1',
        'password123', 'azerty12345', 'azerty123456', 'navupacademy', '0000000000', '1111111111',
    );

    const SQL_COMPTE = "SELECT a.id_compte, a.id_formation, a.formation, a.etat, a.date_debut, a.date_fin, a.date_fin_acces,
            a.date_revocation, a.prenom, a.nom, a.email,
            e.mot_de_passe, e.date_mot_de_passe, e.date_derniere_connexion, e.date_accueil, e.annonce_semaine
        FROM a_acces a LEFT JOIN e_acces e ON e.id_compte = a.id_compte";

    // Session de la requête en cours, posée par exiger()
    public $session = null;

    public function compte($id_compte)
    {
        global $Mysql;

        return $Mysql->fetchOne(self::SQL_COMPTE . " WHERE a.id_compte = ?", array((int) $id_compte), 'i');
    }

    public function compteParEmail($email)
    {
        global $Mysql;

        return $Mysql->fetchOne(self::SQL_COMPTE . " WHERE a.email = ?", array($email), 's');
    }

    /** Le compte a-t-il un mot de passe utilisable ? Un mot de passe antérieur à la révocation de l'accès est nul. */
    public function aMotDePasse($compte)
    {
        return $compte->mot_de_passe !== null
            && ($compte->date_revocation === null || $compte->date_mot_de_passe > $compte->date_revocation);
    }

    /**
     * État de l'accès d'un compte pour un jour donné (le jour vient de PHP, jamais de l'appareil du parent) :
     * - suspendu : le compte est désactivé dans la Tour de contrôle ;
     * - pas_commence : le programme commence plus tard ;
     * - ferme : la fin de l'accès est passée ;
     * - ouvert : les contenus débloqués sont consultables. `programme_termine` : la dernière semaine est finie,
     *   l'accès reste ouvert jusqu'à date_fin_acces.
     */
    public function acces($compte, $aujourdhui = null)
    {
        $jour = $aujourdhui === null ? date('Y-m-d') : $aujourdhui;

        if ($compte->etat !== 'actif') {
            $etat = 'suspendu';
        } elseif ($jour < $compte->date_debut) {
            $etat = 'pas_commence';
        } elseif ($jour > $compte->date_fin_acces) {
            $etat = 'ferme';
        } else {
            $etat = 'ouvert';
        }

        return array(
            'etat' => $etat,
            'date_debut' => $compte->date_debut,
            'date_fin' => $compte->date_fin,
            'date_fin_acces' => $compte->date_fin_acces,
            'programme_termine' => $jour > $compte->date_fin,
        );
    }

    /** Le parent connecté, tel que servi au front : son identité (lecture seule), son accès, ses préférences. */
    public function sortie($compte)
    {
        return array(
            'prenom' => $compte->prenom,
            'nom' => $compte->nom,
            'email' => $compte->email,
            'formation' => $compte->formation,
            'acces' => $this->acces($compte),
            'accueil_lu' => $compte->date_accueil !== null,
            'annonce_semaine' => $compte->annonce_semaine === null ? true : (int) $compte->annonce_semaine === 1,
        );
    }

    /** Corps JSON d'une requête : type de contenu exigé, taille bornée avant toute lecture. Objet, ou 400. */
    public function corps($tailleMax = 5000)
    {
        global $Response;

        $type = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim(explode(';', (string) $_SERVER['CONTENT_TYPE'])[0])) : '';
        $longueur = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($type !== 'application/json' || $longueur > $tailleMax) {
            $Response->validationError("Demande illisible.");
        }
        $brut = file_get_contents("php://input", false, null, 0, $tailleMax + 1);
        $R = strlen($brut) > $tailleMax ? null : json_decode($brut);
        if (!is_object($R)) {
            $Response->validationError("Demande illisible.");
        }

        return $R;
    }

    /** E-mail saisi, mis en minuscules ; null s'il n'a pas la forme d'une adresse. */
    public function lireEmail($valeur)
    {
        if (!is_string($valeur)) {
            return null;
        }
        $email = mb_strtolower(trim($valeur), 'UTF-8');
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    // MOT DE PASSE ################################################

    /** Mot de passe reçu enrobé (18 caractères + base64 + 9 caractères, comme l'outil interne) ; null s'il est illisible. */
    public function decoder($obf)
    {
        if (!is_string($obf) || strlen($obf) <= 27 || strlen($obf) > 1000) {
            return null;
        }
        $pass = base64_decode(substr(substr($obf, 18), 0, -9), true);
        if ($pass === false || $pass === '') {
            return null;
        }

        return $pass;
    }

    /**
     * Règle d'un mot de passe de parent : $_MDP_LONGUEUR_MIN caractères au moins, 72 octets au plus, pas de caractère
     * de contrôle, ni l'e-mail du compte ni un mot de passe trop répandu. Aucune règle de composition.
     * Retourne null s'il convient, sinon la phrase à afficher. Le mot de passe n'est jamais nettoyé ni tronqué.
     */
    public function politique($pass, $email)
    {
        global $_MDP_LONGUEUR_MIN;

        $min = isset($_MDP_LONGUEUR_MIN) ? (int) $_MDP_LONGUEUR_MIN : 10;
        if (!is_string($pass) || !mb_check_encoding($pass, 'UTF-8') || mb_strlen($pass, 'UTF-8') < $min) {
            return "Choisissez un mot de passe d'au moins " . $min . " caractères.";
        }
        if (strlen($pass) > self::OCTETS_MAX) {
            return "Ce mot de passe est trop long : " . self::OCTETS_MAX . " caractères au plus.";
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $pass)) {
            return "Ce mot de passe contient un caractère qui ne peut pas être saisi.";
        }
        $bas = mb_strtolower($pass, 'UTF-8');
        if ($bas === mb_strtolower((string) $email, 'UTF-8') || in_array($bas, self::TROP_COURANTS, true) || preg_match('/^(.)\1+$/u', $pass)) {
            return "Ce mot de passe est trop facile à deviner : choisissez-en un autre.";
        }

        return null;
    }

    private function hacher($pass)
    {
        return password_hash($pass, PASSWORD_BCRYPT, array('cost' => 12));
    }

    /**
     * Vérifie un mot de passe. Le temps de réponse ne dit pas si l'e-mail existe : sans compte ou sans mot de passe
     * utilisable, la vérification se fait quand même, sur un hash factice.
     */
    public function verifier($compte, $pass)
    {
        $utilisable = $compte !== null && $this->aMotDePasse($compte);
        $bon = password_verify((string) $pass, $utilisable ? $compte->mot_de_passe : self::DUMMY_HASH);

        return $utilisable && $pass !== null && $bon;
    }

    /** Écrit le mot de passe d'un compte (création ou remplacement). Sans transaction : l'appelant la tient. */
    public function ecrireMotDePasse($id_compte, $pass)
    {
        global $Mysql;

        $Mysql->execute(
            "INSERT INTO e_acces (id_compte, mot_de_passe, date_mot_de_passe) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE mot_de_passe = VALUES(mot_de_passe), date_mot_de_passe = NOW(), date_modif = NOW()",
            array((int) $id_compte, $this->hacher($pass)),
            'is'
        );
    }

    /** Remplace un hash dont le coût n'est plus celui d'aujourd'hui, à la connexion (le mot de passe est alors connu). */
    public function rehacher($compte, $pass)
    {
        global $Mysql;

        if (password_needs_rehash($compte->mot_de_passe, PASSWORD_BCRYPT, array('cost' => 12))) {
            $Mysql->execute("UPDATE e_acces SET mot_de_passe = ?, date_modif = NOW() WHERE id_compte = ?", array($this->hacher($pass), (int) $compte->id_compte), 'si');
        }
    }

    // SESSIONS ################################################

    private function genJeton($longueur)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $jeton = '';
        for ($i = 0; $i < $longueur; $i++) {
            $jeton .= $alphabet[random_int(0, $max)];
        }

        return $jeton;
    }

    private function empreinte($jeton)
    {
        return hash('sha256', (string) $jeton);
    }

    /**
     * Ouvre une session pour un compte et rend le jeton brut : seule son empreinte est gardée.
     * Note la connexion, efface les sessions closes du compte et les plus anciennes au-delà de $_SESSION_MAX_PAR_COMPTE.
     */
    public function ouvrir($id_compte)
    {
        global $Mysql, $_SESSION_JOURS, $_SESSION_MAX_PAR_COMPTE;

        $idc = (int) $id_compte;
        $jeton = $this->genJeton(32);
        $Mysql->execute(
            "INSERT INTO e_session (jeton, id_compte, user_agent, ip, date_expiration) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))",
            array(
                $this->empreinte($jeton),
                $idc,
                isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255, 'UTF-8') : null,
                Limite::ip() === '' ? null : Limite::ip(),
                (int) $_SESSION_JOURS,
            ),
            'sissi'
        );
        $id = $Mysql->lastId();
        $Mysql->execute("UPDATE e_acces SET date_derniere_connexion = NOW() WHERE id_compte = ?", array($idc), 'i');

        $Mysql->execute("DELETE FROM e_session WHERE id_compte = ? AND date_expiration < NOW()", array($idc), 'i');
        $gardees = $Mysql->fetchAll(
            "SELECT id_session FROM e_session WHERE id_compte = ? ORDER BY id_session DESC LIMIT ?",
            array($idc, max(1, (int) $_SESSION_MAX_PAR_COMPTE)),
            'ii'
        );
        $derniere = (int) end($gardees)->id_session;
        $Mysql->execute("DELETE FROM e_session WHERE id_compte = ? AND id_session < ?", array($idc, $derniere), 'ii');

        $this->session = (object) array('id_session' => $id, 'id_compte' => $idc);

        return $jeton;
    }

    /**
     * Le parent de la requête, d'après son jeton : le compte (vue a_acces + e_acces), ou 401 (code 301 : le front
     * ferme la session). Une session expirée, trop ancienne ou antérieure à la révocation de l'accès est effacée.
     * L'expiration glisse à chaque visite, au plus une fois par heure. Ne dit rien de l'accès : voir exigerAcces().
     */
    public function exiger()
    {
        global $H, $Response, $Mysql, $_SESSION_JOURS, $_SESSION_MAX_JOURS;

        $brut = $H->getBearerToken();
        if (!is_string($brut) || !preg_match('/^[A-Za-z0-9]{32}$/', $brut)) {
            $Response->tokenExpired("Votre session est terminée : reconnectez-vous.");
        }
        $s = $Mysql->fetchOne(
            "SELECT id_session, id_compte, date_creation,
                    (date_expiration < NOW()) AS expiree,
                    TIMESTAMPDIFF(DAY, date_creation, NOW()) AS age,
                    TIMESTAMPDIFF(MINUTE, NOW(), date_expiration) AS reste
             FROM e_session WHERE jeton = ?",
            array($this->empreinte($brut)),
            's'
        );
        $compte = $s === null ? null : $this->compte($s->id_compte);
        if ($s !== null && ($compte === null
            || (int) $s->expiree === 1
            || (int) $s->age >= (int) $_SESSION_MAX_JOURS
            || ($compte->date_revocation !== null && $s->date_creation <= $compte->date_revocation))) {
            $Mysql->execute("DELETE FROM e_session WHERE id_session = ?", array((int) $s->id_session), 'i');
            $compte = null;
        }
        if ($compte === null) {
            $Response->tokenExpired("Votre session est terminée : reconnectez-vous.");
        }

        if ((int) $s->reste < (int) $_SESSION_JOURS * 1440 - 60) {
            $Mysql->execute(
                "UPDATE e_session SET date_expiration = LEAST(DATE_ADD(NOW(), INTERVAL ? DAY), DATE_ADD(date_creation, INTERVAL ? DAY)) WHERE id_session = ?",
                array((int) $_SESSION_JOURS, (int) $_SESSION_MAX_JOURS, (int) $s->id_session),
                'iii'
            );
        }
        $this->session = $s;

        return $compte;
    }

    /**
     * Le parent de la requête, si son accès est ouvert aujourd'hui. Sinon 403 (code 3) avec l'état de l'accès :
     * le front montre l'écran correspondant. Jamais 401 : la session, elle, est bonne.
     */
    public function exigerAcces()
    {
        global $Response;

        $compte = $this->exiger();
        $acces = $this->acces($compte);
        if ($acces['etat'] !== 'ouvert') {
            $Response->forbidden("Vos contenus ne sont pas accessibles pour le moment.", array('acces' => $acces));
        }

        return $compte;
    }

    public function fermer($id_session)
    {
        global $Mysql;

        $Mysql->execute("DELETE FROM e_session WHERE id_session = ?", array((int) $id_session), 'i');
    }

    /** Ferme les autres sessions du compte (changement de mot de passe) ; toutes si $sauf est null. */
    public function fermerAutres($id_compte, $sauf = null)
    {
        global $Mysql;

        if ($sauf === null) {
            $Mysql->execute("DELETE FROM e_session WHERE id_compte = ?", array((int) $id_compte), 'i');
        } else {
            $Mysql->execute("DELETE FROM e_session WHERE id_compte = ? AND id_session <> ?", array((int) $id_compte, (int) $sauf), 'ii');
        }
    }

    // LIEN D'ACCÈS ################################################

    /**
     * Lien d'accès présenté par un parent : array(jeton, compte) s'il est utilisable, sinon null.
     * Utilisable : connu, ni révoqué, ni expiré, ni déjà consommé. Un lien n'est jamais dit « expiré » ou
     * « déjà utilisé » : le front propose seulement d'en recevoir un autre.
     */
    public function lien($brut)
    {
        global $Mysql;

        if (!is_string($brut) || !preg_match('/^[A-Za-z0-9]{40}$/', $brut)) {
            return null;
        }
        $j = $Mysql->fetchOne(
            "SELECT j.id_jeton, j.id_compte, j.motif
             FROM a_jeton j LEFT JOIN e_jeton_utilise u ON u.id_jeton = j.id_jeton
             WHERE j.jeton = ? AND j.date_revocation IS NULL AND j.date_expiration > NOW() AND u.id_jeton IS NULL",
            array($this->empreinte($brut)),
            's'
        );
        if ($j === null) {
            return null;
        }
        $compte = $this->compte($j->id_compte);

        return $compte === null ? null : array($j, $compte);
    }

    /**
     * Consomme un lien d'accès : écrit le mot de passe, ferme toutes les sessions du compte et en ouvre une.
     * L'usage unique tient à la clé primaire de e_jeton_utilise : de deux demandes simultanées, une seule passe.
     * Retourne le jeton de session, ou null si le lien vient d'être consommé ailleurs.
     */
    public function consommer($jeton, $compte, $pass)
    {
        global $SQL, $Mysql;

        $SQL->begin_transaction();
        if ($Mysql->execute("INSERT IGNORE INTO e_jeton_utilise (id_jeton) VALUES (?)", array((int) $jeton->id_jeton), 'i') !== 1) {
            $SQL->rollback();

            return null;
        }
        $this->ecrireMotDePasse($compte->id_compte, $pass);
        $this->fermerAutres($compte->id_compte);
        $session = $this->ouvrir($compte->id_compte);
        $SQL->commit();

        // Le lien reçu par e-mail rouvre la connexion par mot de passe, quel que soit le nombre d'essais ratés
        Limite::effacer(Limite::cleEmail('cnx', $compte->email));
        Limite::effacer(Limite::cleEmail('cnxe', $compte->email));

        return $session;
    }
}

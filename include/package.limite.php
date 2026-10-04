<?php

//=======================================================================
// File:        package.limite.php
// Description: limiteur de l'appli des parents (table e_limite) : compteurs à fenêtre fixe, par clé et par adresse IP.
//              Sert à la connexion (essais ratés) et à la présentation des liens d'accès. Une clé ne contient jamais
//              un e-mail en clair : seulement son empreinte tronquée, la même pour un e-mail connu ou inconnu.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Limite
{
    public static function ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '';
    }

    /** Clé d'un compteur lié à un e-mail saisi : « <prefixe>:<24 hex de son empreinte> ». */
    public static function cleEmail($prefixe, $email)
    {
        return $prefixe . ':' . substr(hash('sha256', mb_strtolower(trim((string) $email), 'UTF-8')), 0, 24);
    }

    /** Nombre compté dans la fenêtre en cours, et secondes qui restent avant sa fin. array(nb, reste). */
    public static function lire($cle, $ip)
    {
        global $Mysql;

        $row = $Mysql->fetchOne(
            "SELECT nb, TIMESTAMPDIFF(SECOND, NOW(), date_fin) AS reste FROM e_limite WHERE cle = ? AND ip = ? AND date_fin >= NOW()",
            array($cle, $ip),
            'ss'
        );

        return $row === null ? array(0, 0) : array((int) $row->nb, max(1, (int) $row->reste));
    }

    /** Compte un fait. La fenêtre est fixe : un fait de plus ne la prolonge pas. */
    public static function compter($cle, $ip, $minutes)
    {
        global $Mysql;

        $Mysql->execute(
            "INSERT INTO e_limite (cle, ip, nb, date_fin) VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL ? MINUTE))
             ON DUPLICATE KEY UPDATE
                nb = IF(date_fin < NOW(), 1, nb + 1),
                date_debut = IF(date_fin < NOW(), NOW(), date_debut),
                date_fin = IF(date_fin < NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), date_fin)",
            array($cle, $ip, (int) $minutes, (int) $minutes),
            'ssii'
        );
    }

    public static function effacer($cle, $ip = null)
    {
        global $Mysql;

        if ($ip === null) {
            $Mysql->execute("DELETE FROM e_limite WHERE cle = ?", array($cle), 's');
        } else {
            $Mysql->execute("DELETE FROM e_limite WHERE cle = ? AND ip = ?", array($cle, $ip), 'ss');
        }
    }

    /**
     * Compte un appel et refuse (429) au-delà de $regle = array(max, minutes). Pour un endpoint dont chaque appel
     * compte, réussi ou non. À appeler avant toute lecture du corps et hors transaction.
     */
    public static function exiger($cle, $regle)
    {
        global $Response;

        $ip = self::ip();
        if ($ip === '') {
            return;
        }
        self::compter($cle, $ip, (int) $regle[1]);
        list($nb, $reste) = self::lire($cle, $ip);
        if ($nb > (int) $regle[0]) {
            $Response->rateLimitExceeded("Trop de demandes depuis cette adresse. Réessayez dans " . (int) ceil($reste / 60) . " min.", $reste);
        }
    }

    /** Efface de temps en temps les fenêtres closes depuis plus d'un jour (une requête sur cinquante). */
    public static function purger()
    {
        global $Mysql;

        if (random_int(1, 50) === 1) {
            $Mysql->execute("DELETE FROM e_limite WHERE date_fin < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    }
}

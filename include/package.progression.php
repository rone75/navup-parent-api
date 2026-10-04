<?php

//=======================================================================
// File:        package.progression.php
// Description: progression d'un parent sur un sujet (table e_progression) : où en est son écoute, et « terminé ».
//              « Terminé » est un geste du parent, jamais une déduction de l'écoute ; il se défait.
//              Chaque écriture augmente la version de la ligne ; une écriture partie d'une version dépassée est
//              refusée et reçoit l'état du serveur : un appareil resté ouvert n'écrase pas ce qu'un autre a fait depuis.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Progression
{
    public function lire($id_compte, $id_sujet)
    {
        global $Mysql;

        return $Mysql->fetchOne(
            "SELECT position, version, date_termine FROM e_progression WHERE id_compte = ? AND id_sujet = ?",
            array((int) $id_compte, (int) $id_sujet),
            'ii'
        );
    }

    public function sortie($p)
    {
        return array(
            'position' => $p === null ? 0 : (int) $p->position,
            'version' => $p === null ? 0 : (int) $p->version,
            'termine' => $p !== null && $p->date_termine !== null,
            'date_termine' => $p === null ? null : $p->date_termine,
        );
    }

    /** Note l'ouverture d'un sujet : il devient « en cours », et c'est lui que « Continuer » reprendra. */
    public function ouvrir($id_compte, $id_sujet)
    {
        global $Mysql;

        $Mysql->execute(
            "INSERT INTO e_progression (id_compte, id_sujet) VALUES (?, ?) ON DUPLICATE KEY UPDATE date_modif = NOW()",
            array((int) $id_compte, (int) $id_sujet),
            'ii'
        );
    }

    /**
     * Écrit la position d'écoute ou « terminé » ($termine : true, false, ou null pour ne pas y toucher).
     * $version : celle que le front a lue. Retourne array(progression, conflit) : en cas de conflit, rien n'est
     * écrit et la progression rendue est celle du serveur.
     */
    public function ecrire($id_compte, $id_sujet, $version, $position, $termine)
    {
        global $Mysql;

        $choix = $termine === null ? -1 : ($termine ? 1 : 0);
        $ecrit = $Mysql->execute(
            "UPDATE e_progression
             SET position = COALESCE(?, position),
                 date_termine = CASE ? WHEN 1 THEN COALESCE(date_termine, NOW()) WHEN 0 THEN NULL ELSE date_termine END,
                 version = version + 1,
                 date_modif = NOW()
             WHERE id_compte = ? AND id_sujet = ? AND version = ?",
            array($position === null ? null : (int) $position, $choix, (int) $id_compte, (int) $id_sujet, (int) $version),
            'iiiii'
        );

        return array($this->lire($id_compte, $id_sujet), $ecrit !== 1);
    }

    /** Sujets publiés terminés par un compte, et total des sujets publiés de sa formation. array(termines, sujets). */
    public function compte($compte)
    {
        global $Mysql;

        $r = $Mysql->fetchOne(
            "SELECT COUNT(*) AS sujets, COUNT(p.date_termine) AS termines
             FROM f_sujet s LEFT JOIN e_progression p ON p.id_sujet = s.id_sujet AND p.id_compte = ?
             WHERE s.id_formation = ? AND s.publie = 1",
            array((int) $compte->id_compte, (int) $compte->id_formation),
            'ii'
        );

        return array((int) $r->termines, (int) $r->sujets);
    }
}

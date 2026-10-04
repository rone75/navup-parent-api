<?php

//=======================================================================
// File:        package.programme.php
// Description: le programme d'un parent tel qu'il le voit : ses semaines (terminée, disponible, verrouillée), les
//              sujets publiés de chacune et où il en est, le sujet à reprendre, et le contenu d'un sujet.
//              Lecture seule sur ce que navup-api écrit : le déroulé vient de la vue a_semaine (le jour où une semaine
//              se débloque y est calculé une fois pour toutes), les sujets de f_sujet (publiés seulement), les
//              fichiers de f_fichier (prêts seulement ; le plus récent d'un rôle est celui que voit le parent).
//              Le jour vient de PHP : l'horloge du téléphone ne débloque rien.
//              Colonnes servies par liste explicite : jamais un brouillon, un chemin de fichier ni une empreinte.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Programme
{
    /** Semaines du programme d'un compte, dans l'ordre, avec le jour où chacune se débloque. */
    private function semaines($compte)
    {
        global $Mysql;

        return $Mysql->fetchAll(
            "SELECT id_semaine, numero, titre, description, date_deblocage FROM a_semaine WHERE id_compte = ? ORDER BY numero",
            array((int) $compte->id_compte),
            'i'
        );
    }

    /** Sujets publiés de la formation d'un compte, avec sa progression sur chacun. */
    private function sujets($compte)
    {
        global $Mysql;

        return $Mysql->fetchAll(
            "SELECT s.id_sujet, s.id_semaine, s.numero, s.titre, s.description, s.pochette,
                    p.position, p.version, p.date_termine, p.date_modif AS date_visite
             FROM f_sujet s
             LEFT JOIN e_progression p ON p.id_sujet = s.id_sujet AND p.id_compte = ?
             WHERE s.id_formation = ? AND s.publie = 1
             ORDER BY s.position, s.id_sujet",
            array((int) $compte->id_compte, (int) $compte->id_formation),
            'ii'
        );
    }

    /** Durée de l'audio de chaque sujet publié (secondes), par identifiant de sujet. */
    private function durees($compte)
    {
        global $Mysql;

        $durees = array();
        foreach ($Mysql->fetchAll(
            "SELECT f.id_sujet, f.duree FROM f_fichier f INNER JOIN f_sujet s ON s.id_sujet = f.id_sujet
             WHERE s.id_formation = ? AND s.publie = 1 AND f.role = 'audio' AND f.etat = 'pret' ORDER BY f.id_fichier",
            array((int) $compte->id_formation),
            'i'
        ) as $f) {
            // Le plus récent l'emporte (ordre croissant des identifiants)
            $durees[(int) $f->id_sujet] = $f->duree === null ? null : (int) $f->duree;
        }

        return $durees;
    }

    private function etatSujet($s)
    {
        if ($s->date_termine !== null) {
            return 'termine';
        }

        return $s->date_visite !== null ? 'en_cours' : 'a_commencer';
    }

    /**
     * L'accueil et le programme : la semaine en cours, les sujets terminés sur le total, le sujet à reprendre, la
     * prochaine semaine à venir, puis chaque semaine avec ses sujets.
     * - semaine `verrouillee` : son jour n'est pas venu ; ses sujets sont annoncés par leur titre, sans s'ouvrir ;
     * - `terminee` : débloquée, et tous ses sujets sont terminés ; `disponible` sinon.
     * Les totaux sont comptés ici : le front les affiche, il n'additionne rien.
     */
    public function vue($compte, $aujourdhui = null)
    {
        $jour = $aujourdhui === null ? date('Y-m-d') : $aujourdhui;
        $durees = $this->durees($compte);

        $parSemaine = array();
        foreach ($this->sujets($compte) as $s) {
            $parSemaine[(int) $s->id_semaine][] = $s;
        }

        $semaines = array();
        $enCours = null;
        $prochaine = null;
        $termines = 0;
        $total = 0;
        $reprise = null;     // le sujet ouvert le plus récemment, s'il n'est pas terminé
        $premier = null;     // à défaut, le premier sujet débloqué qui reste à faire

        foreach ($this->semaines($compte) as $w) {
            $ouverte = $w->date_deblocage <= $jour;
            if ($ouverte) {
                $enCours = (int) $w->numero;
            } elseif ($prochaine === null) {
                $prochaine = array('numero' => (int) $w->numero, 'date' => $w->date_deblocage);
            }

            $liste = array();
            $faits = 0;
            foreach (isset($parSemaine[(int) $w->id_semaine]) ? $parSemaine[(int) $w->id_semaine] : array() as $s) {
                $etat = $this->etatSujet($s);
                $total++;
                if ($etat === 'termine') {
                    $termines++;
                    $faits++;
                }
                $ligne = array(
                    'id_sujet' => (int) $s->id_sujet,
                    'numero' => (int) $s->numero,
                    'titre' => $s->titre,
                    'pochette' => $s->pochette,
                    'duree' => isset($durees[(int) $s->id_sujet]) ? $durees[(int) $s->id_sujet] : null,
                    'etat' => $ouverte ? $etat : 'verrouille',
                );
                $liste[] = $ligne;

                if ($ouverte && $etat !== 'termine') {
                    $candidat = array('id_sujet' => (int) $s->id_sujet, 'numero' => (int) $s->numero, 'titre' => $s->titre, 'pochette' => $s->pochette, 'semaine' => (int) $w->numero, 'etat' => $etat);
                    if ($premier === null) {
                        $premier = $candidat;
                    }
                    if ($s->date_visite !== null && ($reprise === null || $s->date_visite > $reprise['date_visite'])) {
                        $reprise = $candidat + array('date_visite' => $s->date_visite);
                    }
                }
            }

            $semaines[] = array(
                'id_semaine' => (int) $w->id_semaine,
                'numero' => (int) $w->numero,
                'titre' => $w->titre,
                'description' => $w->description,
                'etat' => !$ouverte ? 'verrouillee' : ((count($liste) > 0 && $faits === count($liste)) ? 'terminee' : 'disponible'),
                'date_deblocage' => $w->date_deblocage,
                'termines' => $faits,
                'sujets' => $liste,
            );
        }

        if ($reprise !== null) {
            unset($reprise['date_visite']);
        }

        return array(
            'aujourdhui' => $jour,
            'semaine' => $enCours,
            'sur' => count($semaines),
            'termines' => $termines,
            'sujets' => $total,
            'continuer' => $reprise !== null ? $reprise : $premier,
            'prochaine' => $prochaine,
            'semaines' => $semaines,
        );
    }

    /**
     * Un sujet que ce compte peut ouvrir aujourd'hui : publié, de sa formation, dans une semaine débloquée.
     * Retourne la ligne (sujet + semaine), ou array(null, raison) : `inconnu`, ou `verrouille` avec la date.
     */
    public function accessible($compte, $id_sujet, $aujourdhui = null)
    {
        global $Mysql;

        $jour = $aujourdhui === null ? date('Y-m-d') : $aujourdhui;
        $s = $Mysql->fetchOne(
            "SELECT s.id_sujet, s.id_semaine, s.numero, s.titre, s.description, s.pochette, s.position,
                    w.numero AS semaine_numero, w.titre AS semaine_titre, w.date_deblocage
             FROM f_sujet s INNER JOIN a_semaine w ON w.id_semaine = s.id_semaine AND w.id_compte = ?
             WHERE s.id_sujet = ? AND s.id_formation = ? AND s.publie = 1",
            array((int) $compte->id_compte, (int) $id_sujet, (int) $compte->id_formation),
            'iii'
        );
        if ($s === null) {
            return array(null, 'inconnu', null);
        }
        if ($s->date_deblocage > $jour) {
            return array(null, 'verrouille', $s->date_deblocage);
        }

        return array($s, null, null);
    }

    /** Fichier que voit le parent pour un rôle d'un sujet : le dernier prêt. */
    private function fichier($id_sujet, $role)
    {
        global $Mysql;

        return $Mysql->fetchOne(
            "SELECT id_fichier, type_mime, taille, duree, pages, empreinte FROM f_fichier
             WHERE id_sujet = ? AND role = ? AND etat = 'pret' ORDER BY id_fichier DESC LIMIT 1",
            array((int) $id_sujet, $role),
            'is'
        );
    }

    /**
     * Le contenu d'un sujet ouvert : sa fiche (une adresse signée par page, et le PDF), son audio (adresse signée,
     * durée), où en est l'écoute, et ses voisins pour passer de l'un à l'autre. $s : ligne rendue par accessible().
     */
    public function sujet($compte, $s, $id_session, $aujourdhui = null)
    {
        global $Mysql, $Progression, $_DOSSIER_MEDIAS;

        $jour = $aujourdhui === null ? date('Y-m-d') : $aujourdhui;
        $id = (int) $s->id_sujet;

        $audio = $this->fichier($id, 'audio');
        $fiche = $this->fichier($id, 'fiche');

        $ficheSortie = null;
        if ($fiche !== null) {
            $pages = array();
            $largeur = null;
            $hauteur = null;
            for ($n = 1; $n <= (int) $fiche->pages; $n++) {
                $pages[] = Media::url($fiche->id_fichier, $n, $id_session);
            }
            if (count($pages) > 0) {
                // Les dimensions de la première page laissent au front la place de chaque image avant son arrivée
                $taille = @getimagesize(rtrim($_DOSSIER_MEDIAS, '/') . '/' . $fiche->empreinte . '.p1.jpg');
                if (is_array($taille)) {
                    list($largeur, $hauteur) = $taille;
                }
            }
            $ficheSortie = array(
                'pages' => $pages,
                'largeur' => $largeur,
                'hauteur' => $hauteur,
                'pdf' => Media::url($fiche->id_fichier, 0, $id_session),
            );
        }

        // Voisins dans l'ordre du programme : semaine, puis position
        $voisins = $Mysql->fetchAll(
            "SELECT s.id_sujet, s.numero, s.titre, w.numero AS semaine, w.date_deblocage
             FROM f_sujet s INNER JOIN a_semaine w ON w.id_semaine = s.id_semaine AND w.id_compte = ?
             WHERE s.id_formation = ? AND s.publie = 1
             ORDER BY w.numero, s.position, s.id_sujet",
            array((int) $compte->id_compte, (int) $compte->id_formation),
            'ii'
        );
        $precedent = null;
        $suivant = null;
        foreach ($voisins as $i => $v) {
            if ((int) $v->id_sujet !== $id) {
                continue;
            }
            foreach (array('precedent' => $i - 1, 'suivant' => $i + 1) as $cote => $j) {
                if (isset($voisins[$j])) {
                    $$cote = array(
                        'id_sujet' => (int) $voisins[$j]->id_sujet,
                        'numero' => (int) $voisins[$j]->numero,
                        'titre' => $voisins[$j]->titre,
                        'semaine' => (int) $voisins[$j]->semaine,
                        'verrouille' => $voisins[$j]->date_deblocage > $jour,
                        'date_deblocage' => $voisins[$j]->date_deblocage,
                    );
                }
            }
        }

        return array(
            'id_sujet' => $id,
            'numero' => (int) $s->numero,
            'titre' => $s->titre,
            'description' => $s->description,
            'pochette' => $s->pochette,
            'semaine' => array('numero' => (int) $s->semaine_numero, 'titre' => $s->semaine_titre),
            'audio' => $audio === null ? null : array(
                'url' => Media::url($audio->id_fichier, 0, $id_session),
                'duree' => $audio->duree === null ? null : (int) $audio->duree,
                'taille' => (int) $audio->taille,
            ),
            'fiche' => $ficheSortie,
            'progression' => $Progression->sortie($Progression->lire($compte->id_compte, $id)),
            'precedent' => $precedent,
            'suivant' => $suivant,
        );
    }
}

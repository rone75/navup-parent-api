<?php

//=======================================================================
// File:        package.media.php
// Description: médias de la formation servis aux parents : l'audio d'un sujet, les pages de sa fiche (images) et son
//              PDF. Les fichiers sont dans $_DOSSIER_MEDIAS, hors du web, écrits par navup-api : cette API les lit
//              sans jamais y écrire.
//              Une balise <audio> ou <img> n'envoie pas d'en-tête Authorization : le droit de lire voyage dans
//              l'adresse, signée (HMAC) et liée à la session. Elle vaut de une à deux fenêtres ($_MEDIA_FENETRE) ;
//              la fenêtre est fixe pour que la même adresse soit redonnée et que le cache du navigateur serve.
//              La signature ne suffit pas : à chaque demande, la session, l'accès du compte, la publication du sujet
//              et le déblocage de sa semaine sont revérifiés.
//              Lecture par plages (Range → 206) : sans elle, un téléphone ne peut ni avancer dans un audio ni,
//              sur iOS, le lire du tout.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Media
{
    private static function signature($f, $p, $n, $e)
    {
        global $_CLE_MEDIA;

        return hash_hmac('sha256', "m1|$f|$p|$n|$e", (string) $_CLE_MEDIA);
    }

    /**
     * Adresse signée d'un média. $page : 0 pour le fichier lui-même (audio, PDF), N pour la page N d'une fiche.
     * L'expiration tombe à la fin de la fenêtre suivante : entre une et deux fenêtres de validité.
     */
    public static function url($id_fichier, $page, $id_session)
    {
        global $_PATH_API, $_MEDIA_FENETRE;

        $f = (int) $id_fichier;
        $p = (int) $page;
        $n = (int) $id_session;
        $fenetre = max(600, (int) $_MEDIA_FENETRE);
        $e = (intdiv(time(), $fenetre) + 2) * $fenetre;

        return rtrim($_PATH_API, '/') . '/v1/media/?f=' . $f . '&p=' . $p . '&n=' . $n . '&e=' . $e . '&s=' . self::signature($f, $p, $n, $e);
    }

    /** Paramètres d'une adresse signée encore valable : array(f, p, n), ou null. */
    public static function lire($get)
    {
        global $_CLE_MEDIA;

        foreach (array('f', 'p', 'n', 'e') as $cle) {
            if (!isset($get[$cle]) || !is_string($get[$cle]) || !ctype_digit($get[$cle]) || strlen($get[$cle]) > 12) {
                return null;
            }
        }
        if (!isset($get['s']) || !is_string($get['s']) || !preg_match('/^[a-f0-9]{64}$/', $get['s']) || !isset($_CLE_MEDIA) || strlen((string) $_CLE_MEDIA) < 32) {
            return null;
        }
        $f = (int) $get['f'];
        $p = (int) $get['p'];
        $n = (int) $get['n'];
        $e = (int) $get['e'];
        if ($e < time() || !hash_equals(self::signature($f, $p, $n, $e), $get['s'])) {
            return null;
        }

        return array($f, $p, $n);
    }

    /**
     * Le fichier demandé, si la session vit encore et si son compte peut le lire aujourd'hui : accès ouvert, sujet
     * publié de sa formation, semaine débloquée, fichier prêt. Une seule requête, sur des clés. Retourne la ligne, ou null.
     */
    public static function autorise($id_fichier, $id_session, $aujourdhui = null)
    {
        global $Mysql;

        $jour = $aujourdhui === null ? date('Y-m-d') : $aujourdhui;

        return $Mysql->fetchOne(
            "SELECT fi.id_fichier, fi.role, fi.type_mime, fi.pages, fi.empreinte, fi.chemin
             FROM e_session se
             INNER JOIN a_acces a ON a.id_compte = se.id_compte
             INNER JOIN f_fichier fi ON fi.id_fichier = ?
             INNER JOIN f_sujet su ON su.id_sujet = fi.id_sujet AND su.id_formation = a.id_formation AND su.publie = 1
             INNER JOIN a_semaine w ON w.id_compte = a.id_compte AND w.id_semaine = su.id_semaine
             WHERE se.id_session = ? AND se.date_expiration > NOW()
               AND (a.date_revocation IS NULL OR se.date_creation > a.date_revocation)
               AND a.etat = 'actif' AND a.date_debut <= ? AND a.date_fin_acces >= ?
               AND w.date_deblocage <= ?
               AND fi.etat = 'pret' AND fi.role IN ('audio', 'fiche')",
            array((int) $id_fichier, (int) $id_session, $jour, $jour, $jour),
            'iisss'
        );
    }

    /** Chemin sur le disque et type du média : le fichier (page 0) ou l'image d'une page de fiche. array(chemin, type, etag), ou null. */
    public static function fichier($f, $page)
    {
        global $_DOSSIER_MEDIAS;

        // Les noms viennent de la base, écrits par navup-api : ils sont tout de même contrôlés avant de toucher au disque
        if (!is_string($f->empreinte) || !preg_match('/^[a-f0-9]{64}$/', $f->empreinte) || !is_string($f->chemin) || !preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $f->chemin)) {
            return null;
        }
        $dossier = rtrim((string) $_DOSSIER_MEDIAS, '/');
        // L'étiquette ne porte qu'un morceau de l'empreinte : l'empreinte entière est le nom du fichier sur le disque
        $etag = '"' . (int) $f->id_fichier . '-' . substr($f->empreinte, 0, 16) . ($page > 0 ? '-p' . (int) $page : '') . '"';

        if ($page === 0) {
            return array($dossier . '/' . $f->chemin, (string) $f->type_mime, $etag);
        }
        if ($f->role !== 'fiche' || $page > (int) $f->pages) {
            return null;
        }

        return array($dossier . '/' . $f->empreinte . '.p' . (int) $page . '.jpg', 'image/jpeg', $etag);
    }

    /**
     * Envoie un fichier au navigateur et termine la requête : entier (200), une plage (206), ou rien de plus que
     * les en-têtes (HEAD, 304). Plusieurs plages dans une demande : le fichier entier, comme la norme le permet.
     * Aucune requête SQL ici : la connexion est fermée avant d'émettre, un téléchargement lent ne la retient pas.
     */
    public static function envoyer($chemin, $type, $etag)
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            http_response_code(404);
            exit();
        }
        $taille = filesize($chemin);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('Pragma');
        header_remove('Expires');
        header('Content-Type: ' . $type);
        header('Accept-Ranges: bytes');
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Content-Disposition: inline');

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit();
        }

        $debut = 0;
        $fin = $taille - 1;
        $plage = isset($_SERVER['HTTP_RANGE']) ? trim((string) $_SERVER['HTTP_RANGE']) : '';
        // Une plage conditionnelle (If-Range) sur une autre version du fichier : on renvoie le fichier entier
        if ($plage !== '' && isset($_SERVER['HTTP_IF_RANGE']) && trim((string) $_SERVER['HTTP_IF_RANGE']) !== $etag) {
            $plage = '';
        }
        if ($plage !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $plage, $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                // « -n » : les n derniers octets
                $debut = max(0, $taille - (int) $m[2]);
            } else {
                $debut = (int) $m[1];
                if ($m[2] !== '') {
                    $fin = min($fin, (int) $m[2]);
                }
            }
            if ($debut > $fin || $debut >= $taille) {
                http_response_code(416);
                header('Content-Range: bytes */' . $taille);
                exit();
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $debut . '-' . $fin . '/' . $taille);
        } else {
            http_response_code(200);
        }
        $longueur = $fin - $debut + 1;
        header('Content-Length: ' . $longueur);

        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
            exit();
        }

        set_time_limit(0);
        $h = fopen($chemin, 'rb');
        if ($h === false) {
            exit();
        }
        fseek($h, $debut);
        $reste = $longueur;
        while ($reste > 0 && !feof($h) && !connection_aborted()) {
            $bloc = fread($h, (int) min(65536, $reste));
            if ($bloc === false || $bloc === '') {
                break;
            }
            echo $bloc;
            flush();
            $reste -= strlen($bloc);
        }
        fclose($h);
        exit();
    }
}

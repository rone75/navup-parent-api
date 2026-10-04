-- Appli des parents NavUp : ses propres tables, dans la base `navup` de la Tour de contrôle (préfixe e_, « espace »).
-- Elles ne sont écrites que par navup-parent-api. navup-api les lit pour la fiche d'un dossier (accès créé, dernière
-- connexion, sujets terminés, préférence d'annonce) et les purge avec les dossiers d'essai.
-- À appliquer APRÈS navup-api/sql/050_parents.sql, avec un utilisateur qui a le droit de créer (celui de navup-api
-- en développement), jamais avec navup_parents. Rejouable.

USE navup;

-- Accès d'un compte à l'espace personnel : son mot de passe, créé par le lien reçu par e-mail.
-- Pas de ligne tant que le parent n'a pas créé son mot de passe. Un mot de passe antérieur à a_compte.date_revocation est nul.
CREATE TABLE IF NOT EXISTS e_acces (
  id_compte               INT UNSIGNED NOT NULL,
  mot_de_passe            VARCHAR(255) NOT NULL COMMENT 'bcrypt',
  date_mot_de_passe       DATETIME NOT NULL COMMENT 'dernière création ou modification',
  date_derniere_connexion DATETIME NULL DEFAULT NULL,
  date_accueil            DATETIME NULL DEFAULT NULL COMMENT 'écrans de bienvenue lus',
  annonce_semaine         TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'e-mail à chaque nouvelle semaine ; lu par Compte::annoncerSemaines() de navup-api',
  date_creation           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif              DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_compte),
  CONSTRAINT fk_e_acces_compte FOREIGN KEY (id_compte)
    REFERENCES a_compte (id_compte) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session d'un parent : seule l'empreinte du jeton est gardée. id_session entre dans la signature des adresses de médias.
CREATE TABLE IF NOT EXISTS e_session (
  id_session      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  jeton           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'empreinte SHA-256 (hex)',
  id_compte       INT UNSIGNED NOT NULL,
  user_agent      VARCHAR(255) NULL DEFAULT NULL,
  ip              VARCHAR(45) NULL DEFAULT NULL,
  date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_expiration DATETIME NOT NULL COMMENT 'glissante, bornée par la durée de vie absolue',
  PRIMARY KEY (id_session),
  UNIQUE KEY uk_e_session_jeton (jeton),
  KEY idx_e_session_compte (id_compte, date_creation),
  KEY idx_e_session_expiration (date_expiration),
  CONSTRAINT fk_e_session_compte FOREIGN KEY (id_compte)
    REFERENCES a_compte (id_compte) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Progression d'un compte sur un sujet : où l'écoute en est, et « terminé » (geste du parent, jamais déduit).
-- version : chaque écriture l'augmente ; une écriture partie d'une version dépassée est refusée (deux appareils).
CREATE TABLE IF NOT EXISTS e_progression (
  id_compte     INT UNSIGNED NOT NULL,
  id_sujet      INT UNSIGNED NOT NULL,
  position      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'secondes d''écoute',
  version       INT UNSIGNED NOT NULL DEFAULT 1,
  date_termine  DATETIME NULL DEFAULT NULL,
  date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'première ouverture du sujet',
  date_modif    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'dernière ouverture ou écoute : le sujet à reprendre est le plus récent',
  PRIMARY KEY (id_compte, id_sujet),
  KEY idx_e_progression_recent (id_compte, date_modif),
  CONSTRAINT fk_e_progression_compte FOREIGN KEY (id_compte)
    REFERENCES a_compte (id_compte) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_e_progression_sujet FOREIGN KEY (id_sujet)
    REFERENCES f_sujet (id_sujet) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lien d'accès consommé (a_jeton appartient à navup-api : l'appli des parents n'y écrit pas).
-- La clé primaire fait l'usage unique : seul le premier INSERT réussit.
CREATE TABLE IF NOT EXISTS e_jeton_utilise (
  id_jeton         INT UNSIGNED NOT NULL,
  date_utilisation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_jeton),
  CONSTRAINT fk_e_jeton_utilise_jeton FOREIGN KEY (id_jeton)
    REFERENCES a_jeton (id_jeton) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limiteur de l'appli des parents : essais de connexion (par e-mail et adresse IP, par adresse IP, par e-mail)
-- et vérifications de lien. La clé ne contient jamais un e-mail en clair, seulement son empreinte tronquée.
CREATE TABLE IF NOT EXISTS e_limite (
  cle        VARCHAR(40) NOT NULL,
  ip         VARCHAR(45) NOT NULL COMMENT 'vide pour un compteur indépendant de l''adresse',
  nb         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  date_debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_fin   DATETIME NOT NULL,
  PRIMARY KEY (cle, ip),
  KEY idx_e_limite_fin (date_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Appli des parents NavUp — rendez-vous de l'espace personnel (étape 6b de la Tour de contrôle).
-- Billet : ce que l'appli des parents remet à un parent connecté pour qu'il prenne, déplace ou annule un rendez-vous
-- auprès de navup-api, seule à écrire les rendez-vous. Cette API n'y fait qu'INSÉRER, après avoir vérifié la session
-- et l'accès ; navup-api le lit, revérifie la session et le compte, décide de sa durée (son réglage) et le purge.
-- Seule l'empreinte du billet est gardée. Il meurt avec la session qui l'a demandé (clé étrangère).
-- À appliquer APRÈS sql/100_espace.sql, avec l'utilisateur de navup-api en développement. Rejouable.
-- Puis, par un administrateur MariaDB : le droit INSERT sur cette table (sql/000_utilisateur.exemple.sql).

USE navup;

CREATE TABLE IF NOT EXISTS e_billet (
  id_billet      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  jeton          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'empreinte SHA-256 (hex)',
  id_compte      INT UNSIGNED NOT NULL,
  id_session     INT UNSIGNED NOT NULL,
  date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'la durée de vie se compte d''ici, par navup-api',
  PRIMARY KEY (id_billet),
  UNIQUE KEY uk_e_billet_jeton (jeton),
  KEY idx_e_billet_creation (date_creation),
  CONSTRAINT fk_e_billet_compte FOREIGN KEY (id_compte)
    REFERENCES a_compte (id_compte) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_e_billet_session FOREIGN KEY (id_session)
    REFERENCES e_session (id_session) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

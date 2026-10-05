-- Appli des parents NavUp — droits du parent sur ses données (étape 8 de la Tour de contrôle).
-- e_billet.objet : un billet « donnees » permet à navup-api de remettre au parent ses données (v1/public/donnees/) ;
--   cette API ne le délivre qu'après le mot de passe retapé.
-- e_demande : « Supprimer mon compte ». Cette API n'y fait qu'INSÉRER, après le mot de passe retapé, puis ferme l'espace
--   (sessions et mot de passe). navup-api la lit, ouvre une tâche, prévient l'administrateur ; l'effacement se fait depuis
--   la page RGPD de la Tour de contrôle, qui écrit ici la date de traitement et le dossier.
-- À appliquer APRÈS sql/110_billet.sql, avec l'utilisateur de navup-api en développement. Rejouable.
-- Puis, par un administrateur MariaDB : le droit INSERT sur e_demande (sql/000_utilisateur.exemple.sql).

USE navup;

ALTER TABLE e_billet
  ADD COLUMN IF NOT EXISTS objet ENUM('rdv','donnees') NOT NULL DEFAULT 'rdv' COMMENT 'ce que le billet ouvre' AFTER id_session;

CREATE TABLE IF NOT EXISTS e_demande (
  id_demande           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_compte            INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL une fois le compte effacé',
  type                 ENUM('suppression') NOT NULL DEFAULT 'suppression',
  date_creation        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  id_contact           INT UNSIGNED NULL DEFAULT NULL COMMENT 'écrit par navup-api : le dossier, gardé quand le compte est effacé',
  date_signalement     DATETIME NULL DEFAULT NULL COMMENT 'écrit par navup-api : avis envoyé à l''administrateur',
  date_traitement      DATETIME NULL DEFAULT NULL COMMENT 'écrit par navup-api : dossier effacé',
  id_users_traitement  INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (id_demande),
  KEY idx_e_demande_compte (id_compte),
  KEY idx_e_demande_traitement (date_traitement),
  CONSTRAINT fk_e_demande_compte FOREIGN KEY (id_compte)
    REFERENCES a_compte (id_compte) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_e_demande_users FOREIGN KEY (id_users_traitement)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

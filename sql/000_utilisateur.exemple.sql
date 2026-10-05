-- Utilisateur MariaDB de l'appli des parents : il ne lit que ce que l'appli doit voir et n'écrit que dans ses tables.
-- À exécuter par un administrateur MariaDB (root), APRÈS navup-api/sql/050_parents.sql, sql/100_espace.sql et sql/110_billet.sql :
--   cp sql/000_utilisateur.exemple.sql sql/000_utilisateur.local.sql   (ignoré par git)
--   remplacer <mot de passe> (le même que dans require/secret.php), puis : sudo mariadb < sql/000_utilisateur.local.sql
-- Rejouable : les droits sont retirés puis reposés. script-cgi/verifier-droits.php compare le résultat à cette liste.
--
-- Aucun droit sur d_contact, les données familiales, les ventes, les e-mails ni les utilisateurs internes :
-- le dossier ne se lit que par la vue a_acces, le déroulé du programme que par la vue a_semaine.

CREATE USER IF NOT EXISTS 'navup_parents'@'localhost' IDENTIFIED BY '<mot de passe>';
ALTER USER 'navup_parents'@'localhost' IDENTIFIED BY '<mot de passe>';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'navup_parents'@'localhost';

-- Lecture : le compte et son dossier (vue), le déroulé (vue), les liens d'accès, les sujets et leurs fichiers
GRANT SELECT ON navup.a_acces TO 'navup_parents'@'localhost';
GRANT SELECT ON navup.a_semaine TO 'navup_parents'@'localhost';
GRANT SELECT ON navup.a_jeton TO 'navup_parents'@'localhost';
GRANT SELECT ON navup.f_sujet TO 'navup_parents'@'localhost';
GRANT SELECT ON navup.f_fichier TO 'navup_parents'@'localhost';

-- Écriture : ses seules tables
GRANT SELECT, INSERT, UPDATE, DELETE ON navup.e_acces TO 'navup_parents'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON navup.e_session TO 'navup_parents'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON navup.e_progression TO 'navup_parents'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON navup.e_jeton_utilise TO 'navup_parents'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON navup.e_limite TO 'navup_parents'@'localhost';
-- Billet de rendez-vous : cette API l'insère, navup-api le lit et le purge (sql/110_billet.sql)
GRANT INSERT ON navup.e_billet TO 'navup_parents'@'localhost';
GRANT INSERT ON navup.e_demande TO 'navup_parents'@'localhost';

FLUSH PRIVILEGES;

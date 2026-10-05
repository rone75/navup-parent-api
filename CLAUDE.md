# CLAUDE.md

Guide pour Claude Code sur ce dépôt : API REST PHP 8 de l'appli des parents de NavUp Academy (espace personnel : accès, programme, médias, progression).
Front Angular 21 : `~/Documents/_DEV/navup-parent-front`. Tour de contrôle (outil interne) : `/var/www/navup-api` et `~/Documents/_DEV/navup-front` ; lire le `CLAUDE.md` de `navup-api`, dont ce dépôt reprend le style et dépend pour sa base.
Cahier de l'écosystème : `~/Documents/nabil/Cahier de charges NavUp Academy.pdf`.

## Partage des rôles

- **navup-api décide, cette API sert.** Ouvrir un compte, régler ses dates, le suspendre, révoquer un accès, créer un lien d'accès, envoyer un e-mail : tout cela est dans navup-api. Ici : vérifier un lien, tenir le mot de passe et les sessions, servir le programme et ses médias, noter la progression.
- **La base est le contrat.** Cette API ne partage aucun code avec navup-api et ne recopie aucun de ses réglages : ce qui décide de l'accès est dans la base. Elle lit les vues `a_acces` (le compte, ses dates, prénom, nom, e-mail) et `a_semaine` (le jour où chaque semaine se débloque), `a_jeton`, `f_sujet`, `f_fichier` ; elle écrit dans ses seules tables `e_*`. Elle n'a aucun droit sur `d_contact`, les données familiales, les ventes, les e-mails ni les utilisateurs internes (`sql/000_utilisateur.exemple.sql`, contrôlé par `script-cgi/verifier-droits.php`).
- navup-api lit les tables `e_*` (fiche d'un dossier : mot de passe créé, dernière connexion, sujets terminés, préférence d'e-mail) et les purge ; elle n'y écrit pas.
- **Rendez-vous** (étape 6b de la Tour) : ils s'écrivent dans navup-api, par ses seules méthodes. Cette API n'en lit ni n'en écrit aucun : elle délivre un **billet** (`v1/rendez-vous/billet/`, session vivante et accès ouvert exigés) que le parent présente à `navup-api/v1/public/rendez-vous/espace/`. Le billet est une ligne de `e_billet` (empreinte seule) sur laquelle cette API n'a que le droit `INSERT` ; navup-api le lit, revérifie la session et le compte, décide de sa durée et le purge. Pas de secret partagé entre les deux API, aucune règle de rendez-vous recopiée ici.

- **Données du parent** (étape 8 de la Tour) : « Télécharger mes données » et « Supprimer mon compte » passent par un billet et par une demande que navup-api traite ; cette API ne lit jamais le dossier.

## Architecture

- Pas de framework, pas de composer, pas d'autoload. Un dossier par ressource : `v1/<ressource>/index.php`. URL locale : `http://localhost/navup-parent-api/v1/<ressource>/`.
- `include/package.{mysql,response,header,saisie}.php` : copies de navup-api (une correction se reporte dans les deux). `package.limite.php` (limiteur, table `e_limite`), `package.session.php` (`Session` : compte, accès, mot de passe, sessions, lien), `package.programme.php` (`Programme`), `package.media.php` (`Media`), `package.progression.php` (`Progression`).
- `require/param.php` : réglages versionnés (durées de session, longueur du mot de passe, limiteur, fenêtre des médias). `require/secret.php` (hors dépôt) : `$_PROD`, `$_DB`, `$_CORS_ORIGINES`, `$_PATH_API`, `$_CLE_MEDIA`, `$_DOSSIER_MEDIAS`. Ne jamais le lire ni l'afficher.
- `sql/` : `100_espace.sql` (tables `e_*`), `110_billet.sql` (`e_billet`), `000_utilisateur.exemple.sql` (droits). `script-cgi/` : contrôles en lecture seule.
- `.htaccess` bloque `.git`, `include/`, `require/`, `sql/`, `script-cgi/`, les fichiers cachés et les `.sql .md .log`.

## Pattern d'un endpoint

Comme navup-api : includes explicites, `$H->cors('json')`, puis les globales que les classes lisent (`$Response`, `$Session`, `$Mysql`, `$SQL`, `$file_err`). Un endpoint de contenu commence par `$compte = $Session->exigerAcces();` (session valide et accès ouvert, sinon 401 ou 403 avec l'état) ; un endpoint qui ne dépend pas de l'accès par `$Session->exiger()`. `v1/media/` fait exception : ni CORS ni Bearer, tout est dans l'adresse signée.

## Règles

- Requêtes préparées obligatoires ; aucune interpolation de variable dans une requête SQL.
- **Le jour vient de PHP** (`date('Y-m-d')`, Europe/Paris), jamais de l'appareil ni de `CURDATE()`.
- Une erreur métier sur un parent connecté répond **400** ; un accès fermé répond **403 code 3** avec `acces` ; **401 code 301** seulement quand la session n'est plus bonne (le front la vide), 401 code 2 pour de mauvais identifiants.
- Aucun message ne dit si un e-mail a un compte. Aucun compte ne se verrouille : seul le limiteur ferme, un temps, la connexion par mot de passe.
- Mot de passe : jamais nettoyé ni tronqué, bcrypt (`password_hash`), 72 octets au plus. Jetons : `random_int`, seule leur empreinte SHA-256 est gardée.
- Un lien d'accès ne passe jamais dans une adresse (journal d'accès) : corps de requête seulement. Son usage unique tient à `INSERT IGNORE` dans `e_jeton_utilise`, dans la transaction qui écrit le mot de passe.
- Colonnes servies par liste explicite. Jamais : un sujet non publié, un chemin de fichier, une empreinte entière, un prix.
- `v1/media/` n'écrit rien (ni limiteur, ni prolongation de session) et ferme la base avant d'émettre. `Content-Length` ne s'écrit que dans `Media::envoyer()`.
- Cette API ne lance aucun programme et n'écrit aucun fichier : le rendu des fiches et la conversion des audios sont dans navup-api.
- Aucune donnée personnelle dans un mail d'erreur ni dans un journal. Messages au parent en français, sans jargon.
- Compatibilité PHP 8.2+.

## Vérifier

```bash
php script-cgi/verifier-droits.php
php script-cgi/verifier-espace.php
```

Puis, dans `navup-parent-front` : `npm run parcours` (accès, limiteur, programme, médias par plages, progression, états d'accès, achat, écoute dans le navigateur).

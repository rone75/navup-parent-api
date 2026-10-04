# navup-parent-api

API REST PHP 8 de l'appli des parents de NavUp Academy : l'espace personnel où un parent inscrit suit son programme (un audio et sa fiche pratique par sujet, des semaines qui s'ouvrent une à une). Front Angular : `~/Documents/_DEV/navup-parent-front`.

Elle partage la base `navup` avec la Tour de contrôle (`/var/www/navup-api`), qui reste seule à décider de qui a accès : c'est elle qui ouvre le compte au paiement, règle ses dates, crée les liens d'accès et envoie tous les e-mails. Cette API **authentifie le parent et lui sert ses contenus**, avec un utilisateur MariaDB qui ne lit que ce qu'elle doit voir.

Même style maison que `navup-api` : pas de framework, pas de composer, un dossier par ressource (`v1/<ressource>/index.php`), classes dans `include/`. Les règles de code sont dans `CLAUDE.md`.

## Installation

Prérequis : Apache + php-fpm (DocumentRoot `/var/www`, `AllowOverride All`), PHP 8.2 ou plus, la base `navup` de la Tour de contrôle à jour.

1. Le schéma, avec un utilisateur qui a le droit de créer (celui de navup-api en développement), dans cet ordre :

   ```bash
   mariadb -unavup -p navup < /var/www/navup-api/sql/050_parents.sql   # colonnes d'accès, a_jeton, vues a_acces et a_semaine
   mariadb -unavup -p navup < sql/100_espace.sql                       # tables e_* de l'appli
   ```

2. L'utilisateur restreint de l'appli, par un administrateur MariaDB :

   ```bash
   cp sql/000_utilisateur.exemple.sql sql/000_utilisateur.local.sql    # ignoré par git ; y écrire le mot de passe
   sudo mariadb < sql/000_utilisateur.local.sql
   ```

3. `require/secret.php` d'après `secret.exemple.php` : ce mot de passe, les origines du front, l'adresse publique de l'API (`$_PATH_API`), la clé de signature des médias (`$_CLE_MEDIA`, 32 octets tirés au hasard), le dossier des médias de navup-api.

4. Contrôler :

   ```bash
   php script-cgi/verifier-droits.php    # les droits sont exactement ceux prévus, les tables internes sont refusées
   php script-cgi/verifier-espace.php    # cohérence des tables e_*
   ```

L'API répond alors sur `http://localhost/navup-parent-api/v1/`. Dans la Tour de contrôle, `$_APP_PARENTS_URL` (adresse du front des parents) allume les liens d'accès et l'e-mail « nouvelle semaine ».

## Format des réponses

JSON préfixé par `)]}',` et un saut de ligne (retiré nativement par Angular). Succès : `{"success": true, …}`. Erreur : `{"success": false, "message": "…", "code": N}` : 1 validation (400), 2 identifiants (401), 3 accès fermé (403, avec `acces`), 301 session terminée (401), 404, 429 avec `retry_after`.

## Authentification

`Authorization: Bearer base64(7 caractères + jeton + 4 caractères)`, comme l'outil interne. Le mot de passe voyage enrobé : 18 caractères + base64 + 9 caractères. Une session dure 30 jours glissants, 90 au plus ; cinq appareils par compte.

## Endpoints

| Méthode et chemin | Rôle | Accès |
|---|---|---|
| `POST v1/acces/verification/` `{jeton}` | le lien reçu par e-mail est-il utilisable ? → `{prenom, motif}` | public, limité par IP |
| `POST v1/acces/` `{jeton, pass}` | choisir son mot de passe par le lien ; le lien est consommé, les autres sessions tombent → `{token, moi}` | public, limité par IP |
| `POST v1/session/` `{email, pass}` | connexion → `{token, moi}` | public, limité |
| `GET v1/session/` | le parent, l'état de son accès, ses préférences | session |
| `DELETE v1/session/` | déconnexion | session |
| `PUT v1/session/mot-de-passe/` `{actuel, nouveau}` | changer son mot de passe ; les autres appareils sont déconnectés | session |
| `PUT v1/profil/` `{annonce_semaine?, accueil_lu?}` | e-mail à chaque nouvelle semaine ; mots de bienvenue lus | session |
| `GET v1/programme/` | la semaine en cours, les totaux, le sujet à reprendre, les semaines et leurs sujets | accès ouvert |
| `GET v1/sujets/` `?id=` | un sujet : pages de la fiche et audio en adresses signées, progression, voisins | accès ouvert, semaine débloquée |
| `PUT v1/progression/` `{id_sujet, version, position?, termine?}` | position d'écoute, « terminé » | accès ouvert |
| `GET`, `HEAD v1/media/` `?f=&p=&n=&e=&s=` | l'audio, le PDF, ou la page `p` d'une fiche | adresse signée |

Règles tenues par l'API :

- **Se connecter n'est pas avoir accès.** La connexion et le choix du mot de passe réussissent quel que soit l'état du compte. `moi.acces.etat` vaut `ouvert`, `pas_commence`, `suspendu` ou `ferme` ; les endpoints de contenu répondent 403 (code 3) avec cet état, jamais 401.
- **Le serveur décide de ce qui est débloqué.** Accès ouvert : compte actif, et le jour (celui de PHP) entre `date_debut` et `date_fin_acces`. Semaine débloquée : `date_deblocage` de la vue `a_semaine` atteinte. Sujet visible : publié. Fichier servi : le dernier prêt de son rôle.
- **Lien d'accès** : créé par la Tour de contrôle (`a_jeton`), à usage unique (clé primaire de `e_jeton_utilise`), il voyage dans le corps des requêtes, jamais dans une adresse. Un lien inconnu, expiré, annulé ou déjà utilisé reçoit la même réponse.
- **Connexion** : un seul message pour un e-mail inconnu, un compte sans mot de passe et un mauvais mot de passe, au même temps de réponse. **Aucun compte ne se verrouille** : après trop d'essais ratés (5 par e-mail et adresse IP en 15 minutes, 20 par adresse IP, 30 par e-mail en une heure), la connexion par mot de passe répond 429 ; le lien par e-mail reste utilisable et remet les compteurs à zéro.
- **Révocation** : une session ou un mot de passe antérieurs à `a_compte.date_revocation` ne valent plus rien (e-mail du dossier modifié, « Réinitialiser l'accès » dans la Tour de contrôle).
- **Médias** : jamais servis par Apache. L'adresse est signée (HMAC) et liée à la session, valable de 6 à 12 heures par fenêtre fixe ; à chaque demande, la session, l'accès, la publication du sujet et le déblocage de sa semaine sont revérifiés. Lecture par plages (`Range` → 206, 416 hors fichier), `ETag`, cache privé. Tout refus est un 403 sans explication.
- **Progression** : chaque écriture augmente la version de la ligne ; une écriture partie d'une version dépassée n'est pas faite, `conflit` est vrai et la réponse porte l'état du serveur. « Terminé » ne se déduit jamais de l'écoute.
- **Colonnes servies par liste explicite** : aucun brouillon, aucun chemin de fichier, aucune empreinte entière ne sort.

## Tests manuels

Les contrôles automatisés sont dans le front (`npm run parcours`, `npm run captures`). Ils s'appuient sur trois scripts de la Tour de contrôle, refusés en production : `script-cgi/essai-parent.php` (un parent d'essai en `essai.…@navup.local`, placé où l'on veut dans son programme, et son lien d'accès), `script-cgi/essai-publication.php` (publie des sujets pour le temps d'un contrôle, puis les rend à leur état) et `script-cgi/purge-essais.php`.

```bash
php /var/www/navup-api/script-cgi/essai-publication.php --publier=1-11
php /var/www/navup-api/script-cgi/essai-parent.php --debut=-16 --appli=http://127.0.0.1:4201/   # → lien d'accès
curl -s -D - -o /dev/null -r 0-1 '<adresse d'un audio>'    # 206, Content-Range: bytes 0-1/N, Content-Length: 2
php /var/www/navup-api/script-cgi/essai-publication.php --retablir && php /var/www/navup-api/script-cgi/purge-essais.php
```

## Mise en production

- **Un pool php-fpm à part pour cette API**, avec son utilisateur Unix et `open_basedir` limité à ce dépôt et au dossier des médias. Tant que les deux API partagent un pool, cette API peut lire `require/secret.php` de la Tour de contrôle : l'utilisateur MariaDB restreint ne protège alors que d'une injection SQL. Dans ce pool : `disable_functions = exec,shell_exec,system,passthru,popen,proc_open` (cette API ne lance aucun programme).
- `require/secret.php` : `$_PROD = 1`, l'hôte du front dans `$_CORS_ORIGINES`, `$_PATH_API` en https, une clé de médias propre à la production ; `chmod 640`.
- **Journal d'accès sans chaîne de requête** pour cet hôte (`%U` au lieu de `%r`) : l'adresse d'un média porte sa signature.
- `.htaccess` : `SetEnv ap_trust_cgilike_cl 1` doit rester (depuis Apache 2.4.59, `Content-Length` est retiré des réponses de PHP sans lui, et la lecture audio en a besoin).
- HTTPS obligatoire. Servir l'API sous la même origine que le front (`Alias /api`) évite les requêtes de contrôle préalable du navigateur.
- Le dossier des médias doit rester lisible par le pool de cette API, sans droit d'écriture.

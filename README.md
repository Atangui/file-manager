# Filora — Gestionnaire de fichiers & partage sécurisé pour WordPress

*Par Charles A.*

Filora est un gestionnaire de fichiers moderne, rapide et sécurisé pour WordPress, avec des **liens de partage façon Google Drive** pour vos clients et partenaires.

Il reprend toutes les fonctionnalités de Filester (navigation dans l’arborescence WordPress, upload, édition de code, archives, restrictions par rôle, corbeille…) mais il a été **réécrit de zéro** : pas de jQuery, pas d’elFinder, **aucune commande shell** (Filester a eu plusieurs CVE à cause d’elFinder et des commandes système).

---

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Partage sécurisé (façon Drive)](#partage-sécurisé-façon-drive)
- [Les « angles morts » couverts](#les-angles-morts-couverts)
- [Installation](#installation)
- [Rôles, dossiers privés et portail client](#rôles-dossiers-privés-et-portail-client)
- [Réglages conseillés en production](#réglages-conseillés-en-production)
- [WP-CLI](#wp-cli)
- [Hooks pour développeurs](#hooks-pour-développeurs)
- [Développement](#développement)
- [Architecture](#architecture)

---

## Fonctionnalités

**Interface**
- Application monopage ultra légère (Preact + signals, compilée avec esbuild) : ~11 Ko gzip au chargement, le reste (éditeur, réglages, outils…) est chargé à la demande.
- Thème clair / sombre / comme l’ordinateur (sélecteur en bas de la barre latérale et dans le menu **Affichage** de la barre du haut), densité compacte, design moderne et fluide, responsive (mobile et tablette).
- Liste ou grille **virtualisées** (des dizaines de milliers de fichiers restent fluides), miniatures d’images générées et mises en cache côté serveur.
- Glisser-déposer : fichiers **et dossiers** depuis l’ordinateur, déplacement interne vers un dossier, le fil d’Ariane ou l’arborescence (Alt/Ctrl = copier).
- Menu contextuel, sélection multiple (Ctrl/Cmd, Maj), raccourcis clavier, **palette de commandes** (Ctrl/Cmd + K) pour tout faire au clavier.
- Barre latérale avec **Accès rapides** (raccourci vers le **Coffre-fort** et vos favoris : clic droit sur un dossier → « Ajouter aux favoris »), **dossiers récents**, arborescence et jauge de quota (pour les rôles qui en ont un).
- Panneau de détails (aperçu, métadonnées, URL publique, liens de partage, versions).
- Prévisualisation rapide (barre d’espace) : images, vidéos, audio, PDF, texte/code, contenu des ZIP, polices.

**Gestion des fichiers**
- Créer, renommer, dupliquer, copier, couper/coller, déplacer, supprimer, télécharger (ZIP automatique pour plusieurs éléments).
- **Upload par morceaux** (chunks) : les limites PHP `upload_max_filesize` / `post_max_size` ne bloquent plus les gros fichiers ; reprise automatique en cas de coupure réseau ; upload de dossiers complets ; upload depuis une URL (admins, protégé contre le SSRF).
- **Éditeur de code** (CodeMirror fourni par WordPress, chargé uniquement à l’ouverture) : coloration syntaxique, rechercher/remplacer, retour à la ligne, Ctrl+S.
- **Vérification de la syntaxe PHP avant l’enregistrement** : une faute de frappe dans `functions.php` ne casse plus le site.
- Enregistrement **atomique** + **détection des conflits** (si quelqu’un a modifié le fichier entre-temps).
- **Historique des versions** automatique avec **comparaison (diff)** et restauration en un clic.
- **Corbeille** privée avec « Annuler » immédiat, restauration, purge automatique.
- Archives ZIP : compresser, extraire (avec protections Zip-Slip, liens symboliques et « zip bombs »).
- Outils image : rotation, symétrie, redimensionnement, conversion WebP/AVIF/JPEG/PNG (via l’éditeur d’images de WordPress).
- Permissions (chmod) avec matrice de cases à cocher, récursif fichiers/dossiers.
- Recherche par nom (y compris `*.php`) **et dans le contenu des fichiers**.

**Sécurité & administration**
- Journal d’activité complet (qui, quoi, quand, IP) filtrable, export CSV, conservation configurable, IP anonymisables (RGPD).
- Outils de sécurité : score de configuration, **vérification de l’intégrité du cœur WordPress** (sommes de contrôle officielles), détection des **fichiers PHP dans uploads** (signe n°1 de piratage), fichiers récemment modifiés avec détection de motifs de backdoor, analyse de l’espace disque.
- **Mode « sudo »** : les actions sensibles redemandent le mot de passe (protège même si un cookie de session admin est volé).
- Respect de `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`, mode lecture seule global.
- Gestionnaire disponible aussi **en front-end** via le shortcode `[filora]` (portail client).
- Traduit en **français** (gettext classique, fonctionne sur toutes les versions de WordPress), prêt pour d’autres langues, compatible RTL.

## Partage sécurisé (façon Drive)

Clic droit → **Partager…** sur un fichier ou un dossier :

| Mode | Ce que voit le destinataire |
|---|---|
| **Lecture seule** | Navigation et aperçus, sans bouton de téléchargement |
| **Consultation et téléchargement** | Aperçus, téléchargement fichier par fichier ou « Tout télécharger » (ZIP) |
| **Collaboration** | + peut téléverser des fichiers dans le dossier |
| **Demande de fichiers** | Peut seulement **envoyer** des fichiers ; ne voit jamais le contenu du dossier (seulement ses propres envois) |

Options par lien : **expiration**, **mot de passe** (générateur intégré), **accès réservé à des adresses e-mail** (code à usage unique envoyé par e-mail, jokers `*@entreprise.com` acceptés), **limite de téléchargements**, **liste d’IP autorisées**, message d’accueil, notifications e-mail (visites, téléchargements, dépôts), nom affiché, types de fichiers acceptés pour les dépôts.

Le lien est affiché avec un **QR code**, un bouton « Envoyer par e-mail » et un rappel d’envoyer le mot de passe **par un autre canal**. Chaque lien peut être désactivé, régénéré (l’ancienne URL meurt instantanément), ses visiteurs déconnectés, son compteur remis à zéro, et son activité consultée.

La page publique est pensée pour des **destinataires non techniques** (clients, partenaires, commerciaux) : un espace de consultation, pas un explorateur de fichiers.

- Titre, message de l’expéditeur avec son avatar, et des repères simples : « 4 dossiers · 3 fichiers », « Disponible jusqu’au 2 novembre ».
- **Dossiers illustrés** par les photos qu’ils contiennent, avec un résumé (« 6 photos », « 2 fichiers »).
- **Documents dessinés comme des pages** colorées selon leur type (PDF, tableur, présentation…), noms sans extension, types en langage courant, aucune date ni permission technique.
- **Galerie** automatique quand un dossier contient surtout des photos ; recherche sans accents ; vue vignettes ou liste.
- **Visionneuse plein écran** (photos, vidéos, PDF, textes) avec flèches, balayage tactile et un bouton « Télécharger » toujours visible ; quand l’aperçu est impossible, le message explique simplement quoi faire.
- **Langue du visiteur** : la page s’affiche dans la langue de son navigateur si une traduction existe (un partenaire anglophone voit la page en anglais sur un site français) ; les notifications envoyées au propriétaire restent dans la langue du site.
- **Autonome** (aucun code du thème n’est chargé), rapide, responsive, mode clair/sombre automatique, couleur et logo de votre marque.

## Les « angles morts » couverts

Ce que la plupart des gestionnaires de fichiers et outils de partage oublient :

**Partage**
- Les **jetons** (192 bits aléatoires) ne sont stockés qu’en **hash** dans la base ; une copie chiffrée (clé dérivée de `wp-config.php`, jamais en base) permet de réafficher le lien. Une fuite de la base de données ne révèle donc aucun lien.
- **Rotation des clés de sécurité** de WordPress après un incident : les liens continuent de fonctionner.
- **Fuite par l’en-tête Referer** : `Referrer-Policy: no-referrer` sur toutes les pages de partage.
- **Cache** (WP Rocket, LiteSpeed, Cloudflare…) : en-têtes `no-store`, `DONOTCACHEPAGE`, désactivation LiteSpeed — une page de partage n’est jamais mise en cache ni servie après révocation.
- **Indexation** : `noindex, nofollow` partout.
- **Robots d’aperçu** (Slack, Teams, Outlook Safe Links, WhatsApp…) : ils **ne consomment pas** les limites de téléchargement ni les vues. Les requêtes `HEAD` et les reprises (Range) non plus.
- **Contourner une limite de téléchargement via l’aperçu** : les aperçus sont désactivés quand une limite est définie.
- **URL directe du fichier** : un fichier partagé dans `uploads` reste accessible par son URL directe pour qui la devine. Filora le signale et propose de le déplacer dans le **Coffre-fort** (dossier bloqué par `.htaccess` / `web.config`), avec **auto-test** du serveur et l’extrait de configuration **Nginx** à copier si `.htaccess` est ignoré.
- **Exécution de code** : les fichiers partagés sont toujours **diffusés par PHP** (`Content-Disposition`, `nosniff`, CSP `sandbox`) — jamais exécutés, même un `.php` ou un `.html` ; un SVG ne peut pas lancer de JavaScript sur votre domaine.
- **Fichiers sensibles** (`wp-config.php`, `.env`, dumps SQL, clés, `.git`…) et fichiers cachés : impossibles à partager et invisibles dans les dossiers partagés.
- **Dossiers système** : impossible de partager la racine WordPress, `wp-admin`, `wp-includes`, `wp-content`, les plugins ou les thèmes.
- **Renommage / déplacement** d’un fichier partagé dans Filora : le lien suit automatiquement. Utilisateur supprimé ou ayant perdu ses droits : ses liens se désactivent.
- **Force brute** sur le mot de passe, les codes e-mail, les jetons : limitation de débit par IP, codes à 5 essais, réponses identiques que l’e-mail soit autorisé ou non (pas d’énumération).
- **CSRF** : jeton signé sur tous les formulaires publics, en-tête personnalisé obligatoire pour les envois de fichiers, cookies `HttpOnly` + `SameSite=Lax` (+ `Secure` en HTTPS).
- **Sessions visiteurs** signées (HMAC) et **révoquées** dès qu’on change le mot de passe ou la liste d’e-mails.
- **Usurpation d’IP** derrière un proxy : `X-Forwarded-For` n’est jamais cru par défaut (réglage explicite pour Cloudflare / load balancer).
- **Dépôts de visiteurs** : liste blanche d’extensions, refus des types exécutables ou capables de lancer du JavaScript (PHP, HTML, SVG…), vérification que le contenu correspond à l’extension, jamais d’écrasement, quotas de taille.
- **Mode « Demande de fichiers »** : chaque visiteur ne voit que ses propres envois.
- **Limite de téléchargements contournée par les aperçus ou les miniatures** : quand une limite est fixée, aperçus et miniatures sont désactivés (une miniature peut être le fichier original).
- **Mots de passe de partage** d’au moins 8 caractères, tentatives limitées par IP.

**Gestion de fichiers**
- **Liens symboliques** pointant hors du dossier racine : ignorés.
- **Double extension** (`shell.php.jpg` exécuté par Apache, `page.html.bak` servi comme HTML) et **point final** (`page.html.`) : chaque segment du nom est contrôlé. Fichiers de configuration serveur (`.htaccess`, `.user.ini`, `php.ini`, `web.config`) refusés pour les non-admins sur **tous** les chemins d’écriture : upload, création, renommage, copie, extraction ZIP, sauvegarde.
- **Contourner l’upload en renommant** un `.txt` en `.php` (faille historique des gestionnaires de fichiers) : bloqué.
- **Contourner la liste des types autorisés via un ZIP** : les fichiers extraits suivent les mêmes règles que les uploads (types autorisés du rôle, contenu vérifié).
- **Copier un dossier** ne fait jamais apparaître les fichiers cachés/sensibles qu’il contient ailleurs.
- **Supprimer** la racine WordPress, `wp-admin`, `wp-includes`, `wp-content`, le thème actif ou Filora lui-même : impossible depuis l’interface.
- **Corbeille et versions** stockées dans un dossier privé au nom aléatoire, bloqué en HTTP (ou hors de la racine web avec `FILORA_PRIVATE_DIR`) : un `wp-config.php` supprimé ne devient jamais téléchargeable.
- **Shortcode front-end** : il n’accepte **aucun chemin** (un contributeur pourrait sinon s’ouvrir la racine du serveur) ; le dossier vient toujours du profil du rôle.
- **Multisite** : seuls les super-admins ont l’accès complet ; les admins de site doivent recevoir un profil de rôle.
- **Encodage** : les fichiers non UTF-8 (Windows-1252) sont édités et réenregistrés sans être corrompus.
- **Export CSV** protégé contre l’injection de formules dans Excel.

## Installation

1. Téléchargez le ZIP de la dernière version (ou lancez `npm run zip`, voir plus bas) puis **Extensions → Ajouter → Téléverser une extension**.
   Vous pouvez aussi cloner ce dépôt directement dans `wp-content/plugins/filora` : les fichiers compilés (`assets/dist`) sont versionnés, aucune compilation n’est nécessaire.
2. Activez **Filora**. Un menu **Fichiers** apparaît dans l’administration.
3. Ouvrez **Fichiers → Outils de sécurité** pour vérifier votre configuration (HTTPS, protection du Coffre-fort…).

**Prérequis** : WordPress 5.8+, PHP 7.4+ (8.1+ recommandé). Extension PHP `zip` pour les archives, GD ou Imagick pour les miniatures. Aucun service externe, aucune police Google, aucun CDN : rien ne sort de votre serveur (RGPD).

**Nginx** : `.htaccess` est ignoré. Ajoutez la règle affichée dans **Outils de sécurité** (elle bloque `uploads/filora-vault` et le dossier privé), par exemple :

```nginx
location ~* ^/wp-content/uploads/filora-(vault|private-[a-f0-9]+)(/|$) {
    deny all;
    return 404;
}
```

**Encore plus sûr** : placez le stockage privé (corbeille, versions, fichiers temporaires) hors de la racine web dans `wp-config.php` :

```php
define( 'FILORA_PRIVATE_DIR', '/home/monsite/filora-private' );
```

## Rôles, dossiers privés et portail client

**Réglages → Accès et rôles** permet de donner à n’importe quel rôle son propre espace, en un clic grâce à des **modèles** :

| Modèle | Pour qui | Ce que le rôle peut faire |
|---|---|---|
| **Consultation et téléchargement** | Personnes qui consultent | Voir les dossiers propres au site (jamais WordPress) et télécharger |
| **Dépôt de documents** *(appliqué aux Contributeurs sur une nouvelle installation)* | Équipe qui met en ligne des documents | + déposer des PDF, documents Office et images (20 Mo max) et **remplacer un fichier par une nouvelle version en gardant son adresse web** ; ni suppression, ni renommage, ni déplacement |
| **Gestion des documents** | Équipe de confiance | + créer des dossiers, renommer, déplacer, supprimer (vers la corbeille), créer des liens de partage |
| **Espace personnel (portail client)** | Clients, partenaires | Un dossier privé par utilisateur dans le Coffre-fort, quota de 1 Go |

Les modèles qui partent de la racine du site masquent **WordPress par des motifs** et non par une liste figée : `wp-admin`, `wp-includes`, `wp-content`, tout fichier `.php`, les fichiers cachés (`.htaccess`, `.env`, `.git`…), les journaux, les archives et sauvegardes (`*.zip`, `*.wpress`, `backup*`…). Un fichier ajouté plus tard (nouvelle sauvegarde, `wp-cli.phar`…) reste donc invisible.

Angles morts pris en compte :
- **Mise à jour d’un document** : « Remplacer par une nouvelle version… » (clic droit) garde le nom et donc l’adresse web, la version précédente est conservée (versions, ou corbeille pour les gros fichiers). Sans ce droit, un dépôt n’écrase **jamais** un fichier existant : il est renommé.
- **Rôle attribué aux inscrits** (souvent « Abonné ») : un avertissement s’affiche, en rouge si l’inscription est ouverte à tous.
- Une **mise à jour du plugin ne donne jamais de nouvel accès** : le modèle par défaut ne s’applique qu’à une nouvelle installation.
- **Exporter / Importer** (en haut des Réglages) pour reprendre la même configuration sur un autre site. Les chemins utilisent des variables (`{ABSPATH}`…), donc rien n’est propre à un serveur ; la liste des administrateurs du site cible est conservée, les rôles absents et les dossiers inexistants sont ignorés avec un avertissement.

Chaque rôle reste réglable finement :

- **Dossier racine** avec variables : `{ABSPATH}`, `{WP_CONTENT}`, `{UPLOADS}`, `{VAULT}`, `{user_login}`, `{user_id}`, `{role}`. Exemple : `{VAULT}/clients/{user_login}` donne à chaque utilisateur un dossier **privé** créé automatiquement, inaccessible par URL directe.
- **Actions autorisées** (télécharger, téléverser, remplacer, créer, renommer, déplacer, supprimer, éditer, ZIP, permissions, images, partager).
- Éléments **cachés** et éléments en **lecture seule** (motifs : `*.log`, `private/`, `wp-content/plugins`…).
- **Types de fichiers** autorisés à l’upload, **taille maximale**, **quota** de stockage.

Les administrateurs ont un accès complet, éventuellement limité à certains comptes (utile pour les agences : admins « agence » vs admins « client »).

Pour un **portail client**, activez le shortcode dans **Réglages → Général**, puis placez `[filora height="75vh"]` sur une page réservée aux membres.

## Réglages conseillés en production

- Activer la **confirmation du mot de passe** (Réglages → Sécurité).
- Imposer un **mot de passe** ou une **durée de vie maximale** aux liens (Réglages → Partage).
- Ranger les fichiers confidentiels dans le **Coffre-fort**.
- Vérifier le score dans **Outils de sécurité** et suivre ses recommandations.
- Activer le **mode lecture seule** sur les sites où personne ne doit modifier de fichiers.

## WP-CLI

```bash
wp filora share /wp-content/uploads/filora-vault/contrat.pdf --expires=7d --password=secret
wp filora share /wp-content/uploads/filora-vault/depot --mode=dropbox --emails="*@client.com"
wp filora shares --format=table
wp filora revoke 12
wp filora empty-trash --yes
wp filora scan            # intégrité du cœur + PHP dans uploads
```

## Hooks pour développeurs

| Hook | Rôle |
|---|---|
| `filora_profile` (filtre) | Modifier le profil effectif d’un utilisateur (racine, actions…) |
| `filora_is_full_admin` (filtre) | Décider qui est administrateur complet |
| `filora_system_paths` (filtre) | Ajouter des chemins protégés contre la suppression |
| `filora_server_executable_extensions` (filtre) | Liste des extensions jamais autorisées aux non-admins |
| `filora_share_forbidden_reason` (filtre) | Interdire le partage de certains chemins |
| `filora_vault_dir` (filtre) | Emplacement du Coffre-fort |
| `filora_chunk_size` (filtre) | Taille des morceaux d’upload |
| `filora_xsendfile_header` (filtre) | Déléguer l’envoi des fichiers au serveur (`X-Sendfile`, `X-Accel-Redirect`) |
| `filora_zip_max_bytes`, `filora_extract_max_bytes` (filtres) | Limites des archives |
| `filora_share_created`, `filora_share_download`, `filora_share_uploaded` (actions) | Intégrations (antivirus, CRM, Slack…) |
| `filora_logged` (action) | Chaque entrée du journal d’activité |

## Développement

```bash
npm install
npm run build     # compile assets/src → assets/dist + extrait les chaînes JS
npm run watch     # recompilation automatique
node bin/make-pot.mjs     # régénère languages/filora.pot
node bin/compile-mo.mjs   # compile les .po en .mo
npm run zip       # crée build/filora-x.y.z.zip prêt à installer
```

Tests (sur une installation WordPress jetable avec Filora activé) :

```bash
php tests/rest-smoke.php /chemin/vers/wordpress   # 66 tests API + sécurité
```

## Architecture

```
filora.php                 Amorçage, autoload
includes/
  Access.php, Profile.php  Qui a accès, avec quel profil (rôles, sudo)
  Policy.php, Paths.php    Règles de noms/extensions, normalisation & confinement des chemins
  Fs.php                   Opérations fichiers sandboxées (realpath, motifs, protections)
  Trash.php, Versions.php  Corbeille et historique dans le stockage privé
  Uploads.php, Zipper.php  Uploads par morceaux, ZIP sécurisés
  Stream.php, Images.php   Diffusion (Range, ETag, CSP) et miniatures
  Shares.php, Crypto.php   Modèle des liens, jetons, chiffrement, signatures
  ShareRouter.php          Pages publiques /share/{jeton}/ (gates, téléchargements, dépôts)
  Log.php, Integrity.php   Journal d’activité et outils de sécurité
  Rest/                    API REST filora/v1
templates/share.php        Page publique autonome (CSS/JS inline, CSP stricte)
assets/src/app             Application Preact (sources)
assets/src/share           Script et styles de la page publique
assets/dist                Fichiers compilés (versionnés)
languages/                 Traductions (fr_FR fournie)
```

Auteur : Charles A. — Licence : GPL-2.0-or-later.

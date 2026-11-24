# Tour de Guinée 224 – Reportages

Application de gestion des reportages payants (Facebook & TikTok) : suivi de production, paiements des clients, rémunération et versements des collaborateurs en Guinée.

## Démo

La version publiée sur GitHub Pages est une **démo** : le serveur est simulé dans le navigateur (`demo.js`) avec des données fictives, gardées uniquement dans votre navigateur.

- Administrateur : `admin` / `admin1234`
- Collaborateur : `alpha` / `alpha1234`

Le bandeau jaune permet de changer de rôle, de **simuler une coupure réseau** (pour tester la saisie hors connexion) et de remettre la démo à zéro.

## Installation réelle

GitHub Pages n'exécute pas PHP : la vraie application s'installe sur un hébergement PHP 8.1+ avec SQLite (par exemple alwaysdata, offre gratuite). Voir [LISEZMOI.txt](LISEZMOI.txt).

| Fichier | Rôle |
|---|---|
| `index.html` | l'application |
| `api.php` | le serveur (comptes, droits, données, photos, historique) |
| `config.php` | code d'installation à changer avant la mise en ligne |
| `sw.js`, `manifest.webmanifest`, `icon*` | installation sur téléphone et mode hors connexion |
| `demo.js` | serveur simulé, actif seulement sur `*.github.io` ou avec `?demo` dans l'adresse |

> Ne publiez jamais ici votre vrai `config.php` (avec votre code secret) ni le dossier `data/`.

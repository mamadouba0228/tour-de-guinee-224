<?php
// ==== Configuration Tour de Guinée 224 ====
// 1) Changez ce code AVANT de mettre le site en ligne.
//    Il est demandé une seule fois, pour créer le compte administrateur.
const SETUP_CODE = 'changez-moi-224';

// 2) Emplacement de la base de données (fichier SQLite).
//    Par défaut : dossier "data" à côté de ce fichier (protégé par .htaccess).
const DB_FILE = __DIR__ . '/data/tdg224.sqlite';

// 3) Photos et pièces jointes : rangées dans data/uploads (protégé lui aussi).
//    Espace maximum pour les photos, en octets (80 Mo par défaut, l'offre gratuite donne 100 Mo en tout).
// const FILES_QUOTA = 80 * 1024 * 1024;

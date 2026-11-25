<?php
// ==== Configuration Tour de Guinée 224 ====
// 1) Changez ce code AVANT de mettre le site en ligne.
//    Il est demandé une seule fois, pour créer le compte administrateur.
const SETUP_CODE = 'changez-moi-224';

// 2) Emplacement de la base de données (fichier SQLite).
//    Par défaut : dossier "data" à côté de ce fichier (protégé par .htaccess).
const DB_FILE = __DIR__ . '/data/tdg224.sqlite';

// 3) Photos et pièces jointes : rangées dans data/uploads (protégé lui aussi).
//    Espace maximum pour les photos (30 Mo par défaut). Les 100 Mo de l'offre gratuite sont partagés
//    avec les autres sites du compte : pour changer la limite, retirez les // et ajustez le nombre de Mo.
// const FILES_QUOTA = 30 * 1024 * 1024;

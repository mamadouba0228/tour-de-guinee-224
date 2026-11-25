<?php
// API Tour de Guinée 224 — PHP 8.1+ et SQLite — version 2
declare(strict_types=1);
require __DIR__ . '/config.php';
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', dirname(DB_FILE) . '/uploads');
if (!defined('FILES_QUOTA')) define('FILES_QUOTA', 30 * 1024 * 1024); // 30 Mo pour les photos : l'offre gratuite (100 Mo) est partagée avec les autres sites du compte

// Cookie de session propre à cette application et limité à son dossier (ex. /tdg224/),
// pour ne jamais se mélanger avec les autres applications PHP du même compte.
$base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
session_name('tdg224_session');
session_set_cookie_params(['lifetime' => 60*60*24*30, 'path' => $base, 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const COLLS = ['reportages', 'collaborateurs', 'versements', 'config', 'clients'];
// Ce qu'un collaborateur peut modifier sur SES reportages. Le reste (prix, encaissé, rémunération,
// frais validés, taux, collaborateur attribué) est réservé à l'administrateur.
const COLLAB_REP_FIELDS = ['titre', 'village', 'region', 'prefecture', 'sousPrefecture','clientId', 'client', 'contact', 'date', 'statut', 'plateformes', 'lien', 'notes', 'fraisDeclares', 'maj'];
// Ce qu'un collaborateur ne voit pas sur ses reportages.
const COLLAB_REP_HIDDEN = ['prix', 'encaisse', 'taux', 'modePaiement'];
const TRIES_PER_LOGIN = 5;   // essais ratés autorisés par identifiant et par adresse IP…
const TRIES_PER_IP = 20;     // …et par adresse IP, tous identifiants confondus
const LOCK_SECONDS = 900;    // pendant 15 minutes

function out($data, int $code = 200): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  if (!is_dir(dirname(DB_FILE))) mkdir(dirname(DB_FILE), 0700, true);
  $pdo = new PDO('sqlite:' . DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
  $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, nom TEXT NOT NULL, login TEXT UNIQUE NOT NULL, hash TEXT NOT NULL, role TEXT NOT NULL, collab TEXT DEFAULT "", created TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS docs (coll TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL, maj TEXT, par INTEGER, PRIMARY KEY (coll, id))');
  $pdo->exec('CREATE TABLE IF NOT EXISTS attempts (login TEXT, ip TEXT, t INTEGER)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS journal (id INTEGER PRIMARY KEY, coll TEXT, docid TEXT, quand TEXT, par INTEGER, action TEXT, champs TEXT)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS journal_doc ON journal (coll, docid)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS files (id TEXT PRIMARY KEY, coll TEXT, docid TEXT, nom TEXT, mime TEXT, taille INTEGER, quand TEXT, par INTEGER)');
  // Compte propriétaire : le seul qui émet les reçus (signature). Mise à jour d'une base existante :
  // le premier administrateur créé devient propriétaire.
  $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
  if (!in_array('owner', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN owner INTEGER NOT NULL DEFAULT 0');
  if (!(int) $pdo->query('SELECT COUNT(*) FROM users WHERE owner = 1')->fetchColumn())
    $pdo->exec("UPDATE users SET owner = 1 WHERE id = (SELECT MIN(id) FROM users WHERE role = 'admin')");
  return $pdo;
}
function me(): ?array {
  if (empty($_SESSION['uid'])) return null;
  $s = db()->prepare('SELECT id, nom, login, role, collab, owner FROM users WHERE id = ?');
  $s->execute([$_SESSION['uid']]);
  $u = $s->fetch();
  if (!$u) return null;
  $u['id'] = (int) $u['id']; $u['collab'] = (string) ($u['collab'] ?? ''); $u['owner'] = (bool) $u['owner'] && $u['role'] === 'admin';
  return $u;
}
function isOwner(array $u): bool { return !empty($u['owner']); }
// Les autres comptes ne peuvent ni rétrograder, ni supprimer, ni changer le mot de passe du propriétaire.
function protectOwner(array $u, int $targetId): void {
  $s = db()->prepare('SELECT owner FROM users WHERE id = ?'); $s->execute([$targetId]);
  if ((int) $s->fetchColumn() === 1 && $targetId !== $u['id']) fail('Ce compte est celui du propriétaire : lui seul peut le modifier.', 403);
}
function signatureFile(): ?string {
  foreach (['signature.svg' => 'image/svg+xml', 'signature.png' => 'image/png'] as $f => $m) {
    $p = dirname(DB_FILE) . '/' . $f;
    if (is_file($p)) return $p;
  }
  return null;
}
function need(?string $role = null): array {
  $u = me();
  if (!$u) fail('Non connecté', 401);
  if ($role === 'admin' && $u['role'] !== 'admin') fail('Réservé à l’administrateur', 403);
  return $u;
}
function isAdmin(array $u): bool { return $u['role'] === 'admin'; }
function validId(string $id): bool { return (bool) preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id); }

function getRow(string $coll, string $id): ?array {
  $s = db()->prepare('SELECT data, par FROM docs WHERE coll = ? AND id = ?');
  $s->execute([$coll, $id]);
  $r = $s->fetch();
  if (!$r) return null;
  return ['data' => json_decode($r['data'], true) ?: [], 'par' => (int) $r['par']];
}
// Ce reportage (ou ce versement) est-il celui du collaborateur connecté ?
function isMine(array $u, array $d): bool { return $u['collab'] !== '' && ($d['collab'] ?? '') === $u['collab']; }
// Le compte $u peut-il voir ce document ?
// Un collaborateur voit tous les reportages de l'équipe, mais seulement SES versements.
function canSee(array $u, string $coll, string $id, array $d): bool {
  if (isAdmin($u)) return true;
  return match ($coll) {
    'reportages', 'collaborateurs', 'clients', 'config' => true,
    'versements' => isMine($u, $d),
    default => false,
  };
}
// Ce que le compte $u voit du document (les montants confidentiels sont retirés pour un collaborateur).
function view(array $u, string $coll, string $id, array $d): array {
  if (isAdmin($u)) return $d;
  if ($coll === 'reportages') {
    foreach (COLLAB_REP_HIDDEN as $k) unset($d[$k]);
    // Reportage d'un autre collaborateur : aucun montant.
    if (!isMine($u, $d)) foreach (['partCollab', 'frais', 'fraisDeclares'] as $k) unset($d[$k]);
  }
  if ($coll === 'clients') $d = array_intersect_key($d, ['nom' => 1, 'tel' => 1]);
  if ($coll === 'config') $d = array_intersect_key($d, ['taux' => 1]);
  // Fiches des autres collaborateurs : seulement le nom et la zone.
  if ($coll === 'collaborateurs') $d = $id === $u['collab'] ? array_diff_key($d, ['notes' => 1]) : array_intersect_key($d, ['nom' => 1, 'region' => 1]);
  return $d;
}
// Pièces jointes : un collaborateur ne voit que celles de SES reportages et de SES versements.
function canSeeFile(array $u, array $f, ?array $row): bool {
  if (isAdmin($u)) return true;
  return $row !== null && in_array($f['coll'], ['reportages', 'versements'], true) && isMine($u, $row['data']);
}
function short($v): ?string {
  if ($v === null || $v === '') return null;
  if (is_array($v)) $v = implode(', ', array_map(fn($x) => is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE), $v));
  $s = is_bool($v) ? ($v ? 'oui' : 'non') : (string) $v;
  return function_exists('mb_substr') ? mb_substr($s, 0, 200) : substr($s, 0, 200);
}
function diff(array $a, array $b): array {
  $ch = [];
  foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
    if ($k === 'maj') continue;
    $x = $a[$k] ?? null; $y = $b[$k] ?? null;
    if (is_numeric($x) && is_numeric($y) && (float) $x == (float) $y) continue;
    if (short($x) !== short($y)) $ch[$k] = [short($x), short($y)];
  }
  return $ch;
}
function journal(array $u, string $coll, string $id, string $action, array $champs = []): void {
  db()->prepare('INSERT INTO journal (coll, docid, quand, par, action, champs) VALUES (?,?,?,?,?,?)')
      ->execute([$coll, $id, date('c'), $u['id'], $action, $champs ? json_encode($champs, JSON_UNESCAPED_UNICODE) : null]);
}
function putDoc(array $u, string $coll, string $id, array $data): void {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE);
  if ($json === false || strlen($json) > 100000) fail('Fiche trop volumineuse');
  db()->prepare('INSERT INTO docs (coll, id, data, maj, par) VALUES (?,?,?,?,?) ON CONFLICT(coll, id) DO UPDATE SET data = excluded.data, maj = excluded.maj, par = excluded.par')
      ->execute([$coll, $id, $json, date('c'), $u['id']]);
}
function filePath(string $id): string { return UPLOAD_DIR . '/' . $id; }
function deleteFile(array $f): void {
  if (preg_match('/^[a-f0-9]{24}$/', $f['id']) && is_file(filePath($f['id']))) unlink(filePath($f['id']));
  db()->prepare('DELETE FROM files WHERE id = ?')->execute([$f['id']]);
}
function filesUsed(): int { return (int) db()->query('SELECT COALESCE(SUM(taille), 0) FROM files')->fetchColumn(); }
function fileMeta(array $f): array {
  return ['id' => $f['id'], 'coll' => $f['coll'], 'docid' => $f['docid'], 'nom' => $f['nom'], 'mime' => $f['mime'], 'taille' => (int) $f['taille'], 'quand' => $f['quand'], 'par' => (int) $f['par']];
}
// Limite les essais : renvoie une erreur 429 si cet identifiant ou cette adresse a trop échoué.
function checkTries(string $login): void {
  $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
  $since = time() - LOCK_SECONDS;
  db()->prepare('DELETE FROM attempts WHERE t < ?')->execute([time() - 86400]);
  $s = db()->prepare('SELECT COUNT(*) FROM attempts WHERE t > ? AND login = ? AND ip = ?'); $s->execute([$since, $login, $ip]);
  $nLogin = (int) $s->fetchColumn();
  $s = db()->prepare('SELECT COUNT(*) FROM attempts WHERE t > ? AND ip = ?'); $s->execute([$since, $ip]);
  if ($nLogin >= TRIES_PER_LOGIN || (int) $s->fetchColumn() >= TRIES_PER_IP) fail('Trop d’essais ratés. Réessayez dans 15 minutes.', 429);
}
function failedTry(string $login): void {
  db()->prepare('INSERT INTO attempts (login, ip, t) VALUES (?,?,?)')->execute([$login, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), time()]);
}

$a = $_GET['a'] ?? '';
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Protection CSRF simple : l'en-tête personnalisé ne peut pas être envoyé par un autre site sans CORS.
  if (($_SERVER['HTTP_X_TDG'] ?? '') !== '1') fail('Requête refusée', 403);
  if (!str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/')) $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
}
$POST_ONLY = ['setup', 'login', 'logout', 'save', 'del', 'user_add', 'user_pw', 'user_del', 'user_link', 'user_role', 'recu', 'carte', 'upload', 'file_del', 'import'];
if (in_array($a, $POST_ONLY, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée', 405);

switch ($a) {
  case 'status':
    $n = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    out(['setup' => $n === 0, 'me' => me()]);

  case 'setup':
    if ((int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) fail('Déjà configuré', 403);
    checkTries('#setup');
    if (SETUP_CODE === 'changez-moi-224') fail('Modifiez d’abord SETUP_CODE dans config.php', 403);
    if (!hash_equals(SETUP_CODE, (string) ($in['code'] ?? ''))) { failedTry('#setup'); fail('Code d’installation incorrect', 403); }
    $nom = trim((string) ($in['nom'] ?? '')); $login = strtolower(trim((string) ($in['login'] ?? ''))); $pw = (string) ($in['password'] ?? '');
    if ($nom === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $login) || strlen($pw) < 8) fail('Nom, identifiant (lettres/chiffres, 3 caractères min.) et mot de passe (8 caractères min.) requis');
    db()->prepare('INSERT INTO users (nom, login, hash, role, created, owner) VALUES (?,?,?,?,?,1)')->execute([$nom, $login, password_hash($pw, PASSWORD_DEFAULT), 'admin', date('c')]);
    session_regenerate_id(true); $_SESSION['uid'] = (int) db()->lastInsertId();
    out(['me' => me()]);

  case 'login':
    usleep(300000);
    $login = strtolower(trim((string) ($in['login'] ?? '')));
    checkTries($login);
    $s = db()->prepare('SELECT * FROM users WHERE login = ?');
    $s->execute([$login]);
    $u = $s->fetch();
    if (!$u || !password_verify((string) ($in['password'] ?? ''), $u['hash'])) { failedTry($login); fail('Identifiant ou mot de passe incorrect', 401); }
    db()->prepare('DELETE FROM attempts WHERE login = ?')->execute([$login]);
    if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET hash = ? WHERE id = ?')->execute([password_hash((string) $in['password'], PASSWORD_DEFAULT), $u['id']]);
    session_regenerate_id(true); $_SESSION['uid'] = (int) $u['id'];
    out(['me' => me()]);

  case 'logout':
    $_SESSION = []; session_destroy(); out(['ok' => true]);

  case 'all':
    $u = need();
    $res = array_fill_keys(COLLS, []);
    $names = [];
    foreach (db()->query('SELECT id, nom FROM users') as $r) $names[(int) $r['id']] = $r['nom'];
    $mine = []; // reportages et versements du collaborateur connecté (pour les pièces jointes)
    foreach (db()->query('SELECT coll, id, data, maj, par FROM docs') as $r) {
      if (!isset($res[$r['coll']])) continue;
      $d = json_decode($r['data'], true) ?: [];
      if (!canSee($u, $r['coll'], $r['id'], $d)) continue;
      if (isMine($u, $d)) $mine[$r['coll'] . '/' . $r['id']] = true;
      $d = view($u, $r['coll'], $r['id'], $d);
      $d['id'] = $r['id']; $d['_maj'] = $r['maj']; $d['_par'] = $names[(int) $r['par']] ?? '';
      $res[$r['coll']][] = $d;
    }
    $files = [];
    foreach (db()->query('SELECT * FROM files ORDER BY quand') as $f) {
      // Un collaborateur ne voit que les pièces de ses reportages et de ses versements.
      if (isAdmin($u) || (in_array($f['coll'], ['reportages', 'versements'], true) && isset($mine[$f['coll'] . '/' . $f['docid']]))) $files[] = fileMeta($f);
    }
    out(['data' => $res, 'files' => $files, 'stockage' => isAdmin($u) ? ['utilise' => filesUsed(), 'max' => FILES_QUOTA] : null, 'me' => $u]);

  case 'save':
    $u = need();
    $coll = (string) ($in['coll'] ?? ''); $id = (string) ($in['id'] ?? ''); $data = $in['data'] ?? null;
    if (!in_array($coll, COLLS, true) || !is_array($data)) fail('Données invalides');
    if ($id === '') $id = bin2hex(random_bytes(8));
    if (!validId($id)) fail('Identifiant invalide');
    foreach (array_keys($data) as $k) if ($k === 'id' || str_starts_with((string) $k, '_')) unset($data[$k]);
    $row = getRow($coll, $id);
    $old = $row['data'] ?? null;
    if (!isAdmin($u)) {
      $c = $u['collab'];
      if ($coll === 'reportages') {
        if ($c === '') fail('Votre compte n’est pas encore relié à votre fiche collaborateur. Demandez à l’administrateur.', 403);
        if ($old !== null && ($old['collab'] ?? '') !== $c) fail('Ce reportage ne vous est pas attribué', 403);
        $data = array_intersect_key($data, array_flip(COLLAB_REP_FIELDS));
        $data['collab'] = $c;
      } elseif ($coll === 'clients') {
        // Un collaborateur peut créer un client (nom + téléphone) et corriger ceux qu'il a créés.
        if ($old !== null && $row['par'] !== $u['id']) fail('Seul l’administrateur peut modifier cette fiche client', 403);
        $data = array_intersect_key($data, ['nom' => 1, 'tel' => 1, 'region' => 1, 'prefecture' => 1, 'sousPrefecture' => 1, 'maj' => 1]);
      } else {
        fail('Vous ne pouvez pas modifier cette rubrique', 403);
      }
    }
    if ($coll === 'clients' && trim((string) ($data['nom'] ?? $old['nom'] ?? '')) === '') fail('Le nom du client est obligatoire');
    // Les informations imprimées sur les reçus (ent…) ne sont modifiables que par le propriétaire.
    if ($coll === 'config' && !isOwner($u)) foreach (array_keys($data) as $k) if (str_starts_with((string) $k, 'ent')) unset($data[$k]);
    $new = array_merge($old ?? [], $data);
    putDoc($u, $coll, $id, $new);
    $ch = diff($old ?? [], $new);
    if ($old === null || $ch) journal($u, $coll, $id, $old === null ? 'création' : 'modification', $ch);
    out(['id' => $id]);

  case 'del':
    $u = need('admin');
    $coll = (string) ($in['coll'] ?? ''); $id = (string) ($in['id'] ?? '');
    if (!in_array($coll, COLLS, true)) fail('Données invalides');
    $row = getRow($coll, $id);
    if (!$row) out(['ok' => true]);
    db()->prepare('DELETE FROM docs WHERE coll = ? AND id = ?')->execute([$coll, $id]);
    $s = db()->prepare('SELECT * FROM files WHERE coll = ? AND docid = ?'); $s->execute([$coll, $id]);
    foreach ($s->fetchAll() as $f) deleteFile($f);
    journal($u, $coll, $id, 'suppression', ['fiche' => [short($row['data']['titre'] ?? $row['data']['nom'] ?? $row['data']['montant'] ?? $id), null]]);
    out(['ok' => true]);

  case 'hist':
    need('admin');
    $coll = (string) ($_GET['coll'] ?? ''); $id = (string) ($_GET['id'] ?? '');
    $sql = 'SELECT j.coll, j.docid, j.quand, j.action, j.champs, u.nom FROM journal j LEFT JOIN users u ON u.id = j.par';
    if ($coll !== '') { $s = db()->prepare("$sql WHERE j.coll = ? AND j.docid = ? ORDER BY j.id DESC LIMIT 200"); $s->execute([$coll, $id]); }
    else $s = db()->query("$sql ORDER BY j.id DESC LIMIT 60");
    $h = [];
    foreach ($s->fetchAll() as $r) { $r['champs'] = $r['champs'] ? json_decode($r['champs'], true) : null; $h[] = $r; }
    out(['hist' => $h]);

  case 'upload':
    $u = need();
    $coll = (string) ($_POST['coll'] ?? ''); $docid = (string) ($_POST['docid'] ?? '');
    // collaborateurs : photo pour la carte de collaborateur (administrateurs seulement, voir plus bas)
    if (!in_array($coll, ['reportages', 'versements', 'clients', 'collaborateurs'], true) || !validId($docid)) fail('Données invalides');
    $row = getRow($coll, $docid);
    if (!$row) fail('Enregistrez d’abord la fiche, puis ajoutez les photos.');
    if (!isAdmin($u) && ($coll !== 'reportages' || $u['collab'] === '' || ($row['data']['collab'] ?? '') !== $u['collab'])) fail('Vous ne pouvez pas ajouter de pièce à cette fiche', 403);
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
      $e = is_array($f) ? ($f['error'] ?? 0) : 0;
      fail(in_array($e, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Fichier trop lourd pour le serveur' : 'Fichier non reçu, réessayez');
    }
    if ($f['size'] > 5 * 1024 * 1024) fail('Fichier trop lourd (5 Mo maximum)');
    $info = @getimagesize($f['tmp_name']);
    $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) $mime = $info['mime'];
    elseif ($head === '%PDF-') $mime = 'application/pdf';
    else fail('Formats acceptés : photo (JPEG, PNG, WebP) ou PDF');
    if (filesUsed() + $f['size'] > FILES_QUOTA) fail('Espace de stockage plein : supprimez d’anciennes photos.');
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0700, true);
    $id = bin2hex(random_bytes(12));
    if (!move_uploaded_file($f['tmp_name'], filePath($id))) fail('Impossible d’enregistrer le fichier', 500);
    $nom = preg_replace('/[\x00-\x1f"\\\\\/]+/', '_', (string) ($f['name'] ?? 'fichier'));
    $nom = function_exists('mb_substr') ? mb_substr($nom, 0, 120) : substr($nom, 0, 120);
    $meta = ['id' => $id, 'coll' => $coll, 'docid' => $docid, 'nom' => $nom, 'mime' => $mime, 'taille' => (int) $f['size'], 'quand' => date('c'), 'par' => $u['id']];
    db()->prepare('INSERT INTO files (id, coll, docid, nom, mime, taille, quand, par) VALUES (:id,:coll,:docid,:nom,:mime,:taille,:quand,:par)')->execute($meta);
    journal($u, $coll, $docid, 'pièce jointe ajoutée', ['fichier' => [null, $nom]]);
    out(['file' => $meta]);

  case 'file':
    $u = need();
    $s = db()->prepare('SELECT * FROM files WHERE id = ?'); $s->execute([(string) ($_GET['id'] ?? '')]);
    $f = $s->fetch();
    if (!$f) fail('Fichier introuvable', 404);
    if (!canSeeFile($u, $f, isAdmin($u) ? null : getRow($f['coll'], $f['docid']))) fail('Accès refusé', 403);
    $p = filePath($f['id']);
    if (!is_file($p)) fail('Fichier introuvable', 404);
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['nom']) ?: 'fichier';
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($p));
    header('Cache-Control: private, max-age=604800');
    header('Content-Disposition: inline; filename="' . $safe . '"');
    readfile($p);
    exit;

  case 'file_del':
    $u = need();
    $s = db()->prepare('SELECT * FROM files WHERE id = ?'); $s->execute([(string) ($in['id'] ?? '')]);
    $f = $s->fetch();
    if (!$f) out(['ok' => true]);
    if (!isAdmin($u) && (int) $f['par'] !== $u['id']) fail('Seul l’administrateur ou la personne qui l’a ajoutée peut supprimer cette pièce', 403);
    deleteFile($f);
    journal($u, $f['coll'], $f['docid'], 'pièce jointe supprimée', ['fichier' => [$f['nom'], null]]);
    out(['ok' => true]);

  case 'export':
    $u = need('admin');
    $docs = array_fill_keys(COLLS, []);
    foreach (db()->query('SELECT coll, id, data FROM docs') as $r) {
      if (!isset($docs[$r['coll']])) continue;
      $docs[$r['coll']][] = ['id' => $r['id']] + (json_decode($r['data'], true) ?: []);
    }
    header('Content-Disposition: attachment; filename="tdg224-sauvegarde-' . date('Y-m-d') . '.json"');
    out(['application' => 'Tour de Guinée 224', 'version' => 2, 'exporte' => date('c'), 'par' => $u['nom'], 'docs' => $docs,
         'comptes' => db()->query('SELECT nom, login, role, collab FROM users')->fetchAll(),
         'pieces' => array_map('fileMeta', db()->query('SELECT * FROM files')->fetchAll())]);

  case 'import':
    $u = need('admin');
    $docs = $in['docs'] ?? null;
    if (!is_array($docs)) fail('Fichier de sauvegarde invalide');
    $n = 0;
    db()->beginTransaction();
    try {
      foreach ($docs as $coll => $list) {
        if (!in_array($coll, COLLS, true) || !is_array($list)) continue;
        foreach ($list as $d) {
          if (!is_array($d) || !validId((string) ($d['id'] ?? ''))) continue;
          $id = (string) $d['id'];
          foreach (array_keys($d) as $k) if ($k === 'id' || str_starts_with((string) $k, '_')) unset($d[$k]);
          putDoc($u, $coll, $id, $d); $n++;
        }
      }
      journal($u, '', '', 'restauration d’une sauvegarde', ['fiches' => [null, (string) $n]]);
      db()->commit();
    } catch (Throwable $e) { db()->rollBack(); fail('Restauration impossible : ' . $e->getMessage(), 500); }
    out(['n' => $n]);

  case 'users':
    need('admin');
    out(['users' => db()->query('SELECT id, nom, login, role, collab, owner FROM users ORDER BY owner DESC, role, nom')->fetchAll()]);

  case 'carte':
    // Carte de collaborateur : tous les administrateurs ; notée dans l'historique de la fiche.
    $u = need('admin');
    $id = (string) ($in['id'] ?? '');
    if (!getRow('collaborateurs', $id)) fail('Collaborateur introuvable', 404);
    journal($u, 'collaborateurs', $id, 'carte générée', ['numéro' => [null, short($in['numero'] ?? '')], 'signée' => [null, isOwner($u) ? 'oui' : 'non']]);
    out(['ok' => true]);

  case 'recu':
    // Émission d'un reçu / d'une facture : réservé au propriétaire, et noté dans l'historique du reportage.
    $u = need('admin');
    if (!isOwner($u)) fail('Seul le propriétaire peut émettre les reçus.', 403);
    $id = (string) ($in['id'] ?? '');
    if (!getRow('reportages', $id)) fail('Reportage introuvable', 404);
    $moyen = in_array($in['moyen'] ?? '', ['WhatsApp', 'impression'], true) ? $in['moyen'] : 'impression';
    journal($u, 'reportages', $id, 'reçu émis', ['envoi' => [null, $moyen], 'numéro' => [null, short($in['numero'] ?? '')]]);
    out(['ok' => true]);

  case 'signature':
    // La signature est dans data/ (protégé) : seul le propriétaire peut la récupérer.
    $u = need('admin');
    if (!isOwner($u)) fail('Réservé au propriétaire', 403);
    $p = signatureFile();
    if (!$p) fail('Pas de signature : placez signature.svg dans le dossier data', 404);
    header('Content-Type: ' . (str_ends_with($p, '.png') ? 'image/png' : 'image/svg+xml'));
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    readfile($p);
    exit;

  case 'user_add':
    $u = need('admin');
    $nom = trim((string) ($in['nom'] ?? '')); $login = strtolower(trim((string) ($in['login'] ?? ''))); $pw = (string) ($in['password'] ?? '');
    $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'collab';
    $collab = (string) ($in['collab'] ?? '');
    if ($nom === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $login) || strlen($pw) < 8) fail('Nom, identifiant (lettres/chiffres) et mot de passe de 8 caractères minimum requis');
    if ($collab !== '' && !getRow('collaborateurs', $collab)) fail('Fiche collaborateur introuvable');
    try {
      db()->prepare('INSERT INTO users (nom, login, hash, role, collab, created) VALUES (?,?,?,?,?,?)')->execute([$nom, $login, password_hash($pw, PASSWORD_DEFAULT), $role, $role === 'admin' ? '' : $collab, date('c')]);
    } catch (PDOException $e) { fail('Cet identifiant existe déjà'); }
    journal($u, 'comptes', $login, 'compte créé', ['rôle' => [null, $role]]);
    out(['ok' => true]);

  case 'user_link':
    $u = need('admin');
    $id = (int) ($in['id'] ?? 0); $collab = (string) ($in['collab'] ?? '');
    if ($collab !== '' && !getRow('collaborateurs', $collab)) fail('Fiche collaborateur introuvable');
    protectOwner($u, $id);
    db()->prepare('UPDATE users SET collab = ? WHERE id = ? AND role = \'collab\'')->execute([$collab, $id]);
    out(['ok' => true]);

  case 'user_role':
    $u = need('admin');
    $id = (int) ($in['id'] ?? 0); $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'collab';
    if ($id === $u['id']) fail('Vous ne pouvez pas changer votre propre rôle');
    protectOwner($u, $id);
    $s = db()->prepare('SELECT login, role FROM users WHERE id = ?'); $s->execute([$id]);
    $t = $s->fetch();
    if (!$t) fail('Compte introuvable', 404);
    if ($t['role'] === 'admin' && $role !== 'admin' && (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) fail('Il doit rester au moins un administrateur');
    // Un administrateur voit tout : il n'a pas besoin d'être relié à une fiche collaborateur.
    db()->prepare('UPDATE users SET role = ?, collab = CASE WHEN ? = \'admin\' THEN \'\' ELSE collab END WHERE id = ?')->execute([$role, $role, $id]);
    if ($t['role'] !== $role) journal($u, 'comptes', $t['login'], 'rôle modifié', ['rôle' => [$t['role'], $role]]);
    out(['ok' => true]);

  case 'user_pw':
    $u = need();
    $id = (int) ($in['id'] ?? $u['id']); $pw = (string) ($in['password'] ?? '');
    if ($id !== $u['id'] && !isAdmin($u)) fail('Interdit', 403);
    protectOwner($u, $id);
    if ($id === $u['id']) {
      // Pour son propre compte, il faut redonner l'ancien mot de passe.
      $s = db()->prepare('SELECT hash FROM users WHERE id = ?'); $s->execute([$id]);
      $hash = (string) $s->fetchColumn();
      checkTries('#pw' . $id);
      if (!password_verify((string) ($in['ancien'] ?? ''), $hash)) { failedTry('#pw' . $id); fail('Mot de passe actuel incorrect', 403); }
    }
    if (strlen($pw) < 8) fail('8 caractères minimum');
    db()->prepare('UPDATE users SET hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    out(['ok' => true]);

  case 'user_del':
    $u = need('admin');
    $id = (int) ($in['id'] ?? 0);
    if ($id === $u['id']) fail('Vous ne pouvez pas supprimer votre propre compte');
    protectOwner($u, $id);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    out(['ok' => true]);

  default:
    fail('Action inconnue', 404);
}

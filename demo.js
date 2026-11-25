// Tour de Guinée 224 — MODE DÉMO
// Simule le serveur (api.php) dans le navigateur, avec des données fictives gardées dans ce navigateur.
// S'active uniquement sur GitHub Pages (*.github.io) ou avec ?demo dans l'adresse. Sur le vrai serveur, ce fichier ne fait rien.
(() => {
  const ON = location.hostname.endsWith("github.io") || new URLSearchParams(location.search).has("demo");
  if (!ON) return;
  const K = "tdg_demo_db", KS = "tdg_demo_session", KO = "tdg_demo_off";
  const now = () => new Date().toISOString();
  const rid = () => [...crypto.getRandomValues(new Uint8Array(8))].map(b => b.toString(16).padStart(2, "0")).join("");
  const COLLS = ["reportages", "collaborateurs", "versements", "config", "clients"];
  const COLLAB_FIELDS = ["titre", "village", "region", "clientId", "client", "contact", "date", "statut", "plateformes", "lien", "notes", "fraisDeclares", "maj"];
  const HIDDEN = ["prix", "encaisse", "taux"];

  function seed() {
    const y = new Date().getFullYear(), d = (m, j) => `${y}-${String(m).padStart(2, "0")}-${String(j).padStart(2, "0")}`;
    const t = now(), doc = data => ({ data, maj: t, par: 1 });
    const reps = [
      ["Fête des récoltes", "Timbi-Madina", "Labé", "k1", "c1", "Publié", d(1, 12), 2500000, 2500000, 400000, 150000, 150000, ["Facebook", "TikTok"]],
      ["Inauguration du marché", "Madina", "Conakry", "k2", "c2", "Publié", d(2, 3), 1800000, 1000000, 300000, 50000, 50000, ["Facebook"]],
      ["Tournoi de football des jeunes", "Kankan centre", "Kankan", "k3", "c3", "Publié", d(3, 21), 1200000, 0, 250000, 120000, 120000, ["TikTok"]],
      ["Mariage traditionnel", "Dalaba", "Mamou", "k2", "c1", "Montage", d(4, 8), 3000000, 1500000, 500000, 200000, 260000, []],
      ["Portrait d'une coopérative de femmes", "Kindia ville", "Kindia", "k1", "c2", "Tournage", d(5, 15), 2000000, 500000, 350000, 0, 90000, []],
      ["Festival des masques", "N'Zérékoré", "N'Zérékoré", "k3", "c3", "Planifié", d(6, 2), 2800000, 0, 450000, 0, 0, []],
      ["Visite du port de pêche", "Kamsar", "Boké", "k2", "c1", "Demande", d(7, 19), 1500000, 0, 250000, 0, 0, []]
    ];
    const docs = { reportages: {}, collaborateurs: {}, versements: {}, clients: {}, config: {} };
    reps.forEach(([titre, village, region, clientId, collab, statut, date, prix, encaisse, partCollab, frais, fraisDeclares, plateformes], i) => {
      docs.reportages["r" + (i + 1)] = doc({ titre, village, region, clientId, collab, statut, date, prix, encaisse, partCollab, frais, fraisDeclares, plateformes, taux: 10000, lien: "", notes: "" });
    });
    docs.reportages.r8 = doc({ titre: "Concert de quartier (ancien reportage)", village: "Ratoma", region: "Conakry", client: "Studio Kalum", contact: "622 00 00 00", collab: "c2", statut: "Publié", date: d(1, 28), prix: 900000, encaisse: 900000, partCollab: 150000, frais: 30000, plateformes: ["TikTok"] });
    docs.collaborateurs = {
      c1: doc({ nom: "Alpha (démo)", tel: "620 11 11 11", region: "Labé", moyen: "Orange Money" }),
      c2: doc({ nom: "Binta (démo)", tel: "621 22 22 22", region: "Conakry", moyen: "MTN MoMo" }),
      c3: doc({ nom: "Sékou (démo)", tel: "622 33 33 33", region: "Kankan", moyen: "Orange Money" })
    };
    docs.clients = {
      k1: doc({ nom: "Association des jeunes (démo)", tel: "623 44 44 44", region: "Mamou", prefecture: "Pita", sousPrefecture: "Timbi-Madina" }),
      k2: doc({ nom: "Boutique Kadiatou (démo)", tel: "624 55 55 55", region: "Conakry", prefecture: "Ratoma", adresse: "Kipé" }),
      k3: doc({ nom: "Comité du festival (démo)", tel: "625 66 66 66", region: "Kankan", prefecture: "Kankan" })
    };
    docs.versements = {
      v1: doc({ collab: "c1", date: d(1, 20), montant: 550000, moyen: "Orange Money", ref: "OM-DEMO-001", rep: "r1" }),
      v2: doc({ collab: "c2", date: d(2, 10), montant: 200000, moyen: "MTN MoMo", ref: "MOMO-DEMO-002", rep: "r2" }),
      v3: doc({ collab: "c3", date: d(3, 30), montant: 370000, moyen: "Espèces", ref: "", rep: "r3" })
    };
    docs.config.main = doc({ taux: 10000, entreprise: "Tour de Guinée 224", entTel: "+224 600 00 00 00", entAdresse: "Conakry, Guinée", entPied: "Paiement par Orange Money ou MTN MoMo.\nMerci de votre confiance." });
    return {
      users: [
        { id: 1, nom: "Administrateur démo", login: "admin", pw: "admin1234", role: "admin", collab: "", owner: true },
        { id: 2, nom: "Alpha (démo)", login: "alpha", pw: "alpha1234", role: "collab", collab: "c1" },
        { id: 3, nom: "Binta (démo)", login: "binta", pw: "binta1234", role: "collab", collab: "c2" }
      ],
      docs, journal: [], files: [], attempts: {}, nextUser: 4
    };
  }
  let db;
  try { db = JSON.parse(localStorage.getItem(K) || "null"); } catch (_) { db = null; }
  if (!db) db = seed();
  // Démo créée avant l'arrivée du compte propriétaire : le premier administrateur le devient.
  if (!db.users.some(z => z.owner)) { const a = db.users.find(z => z.role === "admin"); if (a) a.owner = true; }
  const save = () => { try { localStorage.setItem(K, JSON.stringify(db)); return true; } catch (_) { return false; } };
  save();

  const R = (json, status = 200) => ({ status, json });
  const E = (error, status = 400) => R({ error }, status);
  const me = () => { const u = db.users.find(x => x.id === +localStorage.getItem(KS)); return u ? { id: u.id, nom: u.nom, login: u.login, role: u.role, collab: u.collab || "", owner: !!u.owner && u.role === "admin" } : null; };
  const ownerGuard = (u, id) => { const t = db.users.find(z => z.id === +id); return t && t.owner && t.id !== u.id ? E("Ce compte est celui du propriétaire : lui seul peut le modifier.", 403) : null; };
  const isAdmin = u => u.role === "admin";
  const isMine = (u, d) => !!u.collab && d.collab === u.collab;
  // Comme api.php : un collaborateur voit tous les reportages, mais seulement ses versements.
  const canSee = (u, c, id, d) => isAdmin(u) || (c === "versements" ? isMine(u, d) : ["reportages", "collaborateurs", "clients", "config"].includes(c));
  const pick = (o, keys) => Object.fromEntries(Object.entries(o).filter(([k]) => keys.includes(k)));
  function view(u, c, id, d) {
    if (isAdmin(u)) return { ...d };
    d = { ...d };
    if (c === "reportages") { HIDDEN.forEach(k => delete d[k]); if (!isMine(u, d)) ["partCollab", "frais", "fraisDeclares"].forEach(k => delete d[k]); }
    if (c === "clients") d = pick(d, ["nom", "tel"]);
    if (c === "config") d = pick(d, ["taux"]);
    if (c === "collaborateurs") { if (id === u.collab) delete d.notes; else d = pick(d, ["nom", "region"]); }
    return d;
  }
  const short = v => v === null || v === undefined || v === "" ? null : (Array.isArray(v) ? v.join(", ") : typeof v === "boolean" ? (v ? "oui" : "non") : String(v)).slice(0, 200);
  function diff(a, b) {
    const ch = {};
    for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
      if (k === "maj") continue;
      const x = a[k], y = b[k];
      if (x !== "" && y !== "" && x != null && y != null && !isNaN(x) && !isNaN(y) && Number(x) === Number(y)) continue;
      if (short(x) !== short(y)) ch[k] = [short(x), short(y)];
    }
    return ch;
  }
  const journal = (u, coll, docid, action, champs) => db.journal.push({ coll, docid, quand: now(), par: u.id, action, champs: champs && Object.keys(champs).length ? champs : null });
  const urls = {};
  const fileUrl = f => urls[f.id] ||= URL.createObjectURL(new Blob([Uint8Array.from(atob(f._data.split(",")[1]), c => c.charCodeAt(0))], { type: f.mime }));
  const meta = f => ({ id: f.id, coll: f.coll, docid: f.docid, nom: f.nom, mime: f.mime, taille: f.taille, quand: f.quand, par: f.par, url: fileUrl(f) });
  const readAsDataURL = blob => new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(blob); });

  window.TDG_DEMO = async (a, { body = {}, q = {}, form } = {}) => {
    await new Promise(r => setTimeout(r, 120)); // délai réseau simulé
    if (localStorage.getItem(KO) === "1") return { offline: true };
    const u = me();
    const need = adm => !u ? E("Non connecté", 401) : adm && !isAdmin(u) ? E("Réservé à l’administrateur", 403) : null;
    let x;
    switch (a) {
      case "status": return R({ setup: false, me: u });
      case "login": {
        const l = String(body.login || "").trim().toLowerCase();
        const tries = (db.attempts[l] || []).filter(t => t > Date.now() - 900000);
        if (tries.length >= 5) return E("Trop d’essais ratés. Réessayez dans 15 minutes.", 429);
        const usr = db.users.find(z => z.login === l && z.pw === body.password);
        if (!usr) { tries.push(Date.now()); db.attempts[l] = tries; save(); return E("Identifiant ou mot de passe incorrect", 401); }
        delete db.attempts[l]; save(); localStorage.setItem(KS, usr.id); setTimeout(bar);
        return R({ me: me() });
      }
      case "logout": localStorage.removeItem(KS); setTimeout(bar); return R({ ok: true });
      case "all": {
        if ((x = need())) return x;
        const data = Object.fromEntries(COLLS.map(c => [c, []])), vis = new Set(), names = Object.fromEntries(db.users.map(z => [z.id, z.nom]));
        for (const c of COLLS) for (const [id, r] of Object.entries(db.docs[c] || {})) {
          if (!canSee(u, c, id, r.data)) continue;
          if (isMine(u, r.data)) vis.add(c + "/" + id);
          data[c].push({ ...view(u, c, id, r.data), id, _maj: r.maj, _par: names[r.par] || "" });
        }
        const files = db.files.filter(f => isAdmin(u) || (["reportages", "versements"].includes(f.coll) && vis.has(f.coll + "/" + f.docid))).map(meta);
        const used = db.files.reduce((s, f) => s + f.taille, 0);
        return R({ data, files, stockage: isAdmin(u) ? { utilise: used, max: 3 * 1048576 } : null, me: u });
      }
      case "save": {
        if ((x = need())) return x;
        const coll = String(body.coll || ""); let id = String(body.id || ""); let data = body.data;
        if (!COLLS.includes(coll) || !data || typeof data !== "object") return E("Données invalides");
        if (!id) id = rid();
        data = Object.fromEntries(Object.entries(data).filter(([k]) => k !== "id" && !k.startsWith("_")));
        db.docs[coll] ||= {};
        const row = db.docs[coll][id], old = row?.data ?? null;
        if (!isAdmin(u)) {
          if (coll === "reportages") {
            if (!u.collab) return E("Votre compte n’est pas encore relié à votre fiche collaborateur. Demandez à l’administrateur.", 403);
            if (old && old.collab !== u.collab) return E("Ce reportage ne vous est pas attribué", 403);
            data = pick(data, COLLAB_FIELDS); data.collab = u.collab;
          } else if (coll === "clients") {
            if (old && row.par !== u.id) return E("Seul l’administrateur peut modifier cette fiche client", 403);
            data = pick(data, ["nom", "tel", "region", "prefecture", "sousPrefecture", "maj"]);
          } else return E("Vous ne pouvez pas modifier cette rubrique", 403);
        }
        if (coll === "clients" && !String(data.nom ?? old?.nom ?? "").trim()) return E("Le nom du client est obligatoire");
        if (coll === "config" && !u.owner) data = Object.fromEntries(Object.entries(data).filter(([k]) => !k.startsWith("ent")));
        const merged = { ...(old || {}), ...data };
        db.docs[coll][id] = { data: merged, maj: now(), par: u.id };
        const ch = diff(old || {}, merged);
        if (!old || Object.keys(ch).length) journal(u, coll, id, old ? "modification" : "création", ch);
        save();
        return R({ id });
      }
      case "del": {
        if ((x = need(true))) return x;
        const row = db.docs[body.coll]?.[body.id];
        if (row) {
          delete db.docs[body.coll][body.id];
          db.files = db.files.filter(f => !(f.coll === body.coll && f.docid === body.id));
          journal(u, body.coll, body.id, "suppression", { fiche: [short(row.data.titre || row.data.nom || row.data.montant || body.id), null] });
          save();
        }
        return R({ ok: true });
      }
      case "hist": {
        if ((x = need(true))) return x;
        const names = Object.fromEntries(db.users.map(z => [z.id, z.nom]));
        let L = db.journal.slice().reverse();
        L = q.coll ? L.filter(h => h.coll === q.coll && h.docid === q.id).slice(0, 200) : L.slice(0, 60);
        return R({ hist: L.map(h => ({ ...h, nom: names[h.par] || null })) });
      }
      case "upload": {
        if ((x = need())) return x;
        const coll = form.get("coll"), docid = form.get("docid"), file = form.get("file");
        const row = db.docs[coll]?.[docid];
        if (!row) return E("Enregistrez d’abord la fiche, puis ajoutez les photos.");
        if (!isAdmin(u) && (coll !== "reportages" || !u.collab || row.data.collab !== u.collab)) return E("Vous ne pouvez pas ajouter de pièce à cette fiche", 403);
        if (!file || !/^(image\/(jpeg|png|webp)|application\/pdf)$/.test(file.type)) return E("Formats acceptés : photo (JPEG, PNG, WebP) ou PDF");
        if (file.size > 900000) return E("Démo : fichier trop lourd (900 Ko maximum dans ce navigateur)");
        const f = { id: rid() + rid().slice(0, 8), coll, docid, nom: file.name || "fichier", mime: file.type, taille: file.size, quand: now(), par: u.id, _data: await readAsDataURL(file) };
        db.files.push(f);
        journal(u, coll, docid, "pièce jointe ajoutée", { fichier: [null, f.nom] });
        if (!save()) { db.files.pop(); db.journal.pop(); return E("Démo : la mémoire du navigateur est pleine. Supprimez des photos ou réinitialisez la démo."); }
        return R({ file: meta(f) });
      }
      case "file_del": {
        if ((x = need())) return x;
        const f = db.files.find(z => z.id === body.id);
        if (f) {
          if (!isAdmin(u) && f.par !== u.id) return E("Seul l’administrateur ou la personne qui l’a ajoutée peut supprimer cette pièce", 403);
          db.files = db.files.filter(z => z !== f);
          journal(u, f.coll, f.docid, "pièce jointe supprimée", { fichier: [f.nom, null] }); save();
        }
        return R({ ok: true });
      }
      case "export": {
        if ((x = need(true))) return x;
        const docs = Object.fromEntries(COLLS.map(c => [c, Object.entries(db.docs[c] || {}).map(([id, r]) => ({ id, ...r.data }))]));
        return R({ application: "Tour de Guinée 224", version: 2, exporte: now(), par: u.nom, docs, comptes: db.users.map(({ nom, login, role, collab }) => ({ nom, login, role, collab })), pieces: db.files.map(({ _data, ...m }) => m) });
      }
      case "import": {
        if ((x = need(true))) return x;
        let n = 0;
        for (const [coll, list] of Object.entries(body.docs || {})) {
          if (!COLLS.includes(coll) || !Array.isArray(list)) continue;
          for (const d of list) {
            if (!d || !/^[A-Za-z0-9_-]{1,40}$/.test(String(d.id || ""))) continue;
            const { id, ...rest } = d;
            db.docs[coll] ||= {};
            db.docs[coll][id] = { data: Object.fromEntries(Object.entries(rest).filter(([k]) => !k.startsWith("_"))), maj: now(), par: u.id }; n++;
          }
        }
        journal(u, "", "", "restauration d’une sauvegarde", { fiches: [null, String(n)] }); save();
        return R({ n });
      }
      case "users": if ((x = need(true))) return x; return R({ users: db.users.map(({ pw, ...z }) => ({ ...z, owner: z.owner ? 1 : 0 })).sort((a, b) => b.owner - a.owner) });
      case "recu": {
        if ((x = need(true))) return x;
        if (!u.owner) return E("Seul le propriétaire peut émettre les reçus.", 403);
        if (!db.docs.reportages[body.id]) return E("Reportage introuvable", 404);
        journal(u, "reportages", body.id, "reçu émis", { envoi: [null, body.moyen === "WhatsApp" ? "WhatsApp" : "impression"], "numéro": [null, short(body.numero)] }); save();
        return R({ ok: true });
      }
      // La vraie signature n'est jamais publiée dans la démo.
      case "signature": if ((x = need(true))) return x; return u.owner ? E("Pas de signature dans la démo", 404) : E("Réservé au propriétaire", 403);
      case "user_add": {
        if ((x = need(true))) return x;
        const login = String(body.login || "").trim().toLowerCase(), nom = String(body.nom || "").trim(), pw = String(body.password || "");
        if (!nom || !/^[a-z0-9._-]{3,40}$/.test(login) || pw.length < 8) return E("Nom, identifiant (lettres/chiffres) et mot de passe de 8 caractères minimum requis");
        if (db.users.some(z => z.login === login)) return E("Cet identifiant existe déjà");
        const role = body.role === "admin" ? "admin" : "collab";
        db.users.push({ id: db.nextUser++, nom, login, pw, role, collab: role === "admin" ? "" : String(body.collab || "") });
        journal(u, "comptes", login, "compte créé", { "rôle": [null, role] }); save();
        return R({ ok: true });
      }
      case "user_link": {
        if ((x = need(true))) return x;
        if ((x = ownerGuard(u, body.id))) return x;
        const z = db.users.find(v => v.id === +body.id);
        if (z && z.role === "collab") { z.collab = String(body.collab || ""); save(); }
        return R({ ok: true });
      }
      case "user_role": {
        if ((x = need(true))) return x;
        const z = db.users.find(v => v.id === +body.id), role = body.role === "admin" ? "admin" : "collab";
        if (+body.id === u.id) return E("Vous ne pouvez pas changer votre propre rôle");
        if (!z) return E("Compte introuvable", 404);
        if ((x = ownerGuard(u, body.id))) return x;
        if (z.role === "admin" && role !== "admin" && db.users.filter(v => v.role === "admin").length <= 1) return E("Il doit rester au moins un administrateur");
        if (z.role !== role) journal(u, "comptes", z.login, "rôle modifié", { "rôle": [z.role, role] });
        z.role = role; if (role === "admin") z.collab = ""; save();
        return R({ ok: true });
      }
      case "user_pw": {
        if ((x = need())) return x;
        const id = +(body.id || u.id), z = db.users.find(v => v.id === id);
        if (id !== u.id && !isAdmin(u)) return E("Interdit", 403);
        if ((x = ownerGuard(u, id))) return x;
        if (id === u.id && z.pw !== body.ancien) return E("Mot de passe actuel incorrect", 403);
        if (String(body.password || "").length < 8) return E("8 caractères minimum");
        z.pw = String(body.password); save();
        return R({ ok: true });
      }
      case "user_del": {
        if ((x = need(true))) return x;
        if (+body.id === u.id) return E("Vous ne pouvez pas supprimer votre propre compte");
        if ((x = ownerGuard(u, body.id))) return x;
        db.users = db.users.filter(v => v.id !== +body.id); save();
        return R({ ok: true });
      }
    }
    return E("Action inconnue", 404);
  };

  // Bandeau de démonstration
  function bar() {
    const off = localStorage.getItem(KO) === "1", u = me();
    const el = document.getElementById("demobar") || document.body.insertBefore(Object.assign(document.createElement("div"), { id: "demobar" }), document.body.firstChild);
    el.style.cssText = "background:#fcd116;color:#1b1513;padding:8px max(4vw,16px);font:14px/1.4 Inter,system-ui,sans-serif;display:flex;gap:8px;flex-wrap:wrap;align-items:center";
    const b = "font:600 13px Inter,sans-serif;min-height:34px;padding:0 10px;border-radius:8px;border:1px solid #1b151340;background:#fff;color:#1b1513;cursor:pointer";
    el.innerHTML = `<strong>MODE DÉMO</strong><span style="flex:1 1 260px">Données fictives, gardées dans ce navigateur. Comptes : <code>admin</code> / <code>admin1234</code> (propriétaire) · <code>alpha</code> / <code>alpha1234</code> (collaborateur). ${u ? "Connecté : " + u.nom.replace(/[<>&]/g, "") : ""}</span>
      <button style="${b}" data-r="1">Voir en administrateur</button><button style="${b}" data-r="2">Voir en collaborateur</button>
      <button style="${b}${off ? ";background:#ce1126;color:#fff" : ""}" id="dnet" aria-pressed="${off}">${off ? "Réseau coupé : rétablir" : "Simuler une coupure réseau"}</button>
      <button style="${b}" id="dreset">Réinitialiser la démo</button>`;
    el.querySelectorAll("[data-r]").forEach(x => x.onclick = () => { localStorage.setItem(KS, x.dataset.r); location.hash = "tableau"; location.reload(); });
    el.querySelector("#dnet").onclick = () => { const o = localStorage.getItem(KO) === "1"; localStorage.setItem(KO, o ? "0" : "1"); bar(); dispatchEvent(new Event(o ? "online" : "offline")); };
    el.querySelector("#dreset").onclick = e => {
      if (!e.target.dataset.c) { e.target.dataset.c = 1; e.target.textContent = "Confirmer : tout remettre à zéro"; return; }
      Object.keys(localStorage).filter(k => k.startsWith("tdg_")).forEach(k => localStorage.removeItem(k)); location.reload();
    };
  }
  window.TDG_DEMO_BAR = bar;
  document.addEventListener("DOMContentLoaded", bar);
})();

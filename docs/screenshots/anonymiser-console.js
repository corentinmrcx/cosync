// Anonymise les noms, emails et téléphones affichés à l'écran, AVANT une capture
// destinée au README public. À coller dans la console du navigateur (F12) sur la
// page à capturer, puis Entrée. Ne modifie rien en base : uniquement l'affichage.
// Recharger la page fait tout revenir.
(() => {
  const NOMS = ["DURAND", "MARTIN", "BERNARD", "PETIT", "ROBERT", "RICHARD", "MOREAU",
    "GARNIER", "LEROY", "ROUSSEL", "FONTAINE", "CHEVALIER", "GAUTHIER", "LAMBERT",
    "FAURE", "LEMOINE", "MERCIER", "BLANCHARD", "PERRIN", "CLEMENT", "BARBIER",
    "MASSON", "GIRAUD", "BRUNET", "PHILIPPE", "LEGRAND", "CARON", "VIDAL", "BOUCHER",
    "HUMBERT", "AUBERT", "GUERIN", "DELORME", "MARCHAND", "ROLLAND", "TESSIER"];
  const PRENOMS = ["Lucas", "Emma", "Hugo", "Léa", "Tom", "Jade", "Nathan", "Chloé",
    "Louis", "Manon", "Ethan", "Camille", "Théo", "Sarah", "Maël", "Inès", "Jules",
    "Zoé", "Gabriel", "Alice", "Raphaël", "Louise", "Arthur", "Rose", "Adam", "Anna",
    "Léo", "Eva", "Paul", "Nina", "Noah", "Lina", "Sacha", "Mila", "Evan", "Romy"];
  // Mots tout en majuscules qui ne sont PAS des noms de famille
  const EXCL = new Set(["RIB", "FFF", "PDF", "CSV", "XLSX", "CB", "CAF", "ANCV",
    "TOTAL", "URL", "API", "IBAN", "BIC", "SIRET", "TVA", "NOM", "PRÉNOM", "PRENOM",
    "STATUT", "U6", "U7", "U8", "U9", "U10", "U11", "U12", "U13", "U14", "U15", "U16",
    "U17", "U18", "U19", "SENIOR", "SENIORS", "LOISIRS", "COSYNC", "SOUDRON"]);
  const map = new Map();
  let idx = 0;
  const fakeFor = (key) => {
    if (!map.has(key)) {
      map.set(key, { nom: NOMS[idx % NOMS.length], prenom: PRENOMS[idx % PRENOMS.length] });
      idx++;
    }
    return map.get(key);
  };
  const reName = /\b([A-ZÀ-Ý]{2,}(?:[-' ][A-ZÀ-Ý]{2,})*)\s+([A-ZÀ-Ý][a-zà-ÿ]+(?:-[A-ZÀ-Ý][a-zà-ÿ]+)*)\b/g;
  const reNameRev = /\b([A-ZÀ-Ý][a-zà-ÿ]+)\s+([A-ZÀ-Ý]{3,}(?:[-' ][A-ZÀ-Ý]{2,})*)\b/g;
  const reMail = /\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,}\b/g;
  const rePhone = /(\+33\s?|0)[1-9]([ .]?\d{2}){4}/g;
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n;
  while ((n = walker.nextNode())) {
    let t = n.nodeValue;
    if (!t || !t.trim()) continue;
    t = t.replace(reName, (m, nom, prenom) => {
      if (EXCL.has(nom.split(/[-' ]/)[0])) return m;
      const f = fakeFor(nom + "|" + prenom);
      return f.nom + " " + f.prenom;
    });
    t = t.replace(reNameRev, (m, prenom, nom) => {
      if (EXCL.has(nom.split(/[-' ]/)[0])) return m;
      const f = fakeFor(nom + "|" + prenom);
      return f.prenom + " " + f.nom;
    });
    t = t.replace(reMail, (m) => {
      const f = fakeFor("mail|" + m.toLowerCase());
      return (f.prenom + "." + f.nom).toLowerCase()
        .normalize("NFD").replace(/[̀-ͯ]/g, "") + "@exemple.fr";
    });
    t = t.replace(rePhone, "06 12 34 56 78");
    if (t !== n.nodeValue) n.nodeValue = t;
  }
  console.log("✅ Anonymisé : " + map.size + " identités remplacées. Vérifie l'écran avant de capturer !");
})();

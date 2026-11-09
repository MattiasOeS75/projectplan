// Tidsplaner i Google Sheets → milstolpar, leveransdag och leveranslista per projekt.
// Arket läses som CSV via gviz-exporten; kräver att arket är delat "alla med länken".
//   Flik "Tidsplan":  Vecka | DAG | DATUM | <ett spår per kolumn, t.ex. "KICKS · Tag-on - Bild">
//                     Celler som börjar med VERSALER är milstolpar (FIRST VIEW, DELIVERY, UPPSTART …).
//   Flik "Format":    tabell med filnamn/land/format → leveranslistan (grupperad på Land).

const MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, maj: 5, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, okt: 10, oct: 10, nov: 11, dec: 12 };

export const sheetId = (url) => (String(url || "").match(/\/spreadsheets\/d\/([A-Za-z0-9_-]+)/) || [])[1] || "";

export async function fetchTab(id, tab) {
  const u = `https://docs.google.com/spreadsheets/d/${id}/gviz/tq?tqx=out:csv&sheet=${encodeURIComponent(tab)}`;
  const r = await fetch(u, { redirect: "follow" });
  if (!r.ok) throw new Error(`Sheets ${tab}: HTTP ${r.status} (är arket delat "alla med länken"?)`);
  const text = await r.text();
  if (/<html/i.test(text.slice(0, 200))) throw new Error(`Sheets ${tab}: fick inloggningssida – dela arket "alla med länken"`);
  return parseCsv(text);
}

/** Enkel CSV-parser (citattecken, radbrytningar i celler). */
export function parseCsv(text) {
  const rows = []; let row = [], cell = "", q = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (q) { if (ch === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += ch; }
    else if (ch === '"') q = true;
    else if (ch === ",") { row.push(cell); cell = ""; }
    else if (ch === "\n" || ch === "\r") { if (ch === "\r" && text[i + 1] === "\n") i++; row.push(cell); rows.push(row); row = []; cell = ""; }
    else cell += ch;
  }
  if (cell !== "" || row.length) { row.push(cell); rows.push(row); }
  return rows.filter((r) => r.some((c) => c.trim() !== ""));
}

/** "12-okt." / "1 nov" / "2026-10-12" → YYYY-MM-DD. År härleds: löpande ökande datum, nytt år när månaden backar. */
export function makeDateParser(startYear) {
  let year = startYear, lastMonth = 0;
  return (s) => {
    s = String(s || "").trim().toLowerCase();
    let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return m[0];
    m = s.match(/^(\d{1,2})[-\s./]*([a-zåäö]{3})/);
    if (!m) return "";
    const mon = MONTHS[m[2]]; if (!mon) return "";
    if (lastMonth && mon < lastMonth) year++;
    lastMonth = mon;
    return `${year}-${String(mon).padStart(2, "0")}-${String(+m[1]).padStart(2, "0")}`;
  };
}

const KEYWORDS = /\b(leverans|delivery|deliver|möte|avstämning|godkän\w*|approval|approved|first|second|2nd|1st|3rd|final|online|offline|first ?look|first ?view|uppstart|kickoff|kick-off|produktionsstart|inspelning|shoot|presentation|deadline|preview|review|feedback|first cut|rough cut|fine cut|screening|visning)\b/i;
const isMilestone = (t) => {
  const s = t.trim(), w = s.split(/\s+/);
  if (/^[A-ZÅÄÖ0-9][A-ZÅÄÖ0-9/\-]{2,}$/.test(w[0] || "")) return true;          // VERSALER
  if (/^(2nd|1st|3rd)$/i.test(w[0] || "") && /^[A-ZÅÄÖ]/.test(w[1] || "")) return true;
  return KEYWORDS.test(s);                                                        // nyckelord ("Möte Avstämning", "Leverans")
};
const isDelivery = (t) => /^(leverans|delivery|final delivery|slutleverans)/i.test(t.trim());
const trackName = (h) => { const s = String(h || "").trim(); const m = s.match(/(?:[-–·]\s*)([^-–·]+)$/); return (m ? m[1] : s).trim(); };

/** Tidsplan-flik → { milestones:[{d,t,s:1}], delivery } */
export function parseTimeline(rows, { year = new Date().getFullYear() } = {}) {
  // Rubrikraden (den med DATUM) kan ligga under en titel
  const hi = rows.findIndex((r) => r.some((c) => /^datum$/i.test(c.trim())));
  if (hi < 0) return { milestones: [], delivery: "" };
  const head = rows[hi].map((h) => h.trim());
  const di = head.findIndex((h) => /^datum$/i.test(h));
  const width = Math.max(head.length, ...rows.slice(hi + 1).map((r) => r.length));
  // Spår = kolumner efter DATUM, även utan rubrik
  const tracks = []; for (let i = di + 1; i < width; i++) if (rows.slice(hi + 1).some((r) => (r[i] || "").trim())) tracks.push({ i, name: trackName(head[i] || "") });
  const multi = tracks.filter((t) => t.name).length > 1;
  const toDate = makeDateParser(year);
  const milestones = []; let delivery = "";
  for (const r of rows.slice(hi + 1)) {
    const d = toDate(r[di]); if (!d) continue;
    for (const t of tracks) {
      const raw = (r[t.i] || "").replace(/\s+/g, " ").trim(); if (!raw) continue;
      for (const part of raw.split(/\s*·\s*/)) {
        if (!isMilestone(part)) continue;
        const text = part.length > 60 ? part.slice(0, 57) + "…" : part;
        milestones.push({ d, t: multi && t.name ? `${t.name}: ${text}` : text, s: 1 });
        if (isDelivery(part) && d > delivery) delivery = d;
      }
    }
  }
  return { milestones, delivery };
}

/** Format-flik → { cols, groups:[{t, rows:[{c}]}] } grupperat på kolumnen Land (om den finns) */
export function parseFormats(rows) {
  if (rows.length < 2) return null;
  let head = rows[0].map((h) => h.trim());
  let drop = head[0] === "" ? 1 : 0; // första kolumnen är radnummer
  while (head.length && head[head.length - 1] === "") head.pop(); // tomma kolumner i slutet
  const cols = head.slice(drop);
  const li = cols.findIndex((c) => /^(land|country|marknad)$/i.test(c));
  const groups = new Map();
  for (const r of rows.slice(1)) {
    const c = r.slice(drop, drop + cols.length).map((x) => (x || "").trim());
    if (!c[0] && !c[1]) continue;               // tomma/fotnotsrader
    if (c.filter(Boolean).length < 2) continue;
    const key = li >= 0 ? c[li] || "" : "";
    if (!groups.has(key)) groups.set(key, { t: key, rows: [] });
    groups.get(key).rows.push({ c });
  }
  const g = [...groups.values()];
  return g.length ? { cols, groups: g } : null;
}

export const DELIVERY_TABS = ["Format", "Leverans", "Leveranser", "Leveranslista", "Deliveries", "Delivery", "Formats"];

/** Synka alla projekt som har en Sheets-länk. Manuella milstolpar (utan s:1) behålls. */
export async function syncSheets(store, { year } = {}) {
  const projects = await store.list("projects");
  const result = { synced: 0, skipped: 0, errors: [] };
  for (const [id, p] of Object.entries(projects)) {
    const sid = sheetId(p.sheet); if (!sid || p.archived) { result.skipped++; continue; }
    try {
      const tl = parseTimeline(await fetchTab(sid, "Tidsplan"), { year: year || yearHint(p) });
      let deliveries = p.deliveries;
      for (const tab of DELIVERY_TABS) { try { const d = parseFormats(await fetchTab(sid, tab)); if (d) { deliveries = d; break; } } catch { /* fliken finns inte */ } }
      const manual = (p.milestones || []).filter((m) => !m.s);
      const next = { ...p, milestones: [...manual, ...tl.milestones], deliveries };
      if (tl.delivery && (!p.delivery || p.deliveryFromSheet)) { next.delivery = tl.delivery; next.deliveryFromSheet = true; }
      next.sheetSynced = new Date().toISOString().slice(0, 16);
      await store.set("projects", id, next);
      result.synced++;
    } catch (e) { result.errors.push({ id, name: p.name, error: e.message }); }
  }
  return result;
}
const yearHint = (p) => { const m = String(p.source?.monday?.created || p.delivery || "").match(/^(\d{4})/); return m ? +m[1] : new Date().getFullYear(); };

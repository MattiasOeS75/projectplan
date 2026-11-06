// Monday.com-synk: hämtar items från valda boards via GraphQL och lagrar dem råa.
// Mappningen till projects/people/absence sker i mapMonday() – anpassas när boardens kolumner är kända.
const API = "https://api.monday.com/v2";

export async function mondayQuery(query, variables = {}) {
  const token = process.env.MONDAY_TOKEN;
  if (!token) throw new Error("MONDAY_TOKEN saknas i .env");
  const r = await fetch(API, {
    method: "POST",
    headers: { "content-type": "application/json", authorization: token, "API-Version": "2024-10" },
    body: JSON.stringify({ query, variables }),
  });
  const j = await r.json();
  if (j.errors) throw new Error("Monday: " + JSON.stringify(j.errors));
  return j.data;
}

/** Lista alla boards kontot ser – används för att hitta rätt board-id:n. */
export async function listBoards() {
  const d = await mondayQuery(`{ boards(limit: 200, order_by: used_at) { id name state board_kind workspace { name } } }`);
  return d.boards.filter((b) => b.state === "active");
}

/** Hämta alla items (med kolumnvärden) från ett board, sida för sida. */
export async function fetchBoardItems(boardId) {
  const cols = await mondayQuery(
    `query($id:[ID!]){ boards(ids:$id){ name columns { id title type settings_str } groups { id title } } }`,
    { id: [String(boardId)] }
  );
  const board = cols.boards[0];
  const items = [];
  let cursor = null;
  do {
    const d = await mondayQuery(
      `query($id:[ID!],$cursor:String){
        boards(ids:$id){ items_page(limit:200, cursor:$cursor){ cursor
          items { id name group { id title } column_values { id text value type } } } } }`,
      { id: [String(boardId)], cursor }
    );
    const page = d.boards[0].items_page;
    for (const it of page.items) {
      const columns = {};
      for (const cv of it.column_values) columns[cv.id] = { text: cv.text, value: safeJson(cv.value), type: cv.type };
      items.push({ board_id: +boardId, item_id: +it.id, name: it.name, group_id: it.group?.id, group_title: it.group?.title, columns });
    }
    cursor = page.cursor;
  } while (cursor);
  return { board, items };
}

const safeJson = (s) => { try { return s ? JSON.parse(s) : null; } catch { return s; } };

/**
 * Mappning Monday → planeringens dokument. Returnerar { projects, people, absence } som { id: data }.
 * TODO: fyll i när vi vet vilka boards/kolumner som gäller. Sidans datamodell (från source/):
 *   projects: { name, code (max 8 tecken, visas i grid), client, color (0-8 eller "#hex"),
 *              phase (index 0-5: Uppstart, Pre-prod, Produktion, Post / VFX, Korr & leverans, Löpande),
 *              status (fritext), waiting (väntar på …), delivery (YYYY-MM-DD, leveransdag/flagga),
 *              budget (tal el. null), owner (people-id), archived (bool), deliveries ({cols,groups}) }
 *   people:   { name, role, order }
 *   blocks:   { personId, date (YYYY-MM-DD), part (full|am|pm), projectId, note, tentative? }
 *   absence:  { personId (eller utelämnad = alla), from (YYYY-MM-DD), to (YYYY-MM-DD), part (full|am|pm), label }
 */
/** Board "Projektöversikt" (1071363420): bara grupperna Pitch och Pågående blir projekt.
 *  Kolumner: Objekt, Status, Kundregister, Kontakt, Projekt Nr., Producent, Involverade, Skapad, Brief, Deadline, Projektperiod. */
const GROUPS = { pitch: 0, "pågående": 2 }; // grupp → standardfas (Uppstart / Produktion)

export function mapMonday(boards, existing = {}) {
  const out = { projects: {}, people: {}, absence: {} };
  const peopleByName = Object.fromEntries(Object.entries(existing.people || {}).map(([id, p]) => [norm(p.name), id]));
  for (const { board, items } of boards) {
    const byTitle = Object.fromEntries(board.columns.map((c) => [norm(c.title), c.id]));
    const col = (it, ...titles) => { for (const t of titles) { const id = byTitle[norm(t)]; if (id && it.columns[id]?.text) return it.columns[id].text.trim(); } return ""; };
    const raw = (it, ...titles) => { for (const t of titles) { const id = byTitle[norm(t)]; if (id && it.columns[id]?.value) return it.columns[id].value; } return null; };
    for (const it of items) {
      const g = norm(it.group_title);
      const id = `m${it.item_id}`;
      if (!(g in GROUPS)) {
        // Levererat/övrigt: arkivera om projektet tidigare synkats in
        if (existing.projects?.[id] && !existing.projects[id].archived) out.projects[id] = { ...existing.projects[id], archived: true };
        continue;
      }
      const status = col(it, "Status");
      const period = raw(it, "Projektperiod"); // {from,to}
      const deadline = toISO(raw(it, "Deadline")?.date) || toISO(col(it, "Deadline")) || toISO(period?.to);
      const producent = col(it, "Producent");
      const client = [col(it, "Kundregister"), col(it, "Kontakt")].filter(Boolean).join(" · ");
      const prev = existing.projects?.[id] || {};
      // Fas: Pitch → Uppstart. Pågående → behåll fas som satts manuellt i planen, annars Produktion.
      const phase = g === "pitch" ? 0 : (prev.phase != null && prev.source?.monday ? prev.phase : GROUPS[g]);
      const color = prev.color ?? nextColor(existing.projects, out.projects);
      out.projects[id] = {
        name: it.name,
        code: col(it, "Projekt Nr.") || shortCode(it.name),
        client,
        phase,
        color,
        status,
        delivery: deadline,
        owner: peopleByName[norm(producent)] || prev.owner || "",
        archived: false,
        source: { monday: { board: board.name, boardId: it.board_id, item: it.item_id, group: it.group_title, status, producent,
                            involverade: col(it, "Involverade"), period: period?.from ? `${period.from} – ${period.to}` : "", created: col(it, "Skapad") } },
      };
    }
  }
  return out;
}
function nextColor(...sets) {
  const used = {}; for (const set of sets) for (const p of Object.values(set || {})) if (typeof p.color === "number") used[p.color] = (used[p.color] || 0) + 1;
  let best = 0; for (let i = 0; i < 9; i++) if ((used[i] || 0) < (used[best] || 0)) best = i; return best;
}
const norm = (s) => String(s || "").trim().toLowerCase();
/** Kortnamn (≤8 tecken) ur projektnamnet, t.ex. "Absolut vodka - Murakami" → "ABSMURAK" */
function shortCode(name) {
  const words = String(name).replace(/[^\p{L}\p{N} ]+/gu, " ").split(/\s+/).filter((w) => w.length > 1 && !/^(och|the|and|av|x|ml)$/i.test(w));
  if (!words.length) return String(name).slice(0, 8).toUpperCase();
  if (words.length === 1) return words[0].slice(0, 8).toUpperCase();
  return (words[0].slice(0, 3) + words[1].slice(0, 5)).toUpperCase();
}

const PHASES = ["Uppstart", "Pre-prod", "Produktion", "Post / VFX", "Korr & leverans", "Löpande"];
const toISO = (s) => { const m = String(s || "").match(/\d{4}-\d{2}-\d{2}/); return m ? m[0] : ""; };
/** Fas som index 0-5 (så sidan förstår det). */
function phaseFrom(...hints) { return PHASES.indexOf(phaseLabel(...hints)); }
function phaseLabel(...hints) {
  for (const h of hints) {
    const s = String(h || "").toLowerCase();
    if (!s) continue;
    if (/post|vfx|3d|cgi/.test(s)) return "Post / VFX";
    if (/korr|leverans|deliver/.test(s)) return "Korr & leverans";
    if (/pre|förprod|planering/.test(s)) return "Pre-prod";
    if (/prod|shoot|inspel/.test(s)) return "Produktion";
    if (/löpande|retainer|ongoing/.test(s)) return "Löpande";
    if (/uppstart|brief|offert|pitch|start/.test(s)) return "Uppstart";
    const exact = PHASES.find((p) => p.toLowerCase() === s);
    if (exact) return exact;
  }
  return "Uppstart";
}

/** Kör hela synken: hämta → spara råa items → mappa → skriv projects/people/absence. */
export async function syncMonday(store, { boardIds } = {}) {
  const ids = (boardIds?.length ? boardIds : (process.env.MONDAY_BOARDS || "").split(",")).map((s) => s.trim()).filter(Boolean);
  if (!ids.length) throw new Error("Inga boards angivna (MONDAY_BOARDS i .env eller ?boards=1,2)");
  const boards = [];
  for (const id of ids) {
    const b = await fetchBoardItems(id);
    await store.upsertMondayItems(b.items);
    boards.push(b);
  }
  const existing = { projects: await store.list("projects"), people: await store.list("people"), absence: await store.list("absence") };
  const mapped = mapMonday(boards, existing);
  let n = 0;
  for (const col of ["projects", "people", "absence"]) {
    for (const [id, data] of Object.entries(mapped[col])) {
      // Behåll lokala fält (färg, väntar på, budget, deliveries, manuellt satt fas) som inte kommer från Monday
      const prev = existing[col][id] || {};
      const merged = { ...prev, ...data };
      if (col === "projects") { if (prev.color != null) merged.color = prev.color; if (prev.waiting) merged.waiting = prev.waiting; if (prev.budget != null) merged.budget = prev.budget; if (prev.deliveries) merged.deliveries = prev.deliveries; }
      await store.set(col, id, merged);
      n++;
    }
  }
  return { boards: boards.map((b) => ({ name: b.board.name, items: b.items.length })), written: n };
}

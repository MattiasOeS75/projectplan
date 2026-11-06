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
export function mapMonday(boards) {
  const out = { projects: {}, people: {}, absence: {} };
  for (const { board, items } of boards) {
    const byTitle = Object.fromEntries(board.columns.map((c) => [c.title.toLowerCase(), c.id]));
    const col = (it, ...titles) => { for (const t of titles) { const id = byTitle[t.toLowerCase()]; if (id && it.columns[id]?.text) return it.columns[id].text; } return ""; };
    for (const it of items) {
      // Heuristik tills mappningen är bestämd: items med "Status"+"Deadline" tolkas som projekt.
      if (byTitle["status"] || byTitle["deadline"]) {
        out.projects[`m${it.item_id}`] = {
          name: it.name,
          code: col(it, "kod", "projektnummer", "code"),
          client: col(it, "kund", "client"),
          phase: phaseFrom(it.group_title, col(it, "fas", "phase", "status")),
          status: col(it, "status"),
          delivery: toISO(col(it, "deadline", "leverans", "due date", "leveransdatum")),
          waiting: col(it, "väntar på", "waiting"),
          ownerName: col(it, "producent", "ansvarig", "owner", "person"),
          source: { monday: { board: board.name, item: it.item_id } },
        };
      }
    }
  }
  return out;
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
  const mapped = mapMonday(boards);
  let n = 0;
  for (const col of ["projects", "people", "absence"]) {
    const existing = await store.list(col);
    for (const [id, data] of Object.entries(mapped[col])) {
      // Behåll lokala fält (t.ex. color, waitingOn) som inte kommer från Monday
      await store.set(col, id, { ...(existing[id] || {}), ...data });
      n++;
    }
  }
  return { boards: boards.map((b) => ({ name: b.board.name, items: b.items.length })), written: n };
}

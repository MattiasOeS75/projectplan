// Dokumentlager: Postgres (tabellen docs) eller lokal JSON-fil när PGHOST saknas.
import fs from "node:fs";
import path from "node:path";

const COLLECTIONS = new Set(["projects", "people", "blocks", "absence"]);
export const isCollection = (c) => COLLECTIONS.has(c);

export async function createStore() {
  if (process.env.PGHOST) return pgStore();
  return fileStore(path.resolve("data/local.json"));
}

/* ---------- Postgres ---------- */
async function pgStore() {
  const { default: pg } = await import("pg");
  const pool = new pg.Pool({
    host: process.env.PGHOST,
    port: +(process.env.PGPORT || 5432),
    database: process.env.PGDATABASE,
    user: process.env.PGUSER,
    password: process.env.PGPASSWORD,
    ssl: process.env.PGSSL === "false" ? false : { rejectUnauthorized: false },
    max: 5,
  });
  await pool.query(fs.readFileSync(new URL("../db/schema.sql", import.meta.url), "utf8"));
  return {
    kind: "postgres",
    async list(col) {
      const { rows } = await pool.query("SELECT id, data FROM docs WHERE collection=$1", [col]);
      return Object.fromEntries(rows.map((r) => [r.id, r.data]));
    },
    async set(col, id, data) {
      await pool.query(
        `INSERT INTO docs (collection,id,data) VALUES ($1,$2,$3)
         ON CONFLICT (collection,id) DO UPDATE SET data=EXCLUDED.data, updated_at=now()`,
        [col, id, data]
      );
    },
    async del(col, id) {
      await pool.query("DELETE FROM docs WHERE collection=$1 AND id=$2", [col, id]);
    },
    async close() { await pool.end(); },
  };
}

/* ---------- Lokal JSON-fil (för test utan databas) ---------- */
function fileStore(file) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  let db = { projects: {}, people: {}, blocks: {}, absence: {}, monday_items: {} };
  try { db = { ...db, ...JSON.parse(fs.readFileSync(file, "utf8")) }; } catch {}
  const save = () => fs.writeFileSync(file, JSON.stringify(db, null, 2));
  return {
    kind: "file",
    async list(col) { return { ...db[col] }; },
    async set(col, id, data) { db[col][id] = data; save(); },
    async del(col, id) { delete db[col][id]; save(); },
    async close() {},
  };
}

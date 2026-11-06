// Crosby produktionsplan – API + statisk server.
// Kör: npm start   (läser .env i repots rot)
import "dotenv/config";
import express from "express";
import path from "node:path";
import { createStore, isCollection } from "./store.js";
import { syncMonday, listBoards } from "./monday.js";

const app = express();
app.use(express.json({ limit: "1mb" }));

const store = await createStore();
console.log(`Lager: ${store.kind}${store.kind === "file" ? " (data/local.json – sätt PGHOST i .env för Postgres)" : ""}`);

const col = (req, res, next) => (isCollection(req.params.col) ? next() : res.status(404).json({ error: "okänd collection" }));

app.get("/api/:col", col, async (req, res, next) => {
  try { res.json(await store.list(req.params.col)); } catch (e) { next(e); }
});
app.put("/api/:col/:id", col, async (req, res, next) => {
  try {
    if (!req.body || typeof req.body !== "object") return res.status(400).json({ error: "body måste vara ett objekt" });
    await store.set(req.params.col, req.params.id, req.body);
    res.json({ ok: true });
  } catch (e) { next(e); }
});
app.delete("/api/:col/:id", col, async (req, res, next) => {
  try { await store.del(req.params.col, req.params.id); res.json({ ok: true }); } catch (e) { next(e); }
});

// Monday
app.get("/api/monday/boards", async (_req, res, next) => {
  try { res.json(await listBoards()); } catch (e) { next(e); }
});
app.post("/api/monday/sync", async (req, res, next) => {
  try {
    const boardIds = String(req.query.boards || "").split(",").filter(Boolean);
    res.json(await syncMonday(store, { boardIds }));
  } catch (e) { next(e); }
});

app.use(express.static(path.resolve("public")));
app.use((err, _req, res, _next) => { console.error(err); res.status(500).json({ error: err.message }); });

const port = +(process.env.PORT || 3000);
app.listen(port, () => console.log(`Produktionsplan: http://localhost:${port}`));

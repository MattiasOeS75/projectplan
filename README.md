# Crosby produktionsplan

Pipeline (projekt per fas) + beläggning (personer × arbetsdagar, FM/EM), med data i Postgres
och projekt hämtade från Monday.com.

Utgångspunkt: Claude-artefakten *Crosby Produktionsplan* (`source/crosby-produktionsplan.html`,
orörd kopia). `public/index.html` är samma sida men med artefaktens databas utbytt mot ett litet
API (`localDb`-shim längst ner i filen).

## Struktur

```
source/   originalartefakten (referens, ändras inte)
public/   index.html – sidan som servern levererar
server/   server.js (Express, API + statiska filer), store.js (Postgres / JSON-fil), monday.js (synk)
db/       schema.sql – tabeller docs (planeringsdata) och monday_items (rå Monday-data)
scripts/  boards.js (lista Monday-boards), sync.js (kör synk från terminalen)
```

## Kom igång

```bash
npm install
cp .env.example .env     # fyll i Postgres-uppgifter och MONDAY_TOKEN
npm start                # http://localhost:3000
```

Utan `PGHOST` i `.env` körs allt mot `data/local.json` – bra för att testa. Med `PGHOST` satt skapas
tabellerna automatiskt vid start (`db/schema.sql`).

## API

| Metod | Väg | Beskrivning |
|---|---|---|
| GET | `/api/{projects\|people\|blocks\|absence}` | alla dokument som `{ id: data }` |
| PUT | `/api/:col/:id` | skapa/ersätt dokument (JSON-body) |
| DELETE | `/api/:col/:id` | ta bort |
| GET | `/api/monday/boards` | boards kontot ser (id, workspace, namn) |
| POST | `/api/monday/sync?boards=1,2` | hämta items → `monday_items` → mappa → `projects` |

## Datamodell (samma som artefakten)

- **projects**: `name, code (≤8 tecken), client, color (0–8 eller "#hex"), phase (0–5), status, waiting, delivery (YYYY-MM-DD), budget, owner (people-id), archived, deliveries`
- **people**: `name, role, order`
- **blocks**: `personId, date, part (full|am|pm), projectId, note, tentative`
- **absence**: `personId (utelämnad = alla), from, to, part, label`

Faser: `0 Uppstart · 1 Pre-prod · 2 Produktion · 3 Post / VFX · 4 Korr & leverans · 5 Löpande`

## Monday

1. `npm run boards` – lista boards och hitta id:n.
2. Sätt `MONDAY_BOARDS=…` i `.env`.
3. `npm run sync` (eller POST `/api/monday/sync`).

Mappningen ligger i `mapMonday()` i `server/monday.js` och är en första heuristik (kolumner som heter
*Status/Deadline/Kund/Producent* etc.). Den justeras när boardens faktiska kolumner är kända.
Synken skriver över Monday-fälten men behåller lokala fält som färg och "väntar på".

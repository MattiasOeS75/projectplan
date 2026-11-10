# Crosby produktionsplan

Pipeline (projekt per fas) + beläggning (personer × arbetsdagar, FM/EM), med data i Postgres
och projekt hämtade från Monday.com.

Utgångspunkt: Claude-artefakten *Crosby Produktionsplan* (`source/crosby-produktionsplan.html`,
orörd kopia). `public/index.html` är samma sida men med artefaktens databas utbytt mot ett litet
API (`localDb`-shim längst ner i filen).

## Struktur

```
deploy/   det som körs på webbhotellet: index.html, api.php (API + Monday/Sheets-synk + cron-kommandon), .htaccess
public/   index.html – samma sida, för lokal utveckling (kopieras till deploy/)
server/   lokal utvecklingsserver (Express + Postgres eller JSON-fil). Ingen synk – den sköts av api.php.
db/       schema.sql – docs, docs_history (historik), monday_items
tests/    php tests/run.php – parsers och mappning mot fixturer (körs i CI vid push)
source/   originalartefakten (referens, ändras inte)
```

**PHP-API:t är sanningen.** All synklogik finns i `deploy/api.php`; Node-servern är bara för att jobba lokalt med sidan.

## Kom igång

```bash
npm install
cp .env.example .env     # fyll i Postgres-uppgifter och MONDAY_TOKEN
npm start                # http://localhost:3000
```

Utan `PGHOST` i `.env` körs allt mot `data/local.json` – bra för att testa. Med `PGHOST` satt skapas
tabellerna automatiskt vid start (`db/schema.sql`). Synk lokalt: `npm run sync` (kräver `php` och `deploy/config.php`).
Tester: `npm test`.

## API

| Metod | Väg | Beskrivning |
|---|---|---|
| GET | `/api/{projects\|people\|blocks\|absence}` | alla dokument som `{ id: data }` |
| PUT | `/api/:col/:id` | skapa/ersätt dokument (JSON-body) |
| DELETE | `/api/:col/:id` | ta bort |
| GET | `/api/monday/boards` | boards kontot ser (id, workspace, namn) |
| POST | `/api/monday/sync?boards=1,2` | Monday-synk + tidsplaner (samma som knappen *Synka Monday*) |
| POST | `/api/sheets/sync` | bara tidsplaner |
| GET | `/api/info` | serverns katalog/PHP-version (för .htaccess och cron) |

Sidan och API:t ligger bakom inloggning (Basic Auth, se `deploy/README.md`).

## Datamodell (samma som artefakten)

- **projects**: `name, code (≤8 tecken), client, color (0–8 eller "#hex"), phase (0–5), status, waiting, delivery (YYYY-MM-DD), budget, owner (people-id), archived, deliveries`
- **people**: `name, role, order`
- **blocks**: `personId, date, part (full|am|pm), projectId, note, tentative`
- **absence**: `personId (utelämnad = alla), from, to, part, label`

Faser: `0 Uppstart · 1 Pre-prod · 2 Produktion · 3 Post / VFX · 4 Korr & leverans · 5 Löpande`

## Tidsplaner (Google Sheets)

Projekt med en Sheets-länk i fältet *Tidsplan* (eller en länk i någon Monday-kolumn) synkas med
`POST /api/sheets/sync` (körs även efter varje Monday-synk, `npm run sheets` lokalt). Arket måste vara delat
"alla med länken kan visa". Läses:

- flik **Tidsplan** (`Vecka | DAG | DATUM | ett spår per kolumn`, rubrikraden får ligga under en titel): raden med
  `Leverans`/`Delivery` sätter projektets leveransdag (om den inte satts manuellt). Övriga rader läses men läggs inte
  in som milstolpar – i planeringen räcker leveransflaggan.
- flik **Format** / **Leverans** / **Leveranser** / **Deliveries**: tabellen blir leveranslistan i fliken *Leveranser*, grupperad på kolumnen *Land*.

Manuellt inlagda milstolpar påverkas inte av synken.

## Monday

Synken körs av knappen *Synka Monday*, av cron på webbhotellet (`php api.php sync`) eller `npm run sync` lokalt.

Mappningen (`mapMonday()` i `server/monday.js`) är skriven för boardet **Projektöversikt** (1071363420):

| Monday | Plan |
|---|---|
| grupp *Pitch* | fas Uppstart |
| grupp *Pågående* | fas Produktion (manuellt ändrad fas behålls vid nästa synk) |
| grupp *Levererat* / övriga | tas inte in; tidigare synkade projekt arkiveras |
| Objekt | `name` |
| Projekt Nr. | `code` (saknas → kortnamn ur namnet, t.ex. ABSMURAK) |
| Kundregister · Kontakt | `client` |
| Status | `status` |
| Deadline, annars Projektperiod-slut | `delivery` |
| Producent | `owner` (matchas mot personer i planen på namn) |
| Involverade, Skapad, period | sparas under `source.monday` |

Lokala fält (färg, väntar på, budget, leveranser) skrivs aldrig över av synken. Projekt-id = `m<item_id>`.

### Resurser

| Monday | Plan |
|---|---|
| Användare (aktiva medlemmar, ej gäster/åskådare) | `people` `u<userId>` – namn, roll = Monday-titel (lokalt ändrad roll behålls). Försvinner personen ur Monday markeras den `inactive` och döljs i gridden; block och ägarskap behålls, och den återaktiveras om den dyker upp igen |
| **Frilansare** (1083892887) – *av som standard*; sätt `MONDAY_FRILANS` för att ta med gruppen *Aktiva Frilansare* | `people` `m<item>` – roll = Titel/Roll · frilans |
| **Frånvaro** (1071497633), alla grupper utom INKOMMET / Ej Godkända | `absence` `a<subitem>` per period i underobjekten (Typ av Frånvaro → typ); personen matchas på *Medarbetare*. Bara perioder som slutar innevarande år eller senare tas med |

### Planering (underobjekt → block)

Underobjekten på Projektöversikt är planeringen (samma data som TeamBoard-vyn *Planering* i Monday):
**Ansvarig** + **Period** + **Est. tid** → ett block per arbetsdag (mån–fre) i perioden, på personen, med
underobjektets namn som notering och Est. tid som timmar per dag. Blocken är märkta `src: "monday"` (visas med ett
litet M) och ersätts vid varje synk – ändra dem i Monday. Manuellt skapade block rörs inte. Planering äldre än
förra året tas inte med.

Ordningen på personer i gridden är lokal och behålls. Frånvaro från Monday ersätts vid varje synk; manuellt inlagd frånvaro rörs inte.

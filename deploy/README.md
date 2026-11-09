# Deploy till webbhotell (Websupport)

`deploy/` laddas upp automatiskt av GitHub Action *Deploy till cby.se/projectplan* vid push till `main`
(FTP-kontots rot = mappen `projectplan/` i webbroten). `.htaccess` och `config.php` laddas upp manuellt.

## Första gången

1. `config.example.php` → `config.php`, fyll i Postgres-uppgifter och `MONDAY_TOKEN`. Ladda upp.
2. Lösenordsskydd: skapa `.htpasswd` lokalt och ladda upp till samma mapp:
   ```bash
   htpasswd -nbB crosby 'välj-ett-långt-lösenord' > .htpasswd    # på Mac finns htpasswd inbyggt
   ```
   Hämta katalogens absoluta sökväg med `https://cby.se/projectplan/api/info` (fältet `dir`), skriv in den i
   `.htaccess` på raden `AuthUserFile`, och ladda upp `.htaccess`. Därefter kräver sidan och API:t inloggning.
3. Första anropet skapar tabellerna (`schema.sql`), inklusive historiktabellen `docs_history`.

## Cron (Websupport-panelen → Schemalagda jobb)

| När | Kommando |
|---|---|
| var 15:e min, kontorstid | `php /data/e/e/ee0f04f1-bc00-4ba0-adf2-4b60172dc598/cby.se/web/projectplan/api.php sync` |
| kl 03:00 | `php /data/e/e/ee0f04f1-bc00-4ba0-adf2-4b60172dc598/cby.se/web/projectplan/api.php nightly` (synk + backup) |

`backup` skriver `backups/plan-ÅÅÅÅ-MM-DD.json` (30 dagar sparas, mappen är blockerad för webben).
Kommandona går direkt i PHP, utan HTTP, så lösenordsskyddet påverkar dem inte.

## Historik / ångra

Varje ändring av ett dokument sparar den tidigare versionen i `docs_history` (trigger i Postgres):

```sql
SELECT changed_at, op, data FROM docs_history WHERE collection='projects' AND id='m123' ORDER BY changed_at DESC;
```

Kräver PHP 8.1+ med `pdo_pgsql` och `curl`.

# Deploy till webbhotell (Websupport)

1. Kopiera `config.example.php` → `config.php` och fyll i Postgres-uppgifter och `MONDAY_TOKEN`.
2. Ladda upp hela mappen till `projectplan/` i webbroten (så att `https://cby.se/projectplan/` visar `index.html`).
   Ligger den någon annanstans: ändra `RewriteBase` i `.htaccess`.
3. Första anropet skapar tabellerna (`schema.sql`).
4. Synka från Monday: `curl -X POST https://cby.se/projectplan/api/monday/sync`
   (eller lägg samma anrop som cron-jobb i Websupports panel, t.ex. var 15:e minut).

Kräver PHP 8.1+ med `pdo_pgsql` och `curl` (standard hos Websupport).

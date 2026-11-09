<?php
/**
 * Crosby produktionsplan – API för webbhotell (PHP + Postgres).
 * Samma routes som server/server.js:
 *   GET    api/{projects|people|blocks|absence}      → { id: data }
 *   PUT    api/{col}/{id}                            → skapa/ersätt
 *   DELETE api/{col}/{id}
 *   GET    api/monday/boards
 *   POST   api/monday/sync?boards=1,2   (eller MONDAY_BOARDS i config)
 * Konfiguration i config.php (se config.example.php).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function main(): void {
$cfg = @include __DIR__ . '/config.php';
if (!is_array($cfg)) fail(500, 'config.php saknas – kopiera config.example.php');

$path = trim($_GET['path'] ?? '', '/');
$parts = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];
$COLS = ['projects', 'people', 'blocks', 'absence'];

try {
    $db = pdo($cfg);

    // ---- Monday ----
    if (($parts[0] ?? '') === 'monday') {
        $sub = $parts[1] ?? '';
        if ($sub === 'boards' && $method === 'GET') out(monday_boards($cfg));
        if ($sub === 'sync' && $method === 'POST') {
            $ids = array_filter(array_map('trim', explode(',', $_GET['boards'] ?? ($cfg['MONDAY_BOARDS'] ?? ''))));
            $r = monday_sync($db, $cfg, array_values($ids));
            $r['sheets'] = sheets_sync($db);
            out($r);
        }
        fail(404, 'okänd monday-route');
    }
    // ---- Tidsplaner i Google Sheets → milstolpar, leveransdag, leveranslista ----
    if (($parts[0] ?? '') === 'sheets' && ($parts[1] ?? '') === 'sync' && $method === 'POST') out(sheets_sync($db));

    // ---- Dokument ----
    $col = $parts[0] ?? '';
    if (!in_array($col, $COLS, true)) fail(404, 'okänd collection');
    $id = $parts[1] ?? null;

    if ($method === 'GET' && $id === null) {
        $st = $db->prepare('SELECT id, data FROM docs WHERE collection = ?');
        $st->execute([$col]);
        $o = new stdClass();
        foreach ($st as $r) $o->{$r['id']} = json_decode($r['data']);
        out($o);
    }
    if ($method === 'PUT' && $id !== null) {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) fail(400, 'body måste vara ett JSON-objekt');
        doc_set($db, $col, $id, $body);
        out(['ok' => true]);
    }
    if ($method === 'DELETE' && $id !== null) {
        $db->prepare('DELETE FROM docs WHERE collection = ? AND id = ?')->execute([$col, $id]);
        out(['ok' => true]);
    }
    fail(405, 'metoden stöds inte');
} catch (Throwable $e) {
    fail(500, $e->getMessage());
}
}

/* ================= helpers ================= */
function out($data): never { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(int $code, string $msg): never { http_response_code($code); out(['error' => $msg]); }

function pdo(array $cfg): PDO {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['PGHOST'], (int)($cfg['PGPORT'] ?? 5432), $cfg['PGDATABASE']);
    $dsn .= ';sslmode=' . (empty($cfg['PGSSL']) ? 'disable' : 'prefer');
    $db = new PDO($dsn, $cfg['PGUSER'], $cfg['PGPASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    static $init = false;
    if (!$init) { $db->exec(file_get_contents(__DIR__ . '/schema.sql')); $init = true; }
    return $db;
}

function doc_set(PDO $db, string $col, string $id, array $data): void {
    $db->prepare('INSERT INTO docs (collection,id,data) VALUES (?,?,?::jsonb)
                  ON CONFLICT (collection,id) DO UPDATE SET data = EXCLUDED.data, updated_at = now()')
       ->execute([$col, $id, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}
function doc_list(PDO $db, string $col): array {
    $st = $db->prepare('SELECT id, data FROM docs WHERE collection = ?');
    $st->execute([$col]);
    $o = [];
    foreach ($st as $r) $o[$r['id']] = json_decode($r['data'], true);
    return $o;
}

/* ================= Monday ================= */
function monday_query(array $cfg, string $query, array $vars = []): array {
    if (empty($cfg['MONDAY_TOKEN'])) throw new RuntimeException('MONDAY_TOKEN saknas i config.php');
    $ch = curl_init('https://api.monday.com/v2');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $cfg['MONDAY_TOKEN'], 'API-Version: 2024-10'],
        CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => $vars ?: new stdClass()]),
    ]);
    $res = curl_exec($ch);
    if ($res === false) throw new RuntimeException('Monday: ' . curl_error($ch));
    $j = json_decode($res, true);
    if (!empty($j['errors'])) throw new RuntimeException('Monday: ' . json_encode($j['errors'], JSON_UNESCAPED_UNICODE));
    return $j['data'] ?? [];
}

function monday_boards(array $cfg): array {
    $d = monday_query($cfg, '{ boards(limit: 200, order_by: used_at) { id name state board_kind workspace { name } } }');
    return array_values(array_filter($d['boards'] ?? [], fn($b) => ($b['state'] ?? '') === 'active'));
}

function monday_users(array $cfg): array {
    $d = monday_query($cfg, '{ users(kind: non_guests) { id name title enabled is_guest is_view_only } }');
    return array_values(array_filter($d['users'] ?? [], fn($u) => !empty($u['enabled']) && empty($u['is_guest']) && empty($u['is_view_only'])));
}

function monday_board_items(array $cfg, string $boardId, bool $withSubitems = false): array {
    $c = monday_query($cfg, 'query($id:[ID!]){ boards(ids:$id){ name columns { id title type } groups { id title } } }', ['id' => [$boardId]]);
    $board = $c['boards'][0] ?? null;
    if (!$board) throw new RuntimeException("Board $boardId hittades inte");
    $items = []; $cursor = null;
    do {
        $d = monday_query($cfg, 'query($id:[ID!],$cursor:String){ boards(ids:$id){ items_page(limit:200, cursor:$cursor){ cursor
                 items { id name group { id title } column_values { id text value type } '
                 . ($withSubitems ? 'subitems { id name column_values { id text value type column { title } } } ' : '') . '} } } }', ['id' => [$boardId], 'cursor' => $cursor]);
        $page = $d['boards'][0]['items_page'];
        foreach ($page['items'] as $it) {
            $cols = [];
            foreach ($it['column_values'] as $cv) {
                $val = $cv['value'] ? json_decode($cv['value'], true) : null;
                $cols[$cv['id']] = ['text' => $cv['text'], 'value' => $val ?? $cv['value'], 'type' => $cv['type']];
            }
            $subs = [];
            foreach ($it['subitems'] ?? [] as $si) {
                $sc = [];
                foreach ($si['column_values'] as $cv) {
                    $val = $cv['value'] ? json_decode($cv['value'], true) : null;
                    $sc[$cv['column']['title'] ?? $cv['id']] = ['text' => $cv['text'], 'value' => $val ?? $cv['value'], 'type' => $cv['type']];
                }
                $subs[] = ['id' => (int)$si['id'], 'name' => $si['name'], 'columns' => $sc];
            }
            $items[] = ['board_id' => (int)$boardId, 'item_id' => (int)$it['id'], 'name' => $it['name'],
                        'group_id' => $it['group']['id'] ?? null, 'group_title' => $it['group']['title'] ?? '', 'columns' => $cols, 'subitems' => $subs];
        }
        $cursor = $page['cursor'];
    } while ($cursor);
    return ['board' => $board, 'items' => $items];
}

const PHASES = ['Uppstart', 'Pre-prod', 'Produktion', 'Post / VFX', 'Korr & leverans', 'Löpande'];
const GROUPS = ['pitch' => 0, 'pågående' => 2];

function norm($s): string { return mb_strtolower(trim((string)$s)); }
function to_iso($s): string { return preg_match('/\d{4}-\d{2}-\d{2}/', (string)$s, $m) ? $m[0] : ''; }
function short_code(string $name): string {
    $words = array_values(array_filter(preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $name)),
        fn($w) => mb_strlen($w) > 1 && !preg_match('/^(och|the|and|av|x|ml)$/iu', $w)));
    if (!$words) return mb_strtoupper(mb_substr($name, 0, 8));
    if (count($words) === 1) return mb_strtoupper(mb_substr($words[0], 0, 8));
    return mb_strtoupper(mb_substr($words[0], 0, 3) . mb_substr($words[1], 0, 5));
}
function next_color(array ...$sets): int {
    $used = array_fill(0, 9, 0);
    foreach ($sets as $set) foreach ($set as $p) if (isset($p['color']) && is_int($p['color']) && $p['color'] >= 0 && $p['color'] < 9) $used[$p['color']]++;
    $best = 0; foreach ($used as $i => $n) if ($n < $used[$best]) $best = $i;
    return $best;
}

/** Board "Projektöversikt": grupperna Pitch och Pågående → projekt. Speglar mapMonday() i server/monday.js. */
function map_monday(array $boards, array $existing): array {
    $out = ['projects' => []];
    $peopleByName = [];
    foreach ($existing['people'] as $pid => $p) $peopleByName[norm($p['name'] ?? '')] = $pid;
    foreach ($boards as ['board' => $board, 'items' => $items]) {
        $byTitle = [];
        foreach ($board['columns'] as $c) $byTitle[norm($c['title'])] = $c['id'];
        $col = function ($it, string ...$titles) use ($byTitle): string {
            foreach ($titles as $t) { $id = $byTitle[norm($t)] ?? null; if ($id && !empty($it['columns'][$id]['text'])) return trim($it['columns'][$id]['text']); }
            return '';
        };
        $raw = function ($it, string ...$titles) use ($byTitle) {
            foreach ($titles as $t) { $id = $byTitle[norm($t)] ?? null; if ($id && !empty($it['columns'][$id]['value'])) return $it['columns'][$id]['value']; }
            return null;
        };
        foreach ($items as $it) {
            $g = norm($it['group_title']);
            $id = 'm' . $it['item_id'];
            $prev = $existing['projects'][$id] ?? [];
            if (!array_key_exists($g, GROUPS)) {
                if ($prev && empty($prev['archived'])) $out['projects'][$id] = $prev + ['archived' => true];
                continue;
            }
            $status = $col($it, 'Status');
            $period = $raw($it, 'Projektperiod');
            $deadline = to_iso(is_array($raw($it, 'Deadline')) ? ($raw($it, 'Deadline')['date'] ?? '') : '') ?: to_iso($col($it, 'Deadline')) ?: to_iso(is_array($period) ? ($period['to'] ?? '') : '');
            $producent = $col($it, 'Producent');
            $blob = '';
            foreach ($it['columns'] as $c) $blob .= json_encode($c['value'] ?? '', JSON_UNESCAPED_SLASHES) . ' ' . ($c['text'] ?? '') . ' ';
            $sheetUrl = preg_match('#https://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]+[^"\s\\]*#', $blob, $mm) ? $mm[0] : '';
            // Fas: Pitch → Uppstart. Pågående → Produktion vid första synk eller när gruppen bytts i Monday; annars behålls manuellt satt fas.
            $sameGroup = isset($prev['source']['monday']) && norm($prev['source']['monday']['group'] ?? '') === $g;
            $phase = $g === 'pitch' ? 0 : (($sameGroup && isset($prev['phase']) && (int)$prev['phase'] !== 0) ? $prev['phase'] : GROUPS[$g]); // Pågående står aldrig kvar i Uppstart
            $out['projects'][$id] = [
                'name' => $it['name'],
                'code' => $col($it, 'Projekt Nr.') ?: short_code($it['name']),
                'client' => implode(' · ', array_filter([$col($it, 'Kundregister'), $col($it, 'Kontakt')])),
                'phase' => $phase,
                'color' => $prev['color'] ?? next_color($existing['projects'], $out['projects']),
                'status' => $status,
                'delivery' => $deadline,
                'owner' => $peopleByName[norm($producent)] ?? ($prev['owner'] ?? ''),
                'sheet' => $prev['sheet'] ?? $sheetUrl,
                'archived' => false,
                'source' => ['monday' => ['board' => $board['name'], 'boardId' => $it['board_id'], 'item' => $it['item_id'], 'group' => $it['group_title'],
                    'status' => $status, 'producent' => $producent, 'involverade' => $col($it, 'Involverade'),
                    'period' => is_array($period) && !empty($period['from']) ? $period['from'] . ' – ' . ($period['to'] ?? '') : '', 'created' => $col($it, 'Skapad')]],
            ];
        }
    }
    return $out;
}

/** Resurser: Monday-användare + Frilansare-board → people, Frånvaro-board → absence. Speglar mapResources() i server/monday.js. */
function map_resources(array $users, ?array $frilans, ?array $franvaro, array $existing): array {
    $out = ['people' => [], 'absence' => []];
    $prev = $existing['people'] ?? [];
    $order = 0; foreach ($prev as $p) $order = max($order, (int)($p['order'] ?? 0));
    $add = function (string $id, array $data) use (&$out, $prev, &$order) {
        $pp = $prev[$id] ?? [];
        $out['people'][$id] = array_merge(['kind' => 'person'], $pp, $data, ['order' => $pp['order'] ?? ++$order]);
    };
    foreach ($users as $u) $add('u' . $u['id'], ['name' => $u['name'], 'role' => $prev['u' . $u['id']]['role'] ?? ($u['title'] ?? ''), 'source' => ['monday' => ['userId' => (int)$u['id']]]]);
    if ($frilans) {
        $byTitle = []; foreach ($frilans['board']['columns'] as $c) $byTitle[norm($c['title'])] = $c['id'];
        $col = fn($it, $t) => trim($it['columns'][$byTitle[norm($t)] ?? '']['text'] ?? '');
        foreach ($frilans['items'] as $it) {
            $g = $it['group_title'] ?? '';
            if (!preg_match('/aktiva/iu', $g) || preg_match('/inaktiva/iu', $g)) continue;
            $add('m' . $it['item_id'], ['name' => $it['name'], 'role' => implode(' · ', array_filter([$col($it, 'Titel/Roll'), 'frilans'])),
                'source' => ['monday' => ['boardId' => $it['board_id'], 'item' => $it['item_id'], 'typ' => $col($it, 'Typ'), 'foretag' => $col($it, 'Företag')]]]);
        }
    }
    $byName = []; foreach (array_merge($prev, $out['people']) as $id => $p) $byName[norm($p['name'] ?? '')] = $id;
    if ($franvaro) {
        $byTitle = []; foreach ($franvaro['board']['columns'] as $c) $byTitle[norm($c['title'])] = $c['id'];
        $col = fn($it, $t) => trim($it['columns'][$byTitle[norm($t)] ?? '']['text'] ?? '');
        $raw = fn($it, $t) => $it['columns'][$byTitle[norm($t)] ?? '']['value'] ?? null;
        foreach ($franvaro['items'] as $it) {
            if (preg_match('/inkommet|ej godkända/iu', $it['group_title'] ?? '')) continue;
            $who = $col($it, 'Medarbetare') ?: ($it['group_title'] ?? '');
            $personId = $byName[norm($who)] ?? null;
            if (!$personId) continue;
            $type = $col($it, 'Typ av Frånvaro') ?: preg_replace('/^.*? - /u', '', $it['name']);
            $periods = [];
            foreach ($it['subitems'] ?? [] as $si) {
                foreach ($si['columns'] as $c) if (is_array($c['value'] ?? null) && !empty($c['value']['from']) && !empty($c['value']['to'])) { $periods[] = ['id' => 'a' . $si['id'], 'from' => $c['value']['from'], 'to' => $c['value']['to'], 'note' => $si['name']]; break; }
            }
            $own = $raw($it, 'Period');
            if (!$periods && is_array($own) && !empty($own['from']) && !empty($own['to'])) $periods[] = ['id' => 'a' . $it['item_id'], 'from' => $own['from'], 'to' => $own['to'], 'note' => $it['name']];
            foreach ($periods as $p) $out['absence'][$p['id']] = ['personId' => $personId, 'from' => $p['from'], 'to' => $p['to'], 'part' => 'full', 'type' => $type, 'note' => $p['note'],
                'source' => ['monday' => ['boardId' => $it['board_id'], 'item' => $it['item_id']]]];
        }
    }
    return $out;
}

function monday_sync(PDO $db, array $cfg, array $boardIds): array {
    if (!$boardIds) throw new RuntimeException('Inga boards angivna (MONDAY_BOARDS i config.php eller ?boards=1,2)');
    $boards = [];
    $ins = $db->prepare('INSERT INTO monday_items (board_id,item_id,name,group_id,columns) VALUES (?,?,?,?,?::jsonb)
        ON CONFLICT (board_id,item_id) DO UPDATE SET name=EXCLUDED.name, group_id=EXCLUDED.group_id, columns=EXCLUDED.columns, synced_at=now()');
    foreach ($boardIds as $bid) {
        $b = monday_board_items($cfg, $bid);
        foreach ($b['items'] as $it) $ins->execute([$it['board_id'], $it['item_id'], $it['name'], $it['group_id'], json_encode($it['columns'], JSON_UNESCAPED_UNICODE)]);
        $boards[] = $b;
    }
    $existing = ['projects' => doc_list($db, 'projects'), 'people' => doc_list($db, 'people'), 'absence' => doc_list($db, 'absence')];
    // Resurser
    $users = []; try { $users = monday_users($cfg); } catch (Throwable $e) { /* saknar rättighet? fortsätt utan */ }
    $fr = !empty($cfg['MONDAY_FRILANS']) ? monday_board_items($cfg, (string)$cfg['MONDAY_FRILANS']) : null;
    $fv = ($cfg['MONDAY_FRANVARO'] ?? '1071497633') ? monday_board_items($cfg, (string)($cfg['MONDAY_FRANVARO'] ?? '1071497633'), true) : null;
    $res = map_resources($users, $fr, $fv, $existing);
    foreach ($res['people'] as $id => $data) { doc_set($db, 'people', $id, $data); $existing['people'][$id] = $data; }
    $del = $db->prepare('DELETE FROM docs WHERE collection = ? AND id = ?');
    // Personer som tidigare synkats från Frilansare-boardet tas bort när den är avstängd
    if (empty($cfg['MONDAY_FRILANS'])) foreach ($existing['people'] as $id => $p) if (!empty($p['source']['monday']['boardId']) && !isset($res['people'][$id])) { $del->execute(['people', $id]); unset($existing['people'][$id]); }
    foreach ($existing['absence'] as $id => $a) if (isset($a['source']['monday']) && !isset($res['absence'][$id])) $del->execute(['absence', $id]);
    foreach ($res['absence'] as $id => $data) doc_set($db, 'absence', $id, $data);
    $mapped = map_monday($boards, $existing);
    $n = 0;
    foreach ($mapped['projects'] as $id => $data) {
        $prev = $existing['projects'][$id] ?? [];
        $merged = array_merge($prev, $data);
        foreach (['color', 'waiting', 'budget', 'deliveries'] as $k) if (isset($prev[$k]) && $prev[$k] !== '' && $prev[$k] !== null) $merged[$k] = $prev[$k];
        doc_set($db, 'projects', $id, $merged);
        $n++;
    }
    return ['boards' => array_map(fn($b) => ['name' => $b['board']['name'], 'items' => count($b['items'])], $boards),
            'projects' => $n, 'people' => count($res['people']), 'absence' => count($res['absence'])];
}

/* ================= Google Sheets (tidsplaner) ================= */
const MONTHS = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'maj'=>5,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'okt'=>10,'oct'=>10,'nov'=>11,'dec'=>12];

function sheet_id($url): string { return preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', (string)$url, $m) ? $m[1] : ''; }

function sheet_fetch_tab(string $id, string $tab): array {
    $u = "https://docs.google.com/spreadsheets/d/$id/gviz/tq?tqx=out:csv&sheet=" . rawurlencode($tab);
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'produktionsplan/1.0']);
    $t = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($t === false || $code >= 400) throw new RuntimeException("Sheets $tab: HTTP $code (är arket delat 'alla med länken'?)");
    if (preg_match('/<html/i', substr($t, 0, 200))) throw new RuntimeException("Sheets $tab: fick inloggningssida – dela arket 'alla med länken'");
    return parse_csv($t);
}

function parse_csv(string $text): array {
    $rows = []; $row = []; $cell = ''; $q = false; $n = strlen($text);
    for ($i = 0; $i < $n; $i++) {
        $ch = $text[$i];
        if ($q) { if ($ch === '"') { if (($text[$i + 1] ?? '') === '"') { $cell .= '"'; $i++; } else $q = false; } else $cell .= $ch; }
        elseif ($ch === '"') $q = true;
        elseif ($ch === ',') { $row[] = $cell; $cell = ''; }
        elseif ($ch === "\n" || $ch === "\r") { if ($ch === "\r" && ($text[$i + 1] ?? '') === "\n") $i++; $row[] = $cell; $rows[] = $row; $row = []; $cell = ''; }
        else $cell .= $ch;
    }
    if ($cell !== '' || $row) { $row[] = $cell; $rows[] = $row; }
    return array_values(array_filter($rows, fn($r) => count(array_filter($r, fn($c) => trim($c) !== '')) > 0));
}

/** Datumparser med löpande år: "12-okt." → 2026-10-12; nytt år när månaden backar. */
function make_date_parser(int $year): Closure {
    $last = 0;
    return function ($s) use (&$year, &$last): string {
        $s = mb_strtolower(trim((string)$s));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return $m[0];
        if (!preg_match('/^(\d{1,2})[-\s.\/]*([a-zåäö]{3})/u', $s, $m)) return '';
        $mon = MONTHS[$m[2]] ?? 0; if (!$mon) return '';
        if ($last && $mon < $last) $year++;
        $last = $mon;
        return sprintf('%04d-%02d-%02d', $year, $mon, (int)$m[1]);
    };
}
const MS_KEYWORDS = '/\b(leverans|delivery|deliver|möte|avstämning|godkän\w*|approval|approved|first|second|2nd|1st|3rd|final|online|offline|first ?look|first ?view|uppstart|kickoff|kick-off|produktionsstart|inspelning|shoot|presentation|deadline|preview|review|feedback|first cut|rough cut|fine cut|screening|visning)\b/iu';
function is_milestone(string $t): bool {
    $s = trim($t); $w = preg_split('/\s+/u', $s); $first = $w[0] ?? ''; $second = $w[1] ?? '';
    if (preg_match('/^[A-ZÅÄÖ0-9][A-ZÅÄÖ0-9\/\-]{2,}$/u', $first)) return true;                       // VERSALER
    if (preg_match('/^(2nd|1st|3rd)$/i', $first) && preg_match('/^[A-ZÅÄÖ]/u', $second)) return true;
    return (bool)preg_match(MS_KEYWORDS, $s);                                                            // nyckelord
}
function is_delivery(string $t): bool { return (bool)preg_match('/^(leverans|delivery|final delivery|slutleverans)/iu', trim($t)); }
function track_name($h): string { $s = trim((string)$h); return trim(preg_match('/(?:[-–·]\s*)([^-–·]+)$/u', $s, $m) ? $m[1] : $s); }

function parse_timeline(array $rows, int $year): array {
    // Rubrikraden (den med DATUM) kan ligga under en titel
    $hi = -1; foreach ($rows as $ri => $r) { foreach ($r as $c) if (preg_match('/^datum$/i', trim($c))) { $hi = $ri; break 2; } }
    if ($hi < 0) return ['milestones' => [], 'delivery' => ''];
    $head = array_map('trim', $rows[$hi]);
    $di = -1; foreach ($head as $i => $h) if (preg_match('/^datum$/i', $h)) { $di = $i; break; }
    $body = array_slice($rows, $hi + 1);
    $width = count($head); foreach ($body as $r) $width = max($width, count($r));
    $tracks = [];
    for ($i = $di + 1; $i < $width; $i++) { $used = false; foreach ($body as $r) if (trim($r[$i] ?? '') !== '') { $used = true; break; } if ($used) $tracks[] = ['i' => $i, 'name' => track_name($head[$i] ?? '')]; }
    $named = count(array_filter($tracks, fn($t) => $t['name'] !== '')); $multi = $named > 1;
    $toDate = make_date_parser($year);
    $ms = []; $delivery = '';
    foreach ($body as $r) {
        $d = $toDate($r[$di] ?? ''); if (!$d) continue;
        foreach ($tracks as $t) {
            $raw = trim(preg_replace('/\s+/u', ' ', $r[$t['i']] ?? '')); if ($raw === '') continue;
            foreach (preg_split('/\s*·\s*/u', $raw) as $part) {
                if (!is_milestone($part)) continue;
                $text = mb_strlen($part) > 60 ? mb_substr($part, 0, 57) . '…' : $part;
                $ms[] = ['d' => $d, 't' => ($multi && $t['name'] !== '') ? $t['name'] . ': ' . $text : $text, 's' => 1];
                if (is_delivery($part) && $d > $delivery) $delivery = $d;
            }
        }
    }
    return ['milestones' => $ms, 'delivery' => $delivery];
}

function parse_formats(array $rows): ?array {
    if (count($rows) < 2) return null;
    $head = array_map('trim', $rows[0]); while ($head && end($head) === '') array_pop($head); $drop = ($head[0] ?? '') === '' ? 1 : 0;
    $cols = array_slice($head, $drop);
    // gviz ger första fliken om namnet inte finns – kräv att det ser ut som en leveranslista
    $looks = false; foreach ($cols as $c) if (preg_match('/filnamn|file ?name|format|land|country|kanal|channel|upplösning|resolution|längd|duration|leverans|deliver/iu', $c)) { $looks = true; break; }
    if (!$looks) return null;
    $li = -1; foreach ($cols as $i => $c) if (preg_match('/^(land|country|marknad)$/i', $c)) { $li = $i; break; }
    $groups = [];
    foreach (array_slice($rows, 1) as $r) {
        $c = array_map(fn($x) => trim((string)$x), array_slice(array_pad($r, $drop + count($cols), ''), $drop, count($cols)));
        if (($c[0] ?? '') === '' && ($c[1] ?? '') === '') continue;
        if (count(array_filter($c)) < 2) continue;
        $key = $li >= 0 ? ($c[$li] ?? '') : '';
        $groups[$key] ??= ['t' => $key, 'rows' => []];
        $groups[$key]['rows'][] = ['c' => $c];
    }
    return $groups ? ['cols' => $cols, 'groups' => array_values($groups)] : null;
}

function sheets_sync(PDO $db): array {
    $projects = doc_list($db, 'projects');
    $res = ['synced' => 0, 'skipped' => 0, 'errors' => []];
    foreach ($projects as $id => $p) {
        $sid = sheet_id($p['sheet'] ?? ''); if (!$sid || !empty($p['archived'])) { $res['skipped']++; continue; }
        try {
            $year = preg_match('/^(\d{4})/', (string)($p['source']['monday']['created'] ?? $p['delivery'] ?? ''), $m) ? (int)$m[1] : (int)date('Y');
            $tl = parse_timeline(sheet_fetch_tab($sid, 'Tidsplan'), $year);
            $deliveries = $p['deliveries'] ?? null;
            foreach (['Format', 'Leverans', 'Leveranser', 'Leveranslista', 'Deliveries', 'Delivery', 'Formats'] as $tab) {
                try { $d = parse_formats(sheet_fetch_tab($sid, $tab)); if ($d) { $deliveries = $d; break; } } catch (Throwable $e) { /* fliken finns inte */ }
            }
            // Bara leveransdag + formatlista till planen – inga milstolpar från arket (manuella behålls)
            $manual = array_values(array_filter($p['milestones'] ?? [], fn($m) => empty($m['s'])));
            $next = $p; $next['milestones'] = $manual; if ($deliveries) $next['deliveries'] = $deliveries;
            if ($tl['delivery'] && (empty($p['delivery']) || !empty($p['deliveryFromSheet']))) { $next['delivery'] = $tl['delivery']; $next['deliveryFromSheet'] = true; }
            $next['sheetSynced'] = date('Y-m-d\TH:i');
            doc_set($db, 'projects', $id, $next);
            $res['synced']++;
        } catch (Throwable $e) { $res['errors'][] = ['id' => $id, 'name' => $p['name'] ?? '', 'error' => $e->getMessage()]; }
    }
    return $res;
}

if (!defined('PLAN_NO_RUN')) main();

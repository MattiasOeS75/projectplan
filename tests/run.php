<?php
// Tester för deploy/api.php – parsers och mappning mot fixturer. Kör: php tests/run.php  (eller npm test)
declare(strict_types=1);
define('PLAN_NO_RUN', true);
require __DIR__ . '/../deploy/api.php';

$fails = 0; $n = 0;
function ok(bool $cond, string $msg): void { global $fails, $n; $n++; if ($cond) echo "  ✓ $msg\n"; else { $fails++; echo "  ✗ $msg\n"; } }
function fx(string $f): array { return parse_csv(file_get_contents(__DIR__ . "/fixtures/$f")); }

echo "Tidsplan (KICKS, två spår)\n";
$tl = parse_timeline(fx('kicks-tidsplan.csv'), 2026);
ok($tl['delivery'] === '2026-11-18', 'leveransdag från DELIVERY-raden');
ok(count($tl['milestones']) === 14, '14 milstolpar (versaler + nyckelord)');
ok($tl['milestones'][0]['t'] === 'Bild: UPPSTART ANIMATION / BLOCKING (all versions)', 'spårnamn som prefix');

echo "Tidsplan (KICKS enkel, ett spår)\n";
$tl = parse_timeline(fx('kicks-tidsplan-enkel.csv'), 2026);
ok($tl['delivery'] === '2026-11-06', 'LEVERANS sätter leveransdag');
ok(!str_contains($tl['milestones'][0]['t'], ':'), 'inget prefix vid ett spår');

echo "Tidsplan (Valio, titel ovanför rubrikraden, spår utan rubrik, gemener)\n";
$tl = parse_timeline(fx('valio-tidsplan.csv'), 2026);
ok($tl['delivery'] === '2026-11-20', 'leveransdag från "Leverans"');
ok(count($tl['milestones']) === 5, '5 milstolpar via nyckelord (möte/godkända/leverans)');
ok(!parse_timeline([['Vecka', 'DAG']], 2026)['delivery'], 'utan DATUM-kolumn → tomt');

echo "Årsskifte\n";
$d = make_date_parser(2026);
ok($d('30-nov.') === '2026-11-30' && $d('5-jan.') === '2027-01-05', 'december → januari ger nytt år');
ok($d('2027-02-01') === '2027-02-01', 'ISO-datum passerar');

echo "Leveranslistor\n";
$f = parse_formats(fx('kicks-format.csv'));
ok($f['cols'][0] === 'Filnamn' && count($f['cols']) === 9, 'radnummerkolumnen tas bort');
ok(array_map(fn($g) => $g['t'] . ':' . count($g['rows']), $f['groups']) === ['Sverige:13', 'Finland:10', 'Norge:18'], 'grupperat på Land, fotnoter bortfiltrerade');
$f = parse_formats(fx('valio-leverans.csv'));
ok($f['cols'][0] === 'Antal' && count($f['groups'][0]['rows']) === 4, 'Antal-kolumnen behålls');
ok(parse_formats(fx('valio-tidsplan.csv')) === null, 'tidsplan avvisas som leveranslista (gviz-fallback)');

echo "Monday → projekt\n";
$board = ['name' => 'Projektöversikt', 'columns' => array_map(fn($p) => ['id' => $p[0], 'title' => $p[1]],
    [['status', 'Status'], ['c1', 'Kundregister'], ['c2', 'Kontakt'], ['c3', 'Projekt Nr.'], ['c4', 'Producent'], ['c8', 'Deadline'], ['c9', 'Projektperiod']])];
$mk = fn($id, $name, $group, $cols) => ['board_id' => 1071363420, 'item_id' => $id, 'name' => $name, 'group_id' => strtolower($group), 'group_title' => $group,
    'columns' => array_map(fn($v) => is_array($v) ? $v : ['text' => $v, 'value' => null], $cols), 'subitems' => []];
$items = [
    $mk(1, 'POC - Hjälmar', 'Pågående', ['status' => 'Pågående', 'c3' => '25-6676', 'c4' => 'Idamarie Sjöberg']),
    $mk(2, 'VELO - Flavour World', 'Pågående', ['status' => 'Pågående', 'c1' => 'Brand Union', 'c4' => 'Mattias Oliveira e Silva',
        'c9' => ['text' => '1 okt - 20 jan', 'value' => ['from' => '2026-10-01', 'to' => '2027-01-20']]]),
    $mk(3, 'Absolut vodka - Murakami', 'Pitch', ['status' => 'Offert', 'c8' => ['text' => '20 nov, 2026', 'value' => ['date' => '2026-11-20']]]),
    $mk(4, 'Gammalt', 'Levererat', ['status' => 'Levererat']),
    $mk(5, 'Flyttad', 'Pågående', ['status' => 'Pågående']),
];
$existing = ['projects' => [
    'm4' => ['name' => 'Gammalt', 'phase' => 4, 'color' => 3, 'source' => ['monday' => ['group' => 'Pågående']]],
    'm2' => ['name' => 'x', 'phase' => 3, 'color' => 5, 'waiting' => 'kund', 'source' => ['monday' => ['group' => 'Pågående']]],
    'm5' => ['name' => 'Flyttad', 'phase' => 0, 'source' => ['monday' => ['group' => 'Pitch']]],
], 'people' => ['p1' => ['name' => 'Mattias Oliveira e Silva']]];
$r = map_monday([['board' => $board, 'items' => $items]], $existing)['projects'];
ok($r['m1']['code'] === '25-6676' && $r['m1']['phase'] === 2, 'Pågående → Produktion, projektnr som kod');
ok($r['m3']['phase'] === 0 && $r['m3']['delivery'] === '2026-11-20' && $r['m3']['code'] === 'ABSVODKA', 'Pitch → Uppstart, deadline, autokod');
ok($r['m2']['phase'] === 3 && $r['m2']['owner'] === 'p1' && $r['m2']['delivery'] === '2027-01-20', 'manuell fas behålls, ägare matchad, periodslut som leverans');
ok($r['m4']['archived'] === true, 'Levererat → arkiverad');
ok($r['m5']['phase'] === 2, 'Pitch → Pågående i Monday sätter om fasen');
ok(isset($r['m2']['color']) && is_int($r['m2']['color']), 'färg sätts');
$items2 = [$mk(6, 'Med ark', 'Pågående', ['status' => 'Pågående', 'c2' => ['text' => 'Brief', 'value' => ['url' => 'https://docs.google.com/spreadsheets/d/1Fnx_a-b/edit?usp=sharing', 'text' => 'Tidsplan']]])];
$r2 = map_monday([['board' => $board, 'items' => $items2]], ['projects' => [], 'people' => []])['projects'];
ok(($r2['m6']['sheet'] ?? '') === 'https://docs.google.com/spreadsheets/d/1Fnx_a-b/edit?usp=sharing', 'Sheets-länk plockas ur Monday-kolumn');

echo "Monday → resurser\n";
$users = [['id' => '1', 'name' => 'Mattias Oliveira e Silva', 'title' => 'EP'], ['id' => '2', 'name' => 'Petrus Olsson', 'title' => '']];
$fv = ['board' => ['columns' => [['id' => 'm', 'title' => 'Medarbetare'], ['id' => 'ty', 'title' => 'Typ av Frånvaro'], ['id' => 'p', 'title' => 'Period']]], 'items' => [
    ['board_id' => 1071497633, 'item_id' => 20, 'name' => 'Mattias - Vab', 'group_title' => 'Mattias Oliveira e Silva', 'columns' => ['m' => ['text' => 'Mattias Oliveira e Silva'], 'ty' => ['text' => 'VAB'], 'p' => ['value' => null]],
        'subitems' => [['id' => 201, 'name' => 'gammal', 'columns' => ['Period' => ['value' => ['from' => '2023-11-03', 'to' => '2023-11-04']]]],
                       ['id' => 202, 'name' => 'ny', 'columns' => ['Period' => ['value' => ['from' => date('Y') . '-11-17', 'to' => date('Y') . '-11-17']]]]]],
    ['board_id' => 1071497633, 'item_id' => 22, 'name' => 'x', 'group_title' => 'Ej Godkända', 'columns' => ['m' => ['text' => 'Petrus Olsson'], 'ty' => ['text' => 'Semester'], 'p' => ['value' => ['from' => date('Y') . '-12-22', 'to' => date('Y') . '-12-23']]], 'subitems' => []],
]];
$ex = ['people' => ['u1' => ['name' => 'Mattias Oliveira e Silva', 'role' => 'Executive Producer', 'order' => 3, 'inactive' => true]]];
$r = map_resources($users, null, $fv, $ex);
ok($r['people']['u1']['role'] === 'Executive Producer' && $r['people']['u1']['order'] === 3, 'lokal roll och ordning behålls');
ok($r['people']['u1']['inactive'] === false, 'person som finns i Monday återaktiveras');
ok($r['people']['u2']['role'] === '' && $r['people']['u2']['order'] === 4, 'ny person får nästa ordning');
ok(count($r['absence']) === 1 && array_values($r['absence'])[0]['type'] === 'VAB', 'bara frånvaro från i år, Ej Godkända hoppas över');

echo "Monday → planering (underobjekt → block)\n";
$y = (int)date('Y');
$pi = ['board_id' => 1071363420, 'item_id' => 77, 'name' => 'Marshall Weston', 'group_id' => 'pågående', 'group_title' => 'Pågående', 'columns' => [], 'subitems' => [
    ['id' => 901, 'name' => 'Retusch', 'columns' => ['Ansvarig' => ['text' => 'Thomas Grape'], 'Period' => ['value' => ['from' => "$y-10-12", 'to' => "$y-10-16"]], 'Est. tid' => ['text' => '4']]],
    ['id' => 902, 'name' => '3d', 'columns' => ['Ansvarig' => ['text' => 'Mikael Netti, Thomas Grape'], 'Period' => ['value' => ['from' => "$y-10-16", 'to' => "$y-10-19"]], 'Est. tid' => ['text' => '']]],
    ['id' => 903, 'name' => 'utan person', 'columns' => ['Ansvarig' => ['text' => ''], 'Period' => ['value' => ['from' => "$y-10-12", 'to' => "$y-10-13"]]]],
]];
$ppl = ['u5' => ['name' => 'Thomas Grape'], 'u6' => ['name' => 'Mikael Netti']];
$bl = map_planning([['board' => $board, 'items' => [$pi]]], $ppl, ['m77' => ['name' => 'Marshall Weston']]);
$tg = array_filter($bl, fn($b) => $b['personId'] === 'u5' && $b['note'] === 'Retusch');
ok(count($tg) === 5 && array_values($tg)[0]['hours'] === 4.0 && array_values($tg)[0]['src'] === 'monday', 'Retusch 12–16 okt → 5 arbetsdagar, 4 h/dag, märkt monday');
ok(count(array_filter($bl, fn($b) => $b['note'] === '3d')) === 4, 'helgen hoppas över, två ansvariga → block för båda');
ok(!isset(array_values(array_filter($bl, fn($b) => $b['note'] === '3d'))[0]['hours']), 'tom Est. tid → inga timmar');
ok(count(array_filter($bl, fn($b) => $b['note'] === 'utan person')) === 0, 'underobjekt utan ansvarig hoppas över');
ok(count(map_planning([['board' => $board, 'items' => [$pi]]], $ppl, ['m77' => ['name' => 'x', 'archived' => true]])) === 0, 'arkiverat projekt ger inga block');

echo "\n$n tester, $fails fel\n";
exit($fails ? 1 : 0);

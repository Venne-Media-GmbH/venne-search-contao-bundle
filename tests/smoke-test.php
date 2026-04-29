<?php
/**
 * Smoke-Test: prüft dass das Bundle gegen die echte Meilisearch redet,
 * Indexes erstellt, ein Dokument indexiert und das Suchergebnis korrekt
 * zurückkommt. Setzt voraus dass meilisearch:7700 lokal erreichbar ist
 * (z.B. via SSH-Tunnel zu serverk1).
 *
 * Run:
 *   php tests/smoke-test.php
 *
 * Erwartung:
 *   - Index angelegt
 *   - Dokument upserted
 *   - Such nach "spongebob" findet das Dokument zurück
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Meilisearch\Client;

$endpoint = getenv('MEILI_URL') ?: 'http://127.0.0.1:7700';
$apiKey = getenv('MEILI_KEY') ?: '9dEixw58JXfbm2KsWlMDGr3LPUA16q0g';

echo "Endpoint: {$endpoint}\n";

$client = new Client($endpoint, $apiKey);

// Health
$health = $client->health();
echo "Health: ".json_encode($health)."\n";

// Test-Index anlegen
$indexUid = 'smoke_test_de';
echo "Setup index '{$indexUid}'...\n";
try {
    $client->getIndex($indexUid);
} catch (\Throwable) {
    $client->createIndex($indexUid, ['primaryKey' => 'id']);
    sleep(1);
}

$index = $client->index($indexUid);
$index->updateRankingRules(['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness']);
$index->updateSearchableAttributes(['title', 'tags', 'content']);
// DE-Spezial-Synonyme (ß ↔ ss)
$index->updateSynonyms([
    'maße' => ['masse', 'mass'],
    'masse' => ['maße', 'mass'],
    'fußball' => ['fussball'],
    'straße' => ['strasse'],
]);
sleep(1); // tasks brauchen Zeit zum apply

// Test-Dokumente
$docs = [
    ['id' => 'p1', 'type' => 'page', 'locale' => 'de', 'title' => 'Über Spongebob Schwammkopf', 'content' => 'Spongebob lebt in einer Ananas unter dem Meer in Bikini Bottom.', 'tags' => ['cartoon'], 'url' => '/spongebob', 'indexed_at' => time()],
    ['id' => 'p2', 'type' => 'page', 'locale' => 'de', 'title' => 'Patrick Star', 'content' => 'Patrick ist Spongebobs bester Freund. Ein Seestern aus Bikini Bottom.', 'tags' => ['cartoon'], 'url' => '/patrick', 'indexed_at' => time()],
    ['id' => 'p3', 'type' => 'file', 'locale' => 'de', 'title' => 'Café-Menü', 'content' => 'Heißgetränke, Café au lait, Espresso, Latte Macchiato. Frische Maße.', 'tags' => ['pdf'], 'url' => '/files/cafe.pdf', 'indexed_at' => time()],
];

echo "Adding ".count($docs)." docs...\n";
$task = $index->addDocuments($docs);
$client->waitForTask($task['taskUid']);
echo "  taskUid={$task['taskUid']} status=done\n";

// Suchtests
$tests = [
    ['q' => 'spongebob', 'expectMin' => 2, 'desc' => 'exact match'],
    ['q' => 'Spongbob', 'expectMin' => 1, 'desc' => 'typo tolerance (single typo)'],
    ['q' => 'cafe', 'expectMin' => 1, 'desc' => 'diacritic folding (cafe finds café)'],
    ['q' => 'masse', 'expectMin' => 1, 'desc' => 'umlaut folding (masse finds Maße)'],
    ['q' => 'bikini bottom', 'expectMin' => 2, 'desc' => 'multi-word'],
];

$pass = 0;
$fail = 0;
foreach ($tests as $t) {
    $result = $index->search($t['q'], ['limit' => 5, 'attributesToHighlight' => ['title', 'content']]);
    $arr = $result->toArray();
    $hits = $arr['hits'] ?? [];
    $count = count($hits);
    $time = $arr['processingTimeMs'] ?? -1;

    $ok = $count >= $t['expectMin'];
    $marker = $ok ? '✓' : '✗';
    echo sprintf("  %s '%s' (%s) → %d hits in %d ms (expect ≥%d)\n", $marker, $t['q'], $t['desc'], $count, $time, $t['expectMin']);
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        foreach ($hits as $h) {
            echo "      - {$h['id']}: {$h['title']}\n";
        }
    }
}

// Cleanup
echo "Cleanup...\n";
$client->deleteIndex($indexUid);

echo "\n===== {$pass} passed, {$fail} failed =====\n";
exit($fail === 0 ? 0 : 1);

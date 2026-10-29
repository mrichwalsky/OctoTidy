<?php
// Run: php tests/scan-check.php  (no WordPress needed)

define('ABSPATH', __DIR__);
function add_action(...$args): void {}
require __DIR__ . '/../octotidy.php';

function call(string $method, ...$args) {
    $m = new ReflectionMethod('OctoTidy_Media_Attachment_Verifier', $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

$files = [
    'foo.jpg' => [['2024/01/foo.jpg', 10]],
    'foo-150x150.jpg' => [['2024/01/foo-150x150.jpg', 10]],
    'doc.pdf' => [['2023/05/doc.pdf', 11]],
];
$pattern = call('file_pattern', ['jpg', 'pdf']);
$ids = static function (string $value, string $label = 'Post #1 (page): About content') use ($files, $pattern): array {
    $found = call('referenced_ids', $value, $label, $files, $pattern);
    sort($found);
    return $found;
};

assert($ids('{"url":"https://x.com/wp-content/uploads/2024/01/foo-150x150.jpg"}') === [10]);
assert($ids('<img src="/wp-content/uploads/2024/01/foo.jpg.webp">') === [10]);
assert($ids('<a href="https://x.com/wp-content/uploads/2023/05/doc.pdf?v=2">') === [11]);
assert($ids('/wp-content/uploads/2099/01/foo.jpg') === [], 'same basename, different path');
assert($ids('<img class="wp-image-12">') === [12]);
assert($ids('[gallery ids="3,4"]') === [3, 4]);
assert($ids('{"image_id":5}') === [5]);
assert($ids('a:1:{s:8:"image_id";i:6;}') === [6]);
assert($ids('7', 'Post meta #1: _thumbnail_id') === [7]);
assert($ids('8', 'Post meta #1: _price') === []);

$start = microtime(true);
$ids(str_repeat('QUJD', 1250000) . ' /uploads/2024/01/foo.jpg');
assert(microtime(true) - $start < 2, '5MB blob must scan in linear time');

echo "ok\n";

<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Tests\Unit\Data\DtoFixtures;
use Dom\HTMLDocument;
use Saloon\Http\Faking\MockClient;

require_once __DIR__.'/DtoFixtures.php';

afterEach(function () {
    MockClient::destroyGlobal();
});

it('sanitizes unsafe elements attributes and link schemes from html', function () {
    $body = '<p onclick="alert(1)" style="color:red">Hello</p>'
        .'<script>alert(1)</script><svg onload="alert(1)"><circle /></svg>'
        .'<a href="javascript:alert(1)">unsafe</a><a href="tel:123">phone</a>'
        .'<form><input name="secret"></form><style>p { color:red }</style><iframe src="https://example.test"></iframe>';

    $comment = Comment::fromJson(DtoFixtures::comment(['body' => $body]));

    expect($comment->body)->toBe($body)
        ->and($comment->body_filtered)->toContain('<p>Hello</p>')
        ->not->toContain('<script', '<svg', 'onload', 'onclick', 'javascript:', 'tel:', '<form', '<input', '<style', 'style=', '<iframe', 'alert(1)');
});

it('keeps only http https and mailto links', function () {
    $body = '<a href="http://example.test">http</a><a href="https://example.test">https</a>'
        .'<a href="mailto:customer@example.test">mail</a><a href="/relative">relative</a>';

    $filtered = Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered;

    expect(html_entity_decode($filtered, ENT_QUOTES | ENT_HTML5, 'UTF-8'))->toContain('href="http://example.test"', 'href="https://example.test"', 'href="mailto:customer@example.test"')
        ->not->toContain('href="/relative"');
});

it('escapes non html bodies', function (string $contentType) {
    $body = '<script>alert("x")</script> & text';

    $comment = Comment::fromJson(DtoFixtures::comment(['body' => $body, 'content_type' => $contentType]));

    expect($comment->body_filtered)->toBe('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; text');
})->with(['text/plain', 'application/octet-stream']);

it('escapes the original plain text even when it contains a signature marker', function () {
    $body = '<span class="js-signatureMarker"></span>Tail';

    $comment = Comment::fromJson(DtoFixtures::comment(['body' => $body, 'content_type' => 'text/plain']));

    expect($comment->body_filtered)->toBe('&lt;span class=&quot;js-signatureMarker&quot;&gt;&lt;/span&gt;Tail');
});

it('preserves the entire body without adding a closing div when no marker exists', function () {
    $body = '<p>Message</p><p>Tail</p>';

    expect(Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered)->toBe($body);
});

it('preserves long html bodies when no signature marker exists', function () {
    $body = '<p>'.str_repeat('Message ', 3000).'</p><p>Tail</p>';

    expect(Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered)->toBe($body);
});

it('cuts a present signature marker before repairing html', function (string $markerKey) {
    $body = '<div><p>Message</p>'.config('zammad.'.$markerKey).'<p>Signature tail</p></div>';

    expect(Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered)->toBe('<div><p>Message</p></div>');
})->with(['filter_signature_marker_value', 'filter_data_signature_value']);

it('keeps signature content when its filter is disabled', function (string $filter, string $markerKey) {
    config(['zammad.'.$filter => false]);
    $body = '<p>Message</p>'.config('zammad.'.$markerKey).'<p>Signature tail</p>';

    expect(Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered)->toContain('<p>Signature tail</p>');
})->with([
    ['filter_signature_marker', 'filter_signature_marker_value'],
    ['filter_data_signature', 'filter_data_signature_value'],
]);

it('uses configured signature marker values', function () {
    config(['zammad.filter_signature_marker_value' => '<!-- signature -->']);
    $body = '<p>Message</p><!-- signature --><p>Signature tail</p>';

    expect(Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered)->toBe('<p>Message</p>');
});

it('unwraps table markup and preserves the message when enabled', function () {
    $body = '<table><caption>Account access</caption><thead><tr><th>Request</th></tr></thead>'
        .'<tbody><tr><td><p>Please restore access.</p></td></tr></tbody>'
        .'<tfoot><tr><td>Thank you</td></tr></tfoot></table>';

    $comment = Comment::fromJson(DtoFixtures::comment(['body' => $body]));

    expect($comment->body)->toBe($body)
        ->and($comment->body_filtered)->toBe('Account accessRequest<p>Please restore access.</p>Thank you');
});

it('keeps tables when filtering is disabled', function () {
    config(['zammad.filter_tables' => false]);
    $body = '<table><tr><td>Visible table content</td></tr></table>';

    expect(Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered)->toContain('<table>', 'Visible table content', '</table>');
});

it('drops images when enabled', function () {
    $body = '<p>Before<img src="https://example.test/image.png" alt="Hidden"></p>';

    expect(Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered)->toBe('<p>Before</p>');
});

it('keeps safe images when filtering is disabled', function () {
    config(['zammad.filter_images' => false]);
    $body = '<img src="https://example.test/image.png" onerror="alert(1)">';

    expect(Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered)->toContain('<img', 'src="https://example.test/image.png"')
        ->not->toContain('onerror');
});

it('removes hostile urls from the :dataset attribute', function (string $attribute) {
    foreach ([true, false] as $filterImages) {
        config(['zammad.filter_images' => $filterImages]);

        foreach (['javascript:alert(1)', 'data:image/png;base64,AAAA', 'vbscript:msgbox(1)', 'JaVaScRiPt:alert(1)', 'jav&#x61;script:alert(1)'] as $url) {
            $body = '<p '.$attribute.'="'.$url.'">Message</p><img '.$attribute.'="'.$url.'"><source '.$attribute.'="'.$url.'">';

            $filtered = Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered;

            expect($filtered)->toContain('<p>Message</p>')
                ->not->toContain($attribute.'=', 'javascript:', 'data:', 'vbscript:');
        }
    }
})->with([
    'srcset' => ['srcset'], 'imagesrcset' => ['imagesrcset'], 'src' => ['src'], 'href' => ['href'],
    'poster' => ['poster'], 'background' => ['background'], 'cite' => ['cite'], 'longdesc' => ['longdesc'],
    'xlink:href' => ['xlink:href'], 'formaction' => ['formaction'], 'action' => ['action'], 'ping' => ['ping'],
    'data' => ['data'], 'codebase' => ['codebase'], 'archive' => ['archive'], 'lowsrc' => ['lowsrc'],
    'profile' => ['profile'], 'manifest' => ['manifest'], 'usemap' => ['usemap'],
    'conversiondestination' => ['conversiondestination'], 'reportingorigin' => ['reportingorigin'],
    'resources' => ['resources'], 'code' => ['code'], 'object' => ['object'],
]);

it('bounds the filtered body without changing the original', function (string $contentType) {
    $body = str_repeat('x', 240000);

    $comment = Comment::fromJson(DtoFixtures::comment(['body' => $body, 'content_type' => $contentType]));

    expect(strlen($comment->body_filtered))->toBe(200000)
        ->and($comment->body)->toBe($body);
})->with(['text/html', 'text/plain']);

it('hydrates a 1.2 MB html body under the default memory limit', function () {
    $body = str_repeat('<p>x</p>', 150000);

    $comment = Comment::fromJson(DtoFixtures::comment(['body' => $body]));

    expect(strlen($comment->body_filtered))->toBeLessThanOrEqual(200000)
        ->and($comment->body)->toBe($body);
});

it('sanitizes html media type variants', function (string $contentType) {
    $body = '<p onclick="alert(1)">Message</p><script>alert(1)</script>'
        .config('zammad.filter_signature_marker_value').'<p>Signature</p>';

    $comment = Comment::fromJson(DtoFixtures::comment(['body' => $body, 'content_type' => $contentType]));

    expect($comment->body_filtered)->toBe('<p>Message</p>')
        ->and($comment->body)->toBe($body);
})->with(['text/html; charset=utf-8', 'Text/HTML', 'application/xhtml+xml', 'APPLICATION/XHTML+XML; charset=UTF-8']);

it('escapes deeply nested html in a memory limited subprocess', function (string $element, int $depth, string $contentType) {
    $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
require $argv[1].'/tests/Unit/Data/DtoFixtures.php';

$app = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($app);
$app->instance('config', new Illuminate\Config\Repository([
    'zammad' => require $argv[1].'/config/zammad.php',
]));
Saloon\Config::preventStrayRequests();

$body = str_repeat('<'.$argv[2].'>', (int) $argv[3]).'Message'.str_repeat('</'.$argv[2].'>', (int) $argv[3]);
$comment = AlexHackney\Zammad\Data\Comment::fromJson(AlexHackney\Zammad\Tests\Unit\Data\DtoFixtures::comment([
    'body' => $body,
    'content_type' => $argv[4],
]));
echo json_encode([
    'body_unchanged' => $comment->body === $body,
    'escaped' => $comment->body_filtered === htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'memory_limit' => ini_get('memory_limit'),
]);
PHP;

    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $script, dirname(__DIR__, 3), $element, (string) $depth, $contentType], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    expect(is_resource($process))->toBeTrue();

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, $errors)
        ->and($errors)->toBe('')
        ->and(json_decode($output, true))->toBe([
            'body_unchanged' => true,
            'escaped' => true,
            'memory_limit' => '64M',
        ]);
})->with([
    ['div', 17000, 'text/html'],
    ['b', 20000, 'application/xhtml+xml'],
]);

it('sanitizes tables nested thirty deep', function (bool $filterTables) {
    config(['zammad.filter_tables' => $filterTables]);
    $body = str_repeat('<table><tr><td>', 30).'<p onclick="alert(1)">Message</p>'.str_repeat('</td></tr></table>', 30);

    $filtered = Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered;

    expect($filtered)->toContain('<p>Message</p>')->not->toContain('onclick', '&lt;');

    if ($filterTables) {
        expect($filtered)->toBe('<p>Message</p>');
    } else {
        expect(substr_count($filtered, '<table>'))->toBe(30);
    }
})->with([true, false]);

it('escapes html only when nesting exceeds one hundred', function (int $depth) {
    $body = str_repeat('<div>', $depth).'Message'.str_repeat('</div>', $depth);

    $filtered = Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered;

    expect($filtered)->toBe($depth > 100 ? htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $body);
})->with([100, 101]);

it('ignores void and self closing tags when measuring nesting', function () {
    $body = str_repeat('<br><hr><wbr><img src="https://example.test/image.png" /><span />', 120).'<p onclick="alert(1)">Message</p>';

    $filtered = Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered;

    expect($filtered)->toContain('<p>Message</p>')->not->toContain('onclick', '&lt;');
});

it('drops raw text rcdata and legacy elements without swallowing the host page', function (string $element, bool $filterTables) {
    config(['zammad.filter_tables' => $filterTables]);
    $body = '<table><tr><td><'.$element.'>x</'.$element.'></td></tr></table>';

    $filtered = Comment::fromJson(DtoFixtures::comment(['body' => $body]))->body_filtered;

    expect(preg_match('~</?\s*'.$element.'(?=[\s/>])~i', $filtered))->toBe(0);

    if (class_exists(HTMLDocument::class)) {
        $document = HTMLDocument::createFromString('<!doctype html><html><head><title>Host</title></head><body><div>'.$filtered.'</div><p id="sentinel">Still here</p></body></html>');

        expect($document->getElementById('sentinel')?->textContent)->toBe('Still here');
    }
})->with([
    'plaintext', 'xmp', 'listing', 'noembed', 'noframes', 'noscript', 'textarea', 'title',
    'style', 'script', 'iframe', 'object', 'embed', 'template', 'select', 'option',
])->with([true, false]);

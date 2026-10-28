<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runMigrateScript(array $arguments, array $env = [], ?string $cwd = null, ?string $prelude = null): array
{
    $scriptPath = dirname(__DIR__, 3).'/bin/migrate-from-codebar';
    $command = $prelude === null ? [$scriptPath] : [PHP_BINARY, '-r', $prelude.' array_shift($argv); require $argv[0];', $scriptPath];
    $process = new Process([...$command, ...$arguments], $cwd, array_merge(getenv(), $env));
    $process->setTimeout(10);
    $exitCode = $process->run();

    return [
        'exitCode' => $exitCode,
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
}

function removeTempDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    /** @var SplFileInfo $item */
    foreach ($items as $item) {
        if ($item->isDir() && ! $item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

function createMigrationFixtureApp(): string
{
    $baseDir = realpath(sys_get_temp_dir())
        .DIRECTORY_SEPARATOR
        .'zammad-migration-test-'
        .bin2hex(random_bytes(8));

    mkdir($baseDir.'/app/Services', 0777, true);
    mkdir($baseDir.'/config', 0777, true);
    mkdir($baseDir.'/resources/views/tickets', 0777, true);
    mkdir($baseDir.'/vendor/codebar-ag/laravel-zammad/src/DTO', 0777, true);

    $composerJson = [
        'name' => 'acme/helpdesk-app',
        'type' => 'project',
        'repositories' => [
            [
                'type' => 'vcs',
                'url' => 'https://github.com/alexhackney/laravel-zammad',
            ],
        ],
        'require' => [
            'php' => '^8.3',
            'codebar-ag/laravel-zammad' => 'dev-main as 12.9.1',
        ],
    ];

    file_put_contents(
        $baseDir.'/composer.json',
        json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    $appClass = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use CodebarAg\Zammad\DTO\Attachment;
use CodebarAg\Zammad\DTO\Comment;
use CodebarAg\Zammad\DTO\ObjectAttribute;
use CodebarAg\Zammad\DTO\Ticket;
use CodebarAg\Zammad\DTO\User;
use CodebarAg\Zammad\Events\ZammadResponseLog;
use CodebarAg\Zammad\Facades\Zammad;

class SupportService
{
    public function sync(string $externalId): ?Ticket
    {
        $me = Zammad::user()->show(0);
        $user = Zammad::user()->searchOrCreateByEmail('customer@example.com', [
            'firstname' => 'Ada',
        ]);
        Zammad::user()->update($externalId, ['lastname' => 'Lovelace']);
        Zammad::userAccessToken()->delete('token-abc');
        $comments = Zammad::comment()->showByTicket(10);

        return Zammad::ticket()->showWithComments(10);
    }

    /**
     * @return list<class-string>
     */
    public function dtoTypes(): array
    {
        return [
            Attachment::class,
            Comment::class,
            ObjectAttribute::class,
            Ticket::class,
            User::class,
            ZammadResponseLog::class,
        ];
    }
}
PHP;

    file_put_contents($baseDir.'/app/Services/SupportService.php', $appClass."\n");

    $configFile = <<<'PHP'
<?php

return [
    'url' => env('ZAMMAD_URL'),
    'token' => env('ZAMMAD_TOKEN'),
    'object_reference_error_ignore' => env('ZAMMAD_OBJECT_REFERENCE_ERROR_IGNORE', false),
    'objet_reference_error' => env('ZAMMAD_OBJECT_REFERENCE_ERROR', 'Can&#39;t delete, object has references.'),
];
PHP;

    file_put_contents($baseDir.'/config/zammad.php', $configFile."\n");

    $bladeFile = <<<'BLADE'
@php
    /** @var \CodebarAg\Zammad\DTO\Comment $comment */
@endphp
<article class="comment">
    {!! $comment->body_filtered !!}
</article>
BLADE;

    file_put_contents($baseDir.'/resources/views/tickets/show.blade.php', $bladeFile."\n");

    $vendorFile = <<<'PHP'
<?php

namespace CodebarAg\Zammad\DTO;

class Ticket
{
    public string $configKey = 'objet_reference_error';
}
PHP;

    file_put_contents($baseDir.'/vendor/codebar-ag/laravel-zammad/src/DTO/Ticket.php', $vendorFile."\n");

    return $baseDir;
}

beforeEach(function () {
    $this->fixtureDir = createMigrationFixtureApp();
});

afterEach(function () {
    if (isset($this->fixtureDir) && is_string($this->fixtureDir)) {
        removeTempDirectory($this->fixtureDir);
    }
});

it('refuses to run when APP_DIR has no composer.json', function () {
    $emptyDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        .DIRECTORY_SEPARATOR
        .'zammad-empty-'
        .bin2hex(random_bytes(8));
    mkdir($emptyDir, 0777, true);

    try {
        $result = runMigrateScript(['--skip-composer', $emptyDir]);

        expect($result['exitCode'])->not->toBe(0)
            ->and($result['stderr'])->toContain('composer.json');
    } finally {
        removeTempDirectory($emptyDir);
    }
});

it('rewrites namespaces, DTO imports, config keys, and blade views while leaving vendor untouched', function () {
    $vendorPath = $this->fixtureDir.'/vendor/codebar-ag/laravel-zammad/src/DTO/Ticket.php';
    $vendorBefore = (string) file_get_contents($vendorPath);

    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0);

    $serviceContent = (string) file_get_contents($this->fixtureDir.'/app/Services/SupportService.php');
    expect($serviceContent)
        ->toContain('use AlexHackney\Zammad\Data\Attachment;')
        ->toContain('use AlexHackney\Zammad\Data\Comment;')
        ->toContain('use AlexHackney\Zammad\Data\ObjectAttribute;')
        ->toContain('use AlexHackney\Zammad\Data\Ticket;')
        ->toContain('use AlexHackney\Zammad\Data\User;')
        ->toContain('use AlexHackney\Zammad\Events\ZammadResponseLog;')
        ->toContain('use AlexHackney\Zammad\Facades\Zammad;')
        ->not->toContain('CodebarAg\Zammad')
        ->not->toContain('Zammad\DTO');

    $configContent = (string) file_get_contents($this->fixtureDir.'/config/zammad.php');
    expect($configContent)
        ->toContain("'object_reference_error' => env('ZAMMAD_OBJECT_REFERENCE_ERROR'")
        ->not->toContain('objet_reference_error');

    $bladeContent = (string) file_get_contents($this->fixtureDir.'/resources/views/tickets/show.blade.php');
    expect($bladeContent)
        ->toContain('\AlexHackney\Zammad\Data\Comment')
        ->not->toContain('CodebarAg\Zammad')
        ->not->toContain('\DTO\\');

    expect((string) file_get_contents($vendorPath))->toBe($vendorBefore);

    expect($result['stdout'])
        ->toContain('Files changed:')
        ->toContain('app/Services/SupportService.php')
        ->toContain('config/zammad.php')
        ->toContain('resources/views/tickets/show.blade.php')
        ->not->toContain('vendor/codebar-ag')
        ->toContain('Review manually:')
        ->toContain('show(0)')
        ->toContain('searchOrCreateByEmail')
        ->toContain('showByTicket')
        ->toContain('showWithComments')
        ->toContain('body_filtered')
        ->toContain('ZammadResponseLog')
        ->toContain('ZAMMAD_OBJECT_REFERENCE_ERROR')
        ->toContain('update($externalId')
        ->toContain("delete('token-abc')");
});

it('changes nothing and lists what would change when run with --dry-run', function () {
    $files = [
        $this->fixtureDir.'/composer.json',
        $this->fixtureDir.'/app/Services/SupportService.php',
        $this->fixtureDir.'/config/zammad.php',
        $this->fixtureDir.'/resources/views/tickets/show.blade.php',
        $this->fixtureDir.'/vendor/codebar-ag/laravel-zammad/src/DTO/Ticket.php',
    ];

    $before = [];
    foreach ($files as $file) {
        $before[$file] = (string) file_get_contents($file);
    }

    $result = runMigrateScript(['--dry-run', '--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])
        ->toContain('Dry run: changed nothing.')
        ->toContain('Files that would change:')
        ->toContain('app/Services/SupportService.php')
        ->toContain('config/zammad.php')
        ->toContain('resources/views/tickets/show.blade.php')
        ->not->toContain('vendor/');

    foreach ($files as $file) {
        expect((string) file_get_contents($file))->toBe($before[$file]);
    }
});

it('is idempotent when run a second time', function () {
    $first = runMigrateScript(['--skip-composer', $this->fixtureDir]);
    expect($first['exitCode'])->toBe(0);

    $serviceAfterFirst = (string) file_get_contents($this->fixtureDir.'/app/Services/SupportService.php');
    $configAfterFirst = (string) file_get_contents($this->fixtureDir.'/config/zammad.php');
    $bladeAfterFirst = (string) file_get_contents($this->fixtureDir.'/resources/views/tickets/show.blade.php');

    $second = runMigrateScript(['--skip-composer', $this->fixtureDir]);

    expect($second['exitCode'])->toBe(0)
        ->and($second['stdout'])->toContain('Files changed: none')
        ->and((string) file_get_contents($this->fixtureDir.'/app/Services/SupportService.php'))->toBe($serviceAfterFirst)
        ->and((string) file_get_contents($this->fixtureDir.'/config/zammad.php'))->toBe($configAfterFirst)
        ->and((string) file_get_contents($this->fixtureDir.'/resources/views/tickets/show.blade.php'))->toBe($bladeAfterFirst);
});

it('removes the laravel-zammad VCS repository entry and calls fake composer remove and require', function () {
    $fakeBinDir = $this->fixtureDir.'/fake-bin';
    mkdir($fakeBinDir, 0777, true);

    $composerLogFile = $this->fixtureDir.'/composer-calls.log';
    $fakeComposerPath = $fakeBinDir.'/composer';

    $fakeComposerScript = <<<BASH
#!/usr/bin/env bash
echo "\$*" >> "{$composerLogFile}"
exit 0
BASH;

    file_put_contents($fakeComposerPath, $fakeComposerScript."\n");
    chmod($fakeComposerPath, 0755);

    $currentPath = (string) getenv('PATH');
    $env = [
        'PATH' => $fakeBinDir.PATH_SEPARATOR.$currentPath,
    ];

    $result = runMigrateScript([$this->fixtureDir], $env);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->toContain('composer.json')
        ->and(is_file($composerLogFile))->toBeTrue();

    $calls = array_values(array_filter(
        array_map('trim', explode("\n", (string) file_get_contents($composerLogFile))),
        static fn (string $line): bool => $line !== '',
    ));

    expect($calls)->toBe([
        'remove codebar-ag/laravel-zammad --no-interaction --no-scripts --working-dir='.$this->fixtureDir,
        'require alexhackney/laravel-zammad --no-interaction --working-dir='.$this->fixtureDir,
    ]);

    $decodedComposer = json_decode(
        (string) file_get_contents($this->fixtureDir.'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($decodedComposer)->toBeArray()
        ->not->toHaveKey('repositories');

    // Running again should not touch composer.json a second time
    $second = runMigrateScript([$this->fixtureDir], $env);
    expect($second['exitCode'])->toBe(0)
        ->and($second['stdout'])->toContain('Files changed: none');
});

it('defaults APP_DIR to current directory, preserves other repositories, and ignores composer remove failure', function () {
    $composerJson = [
        'name' => 'acme/helpdesk-app',
        'repositories' => [
            [
                'type' => 'vcs',
                'url' => 'https://github.com/acme/private-package',
            ],
            [
                'type' => 'vcs',
                'url' => 'git@github.com:alexhackney/laravel-zammad.git',
            ],
        ],
        'require' => [
            'php' => '^8.3',
        ],
    ];

    file_put_contents(
        $this->fixtureDir.'/composer.json',
        json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    $fakeBinDir = $this->fixtureDir.'/fake-bin';
    mkdir($fakeBinDir, 0777, true);

    $composerLogFile = $this->fixtureDir.'/composer-calls.log';
    $fakeComposerPath = $fakeBinDir.'/composer';

    $fakeComposerScript = <<<BASH
#!/usr/bin/env bash
echo "\$*" >> "{$composerLogFile}"
if [[ "\$1" == "remove" ]]; then
    exit 1
fi
exit 0
BASH;

    file_put_contents($fakeComposerPath, $fakeComposerScript."\n");
    chmod($fakeComposerPath, 0755);

    $currentPath = (string) getenv('PATH');
    $env = [
        'PATH' => $fakeBinDir.PATH_SEPARATOR.$currentPath,
    ];

    $result = runMigrateScript([], $env, $this->fixtureDir);

    expect($result['exitCode'])->toBe(0);

    $decodedComposer = json_decode(
        (string) file_get_contents($this->fixtureDir.'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($decodedComposer['repositories'])->toBe([
        [
            'type' => 'vcs',
            'url' => 'https://github.com/acme/private-package',
        ],
    ]);
});

it('only reports Zammad chains in the review list, not Eloquent updates elsewhere in the app', function () {
    file_put_contents($this->fixtureDir.'/app/Services/Billing.php', <<<'PHP'
<?php

namespace App\Services;

class Billing
{
    public function run($show, array $data): void
    {
        $show->update($data);
        $show->show()->first();
        $show->delete($data);
    }
}
PHP);
    file_put_contents($this->fixtureDir.'/app/Services/Support.php', <<<'PHP'
<?php

namespace App\Services;

use Zammad;

class Support
{
    public function run(int $id): void
    {
        Zammad::user()->update($id, ['phone' => '1']);
    }
}
PHP);

    $result = runMigrateScript(['--dry-run', '--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])
        ->toContain('app/Services/Support.php')
        ->not->toContain('app/Services/Billing.php');
});

it('uses the exact APP_DIR including a trailing newline and never edits its sibling', function () {
    $newlineDir = $this->fixtureDir."\n";
    mkdir($newlineDir);
    $source = $this->fixtureDir.'/app/Services/SupportService.php';
    $before = file_get_contents($source);

    try {
        $refused = runMigrateScript(['--skip-composer', $newlineDir]);
        expect($refused['exitCode'])->not->toBe(0)
            ->and(file_get_contents($source))->toBe($before);

        file_put_contents($newlineDir.'/composer.json', '{"require":{}}');
        mkdir($newlineDir.'/app');
        file_put_contents($newlineDir.'/app/Test.php', '<?php use CodebarAg\\Zammad\\DTO\\Ticket;');
        $result = runMigrateScript(['--skip-composer', $newlineDir]);
        expect($result['exitCode'])->toBe(0)
            ->and(file_get_contents($newlineDir.'/app/Test.php'))->toContain('AlexHackney\\Zammad\\Data\\Ticket')
            ->and(file_get_contents($source))->toBe($before);
    } finally {
        removeTempDirectory($newlineDir);
    }
});

it('refuses linked manifests and skips linked source files and directories', function () {
    $external = $this->fixtureDir.'/outside';
    mkdir($external);
    $original = '<?php use CodebarAg\\Zammad\\DTO\\Ticket;';
    file_put_contents($external.'/Keep.php', $original);
    link($external.'/Keep.php', $this->fixtureDir.'/app/Hard.php');
    symlink($external.'/Keep.php', $this->fixtureDir.'/app/Link.php');
    symlink($external, $this->fixtureDir.'/app/Linked');

    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
    expect($result['exitCode'])->toBe(0)
        ->and(file_get_contents($external.'/Keep.php'))->toBe($original);

    rename($this->fixtureDir.'/composer.json', $external.'/composer.json');
    $manifest = file_get_contents($external.'/composer.json');
    symlink($external.'/composer.json', $this->fixtureDir.'/composer.json');
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
    expect($result['exitCode'])->not->toBe(0)
        ->and($result['stderr'])->toContain('composer.json')
        ->and(file_get_contents($external.'/composer.json'))->toBe($manifest);
});

it('limits rewrites to text PHP sources and the exact namespace roots and published array key', function () {
    $source = <<<'PHP_SOURCE'
<?php
use App\DTO\User;
use OtherVendor\DTO\Ticket;
use Other\CodebarAg\Zammad\DTO\User;
use CodebarAg\ZammadOther\DTO\User;
use CodebarAg\Zammad\DTO;
$dto = CodebarAg\Zammad\DTO\Ticket::class;
$string = 'CodebarAg\\Zammad\\DTO\\User';
$root = 'CodebarAg\\Zammad\\Facades\\Zammad';
$key = 'objet_reference_error';
PHP_SOURCE;
    file_put_contents($this->fixtureDir.'/app/Scope.php', $source);
    $serialized = 'a:1:{s:21:"objet_reference_error";b:1;}';
    file_put_contents($this->fixtureDir.'/config/serialized.bin', $serialized);
    file_put_contents($this->fixtureDir.'/config/binary.php', "\0CodebarAg\\Zammad\\DTO\\Ticket");
    file_put_contents($this->fixtureDir.'/config/other.php', "<?php return ['objet_reference_error' => 'leave'];");
    file_put_contents($this->fixtureDir.'/config/zammad.php', <<<'PHP_SOURCE'
<?php
// 'objet_reference_error' => 'comment'
return ['objet_reference_error' /* key */ => 'objet_reference_error', 'text' => "'objet_reference_error' =>"];
PHP_SOURCE);

    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
    expect($result['exitCode'])->toBe(0)
        ->and(file_get_contents($this->fixtureDir.'/app/Scope.php'))
        ->toContain('App\\DTO\\User', 'OtherVendor\\DTO\\Ticket', 'Other\\CodebarAg\\Zammad\\DTO\\User', 'CodebarAg\\ZammadOther\\DTO\\User')
        ->toContain('use AlexHackney\\Zammad\\Data;', 'AlexHackney\\Zammad\\Data\\Ticket::class', 'AlexHackney\\\\Zammad\\\\Data\\\\User', 'AlexHackney\\\\Zammad\\\\Facades\\\\Zammad')
        ->toContain("'objet_reference_error'")
        ->and(file_get_contents($this->fixtureDir.'/config/serialized.bin'))->toBe($serialized)
        ->and(file_get_contents($this->fixtureDir.'/config/binary.php'))->toBe("\0CodebarAg\\Zammad\\DTO\\Ticket")
        ->and(file_get_contents($this->fixtureDir.'/config/other.php'))->toContain("'objet_reference_error' =>")
        ->and(file_get_contents($this->fixtureDir.'/config/zammad.php'))
        ->toContain("'object_reference_error' /* key */ => 'objet_reference_error'", "// 'objet_reference_error' => 'comment'", '"\'objet_reference_error\' =>"');
});

it('preserves JSON objects and unrelated repositories while rewriting Composer registrations even when skipping commands', function () {
    $manifest = <<<'JSON'
{
    "require": {},
    "config": {},
    "extra": {
        "empty": {},
        "laravel": {
            "providers": ["CodebarAg\\Zammad\\ZammadServiceProvider"],
            "aliases": {"Zammad": "CodebarAg\\Zammad\\Facades\\Zammad"}
        }
    },
    "autoload": {"psr-4": {"CodebarAg\\Zammad\\DTO\\": ["CodebarAg\\Zammad\\DTO\\", "src/"]}},
    "autoload-dev": {"psr-4": {"CodebarAg\\Zammad\\Tests\\": "tests/"}},
    "repositories": [
        {"type": "path", "url": "/apps/laravel-zammad-app/packages/unrelated"},
        {"type": "composer", "url": "https://example.test/laravel-zammad"},
        {"type": "artifact", "url": "laravel-zammad"},
        {"type": "vcs", "url": "https://example.test/laravel-zammad-extra"},
        {"type": "vcs", "url": "https://example.test/Hackney-Enterprises-Inc/laravel-zammad"},
        {"type": "git", "url": "git@example.test:someone/laravel-zammad.git"}
    ]
}
JSON;
    file_put_contents($this->fixtureDir.'/composer.json', $manifest);
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
    $decoded = json_decode((string) file_get_contents($this->fixtureDir.'/composer.json'), false, 512, JSON_THROW_ON_ERROR);

    expect($result['exitCode'])->toBe(0)
        ->and($decoded->require)->toBeInstanceOf(stdClass::class)
        ->and($decoded->config)->toBeInstanceOf(stdClass::class)
        ->and($decoded->extra->empty)->toBeInstanceOf(stdClass::class)
        ->and($decoded->extra->laravel->providers)->toBe(['AlexHackney\\Zammad\\ZammadServiceProvider'])
        ->and($decoded->extra->laravel->aliases->Zammad)->toBe('AlexHackney\\Zammad\\Facades\\Zammad')
        ->and($decoded->autoload->{'psr-4'}->{'AlexHackney\\Zammad\\Data\\'})->toBe(['AlexHackney\\Zammad\\Data\\', 'src/'])
        ->and($decoded->{'autoload-dev'}->{'psr-4'}->{'AlexHackney\\Zammad\\Tests\\'})->toBe('tests/')
        ->and(array_column($decoded->repositories, 'type'))->toBe(['path', 'composer', 'artifact', 'vcs']);
});

/** @return array<string, string> */
function installMigrationComposerProbe(string $app, string $body): array
{
    mkdir($app.'/fake-bin');
    file_put_contents($app.'/fake-bin/composer', "#!/usr/bin/env php\n<?php\ndeclare(strict_types=1);\n".$body);
    chmod($app.'/fake-bin/composer', 0755);

    return ['PATH' => $app.'/fake-bin'.PATH_SEPARATOR.getenv('PATH')];
}

it('rewrites all files before Composer runs and makes the second full run a no-op', function () {
    mkdir($this->fixtureDir.'/bootstrap');
    file_put_contents($this->fixtureDir.'/bootstrap/providers.php', '<?php return [CodebarAg\\Zammad\\ZammadServiceProvider::class];');
    $env = installMigrationComposerProbe($this->fixtureDir, <<<'PHP_SOURCE'
foreach (['app/Services/SupportService.php', 'bootstrap/providers.php', 'config/zammad.php', 'composer.json'] as $file) {
    $text = file_get_contents($file);
    if (str_contains($text, 'CodebarAg') || str_contains($text, 'objet_reference_error') || str_contains($text, '"repositories"')) {
        fwrite(STDERR, 'Files not rewritten before Composer: '.$file);
        exit(19);
    }
}
file_put_contents('composer-calls.log', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
$manifest = json_decode(file_get_contents('composer.json'));
if ($argv[1] === 'remove') {
    unset($manifest->require->{'codebar-ag/laravel-zammad'});
} else {
    $manifest->require->{'alexhackney/laravel-zammad'} = '^1.0';
}
file_put_contents('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
PHP_SOURCE);
    $first = runMigrateScript([$this->fixtureDir], $env);
    expect($first['exitCode'])->toBe(0)
        ->and($first['stdout'])->toContain('composer install', 'dump-autoload');
    $calls = file_get_contents($this->fixtureDir.'/composer-calls.log');
    $manifest = file_get_contents($this->fixtureDir.'/composer.json');
    $second = runMigrateScript([$this->fixtureDir], $env);
    expect($second['exitCode'])->toBe(0)
        ->and($second['stdout'])->toContain('Files changed: none', 'already requires alexhackney/laravel-zammad')
        ->and(file_get_contents($this->fixtureDir.'/composer-calls.log'))->toBe($calls)
        ->and(file_get_contents($this->fixtureDir.'/composer.json'))->toBe($manifest);
});

it('propagates required Composer removal and requirement failures', function (bool $oldRequired) {
    if (! $oldRequired) {
        file_put_contents($this->fixtureDir.'/composer.json', '{"require":{}}');
    }
    $env = installMigrationComposerProbe($this->fixtureDir, <<<'PHP_SOURCE'
file_put_contents('composer-calls.log', $argv[1]."\n", FILE_APPEND);
fwrite(STDERR, 'composer probe failed');
exit(17);
PHP_SOURCE);
    $result = runMigrateScript([$this->fixtureDir], $env);
    expect($result['exitCode'])->not->toBe(0)
        ->and($result['stderr'])->toContain('composer')
        ->and(file_get_contents($this->fixtureDir.'/composer-calls.log'))->toBe($oldRequired ? "remove\n" : "remove\nrequire\n");
})->with([true, false]);

it('reports write failures without claiming a successful no-op', function () {
    $path = $this->fixtureDir.'/app/Services/SupportService.php';
    chmod($path, 0444);

    try {
        $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
        expect($result['exitCode'])->not->toBe(0)
            ->and($result['stderr'])->toContain('SupportService.php')
            ->and($result['stdout'])->not->toContain('Files changed: none');
    } finally {
        chmod($path, 0644);
    }
});

it('reports multiline facade calls and direct and stored resources with file and line numbers', function () {
    $source = <<<'PHP_SOURCE'
<?php
use CodebarAg\Zammad\Resources\UserResource;
use CodebarAg\Zammad\Resources\UserAccessTokenResource;
(new UserResource)->show(0);
(new UserResource($connector))
    ->update('abc', []);
$users = Zammad::user();
$users->show(0);
Zammad::user()
    ->show(0);
$tokens = Zammad::userAccessToken();
$tokens
    ->delete('abc');
/** @var UserResource $resource */
$resource->show(0);
function change(UserResource $typed): void {
    $typed->update('abc', []);
}
$created = new UserResource($connector);
$created->delete('abc');
Zammad::user()
    ->update('abc', []);
PHP_SOURCE;
    file_put_contents($this->fixtureDir.'/app/Review.php', $source);
    file_put_contents($this->fixtureDir.'/app/Eloquent.php', <<<'PHP_SOURCE'
<?php
// zammad sync may run later
User::query()->update([]);
$user->delete();
$other->show(0);
PHP_SOURCE);
    file_put_contents($this->fixtureDir.'/resources/views/review.blade.php', "{{-- zammad --}}\n{!!\n    \$comment->body_filtered\n!!}");
    file_put_contents($this->fixtureDir.'/.env.local', 'ZAMMAD_OBJECT_REFERENCE_ERROR=example');
    $result = runMigrateScript(['--dry-run', '--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->not->toContain('app/Eloquent.php')
        ->toContain('resources/views/review.blade.php:2', '.env.local:1');
    foreach ([4, 6, 8, 10, 13, 15, 17, 20, 22] as $line) {
        expect($result['stdout'])->toContain('app/Review.php:'.$line.':');
    }
});

it('reports resources assigned from connector accessors and the actual token resource class', function () {
    file_put_contents($this->fixtureDir.'/app/Stored.php', <<<'PHP_SOURCE'
<?php
use CodebarAg\Zammad\Resources\UserAccessToken;
$users = $connector->user();
$users
    ->show(0);
$tokens = $connector->userAccessToken();
$tokens->delete('abc');
(new UserAccessToken($connector))->delete('abc');
PHP_SOURCE);
    $result = runMigrateScript(['--dry-run', '--skip-composer', $this->fixtureDir]);
    expect($result['exitCode'])->toBe(0);
    foreach ([5, 7, 8] as $line) {
        expect($result['stdout'])->toContain('app/Stored.php:'.$line.':');
    }
});

it('prints manual commands and reports a missing Composer binary with the correct status', function (bool $skip, bool $dryRun) {
    mkdir($this->fixtureDir.'/php-only');
    symlink(PHP_BINARY, $this->fixtureDir.'/php-only/php');
    $arguments = array_values(array_filter([$skip ? '--skip-composer' : null, $dryRun ? '--dry-run' : null, $this->fixtureDir]));
    $result = runMigrateScript($arguments, ['PATH' => $this->fixtureDir.'/php-only']);
    expect($result['exitCode'])->toBe($skip ? 0 : 1)
        ->and($result['stdout'])->toContain('Composer binary not found', 'composer remove codebar-ag/laravel-zammad --no-interaction --no-scripts', 'composer require alexhackney/laravel-zammad --no-interaction');
})->with([[true, false], [false, false], [true, true], [false, true]]);

it('aborts on source read failure before applying any prepared rewrites', function () {
    $source = $this->fixtureDir.'/app/Services/SupportService.php';
    $manifest = file_get_contents($this->fixtureDir.'/composer.json');
    chmod($source, 0000);

    try {
        $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
        expect($result['exitCode'])->not->toBe(0)
            ->and($result['stderr'])->toContain('SupportService.php')
            ->and($result['stdout'])->not->toContain('Files changed: none')
            ->and(file_get_contents($this->fixtureDir.'/composer.json'))->toBe($manifest);
    } finally {
        chmod($source, 0644);
    }
});

it('preserves named repositories and env bytes while scanning lib and excluding nested dependency trees', function () {
    $manifest = <<<'JSON'
{
    "require": {},
    "repositories": {
        "local": {"type": "path", "url": "../laravel-zammad"},
        "old": {"type": "vcs", "url": "ssh://git@example.test/codebar-ag/laravel-zammad.git"},
        "private": {"type": "git", "url": "git@example.test:acme/other.git"},
        "packagist.org": false
    }
}
JSON;
    file_put_contents($this->fixtureDir.'/composer.json', $manifest);
    mkdir($this->fixtureDir.'/lib');
    file_put_contents($this->fixtureDir.'/lib/Bridge.php', '<?php use CodebarAg\\Zammad\\DTO\\Ticket;');
    $original = '<?php use CodebarAg\\Zammad\\DTO\\Ticket;';
    foreach (['vendor', 'node_modules', 'storage', '.git'] as $directory) {
        mkdir($this->fixtureDir.'/app/'.$directory);
        file_put_contents($this->fixtureDir.'/app/'.$directory.'/Keep.php', $original);
    }
    $env = "ZAMMAD_OBJECT_REFERENCE_ERROR=unchanged\n";
    file_put_contents($this->fixtureDir.'/.env', $env);
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
    $decoded = json_decode((string) file_get_contents($this->fixtureDir.'/composer.json'));
    expect($result['exitCode'])->toBe(0)
        ->and($decoded->repositories)->toBeInstanceOf(stdClass::class)
        ->and(array_keys(get_object_vars($decoded->repositories)))->toBe(['local', 'private', 'packagist.org'])
        ->and(file_get_contents($this->fixtureDir.'/lib/Bridge.php'))->toContain('AlexHackney\\Zammad\\Data\\Ticket')
        ->and(file_get_contents($this->fixtureDir.'/.env'))->toBe($env);
    foreach (['vendor', 'node_modules', 'storage', '.git'] as $directory) {
        expect(file_get_contents($this->fixtureDir.'/app/'.$directory.'/Keep.php'))->toBe($original);
    }
});

it('refuses symlinks anywhere in the selected application path', function (string $suffix) {
    symlink($this->fixtureDir, $this->fixtureDir.'/app-link');
    $before = file_get_contents($this->fixtureDir.'/app/Services/SupportService.php');
    if ($suffix === '/nested') {
        mkdir($this->fixtureDir.'/nested');
        file_put_contents($this->fixtureDir.'/nested/composer.json', '{"require":{}}');
    }
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir.'/app-link'.$suffix]);
    expect($result['exitCode'])->not->toBe(0)
        ->and($result['stderr'])->toContain('symlink')
        ->and(file_get_contents($this->fixtureDir.'/app/Services/SupportService.php'))->toBe($before);
})->with(['', '/', '/.', '/nested']);

it('preserves the failing original and removes temporary files after a partial write', function (bool $manifestFailure) {
    if (! function_exists('posix_setrlimit') || ! function_exists('pcntl_signal')) {
        $this->markTestSkipped('POSIX file-size limits and signal handling are required.');
    }
    $relative = $manifestFailure ? 'composer.json' : 'app/Atomic.php';
    $original = $manifestFailure
        ? '{"extra":{"keep":"'.str_repeat('x', 500).'"},"repositories":[{"type":"vcs","url":"https://example.test/laravel-zammad"}]}'
        : "<?php\n".str_repeat("use CodebarAg\\Zammad\\DTO\\Ticket;\n", 20);
    if (! $manifestFailure) {
        file_put_contents($this->fixtureDir.'/composer.json', '{"require":{}}');
    }
    $path = $this->fixtureDir.'/'.$relative;
    file_put_contents($path, $original);
    $entries = scandir(dirname($path));
    $limit = $manifestFailure ? 20 : strlen($original) + 5;
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir], [], null,
        'pcntl_signal(SIGXFSZ, SIG_IGN); posix_setrlimit(POSIX_RLIMIT_FSIZE, '.$limit.', '.$limit.');');

    expect($result['exitCode'])->not->toBe(0)
        ->and($result['stderr'])->toContain('write', 'File too large')
        ->and(file_get_contents($path))->toBe($original)
        ->and(scandir(dirname($path)))->toBe($entries);
})->with([true, false]);

it('preserves permissions when replacing a migrated file', function () {
    $path = $this->fixtureDir.'/app/Services/SupportService.php';
    chmod($path, 0750);
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
    clearstatcache(true, $path);

    expect($result['exitCode'])->toBe(0)
        ->and(fileperms($path) & 0777)->toBe(0750)
        ->and(file_get_contents($path))->toContain('AlexHackney\\Zammad\\Data');
});

it('refuses unsafe manifest numbers or invalid JSON before any changes and prints manual steps', function (string $extra, string $error) {
    $manifest = '{"repositories":[{"type":"vcs","url":"https://example.test/laravel-zammad"}],"extra":'.$extra.'}';
    file_put_contents($this->fixtureDir.'/composer.json', $manifest);
    $source = file_get_contents($this->fixtureDir.'/app/Services/SupportService.php');
    $env = installMigrationComposerProbe($this->fixtureDir, "file_put_contents('composer-calls.log', 'called');");
    $result = runMigrateScript([$this->fixtureDir], $env);

    expect($result['exitCode'])->not->toBe(0)
        ->and($result['stderr'])->toContain('composer.json', $error)
        ->and($result['stdout'])->toContain('manually', 'repositories', 'CodebarAg\\Zammad', 'AlexHackney\\Zammad', 'DTO', 'Data')
        ->and(file_get_contents($this->fixtureDir.'/composer.json'))->toBe($manifest)
        ->and(file_get_contents($this->fixtureDir.'/app/Services/SupportService.php'))->toBe($source)
        ->and(file_exists($this->fixtureDir.'/composer-calls.log'))->toBeFalse();
})->with([
    ['{"account":9223372036854775809}', 'integer'],
    ['{"account":-1234567890123456}', 'integer'],
    ['{"broken":}', 'JSON'],
]);

it('accepts long numbers inside JSON strings and ordinary JSON numbers without precision loss', function () {
    $manifest = '{"extra":{"escaped":"quote: \\" account: 9223372036854775809","account":"9223372036854775809","safe":999999999999999,"fraction":1.25,"exponent":1e-16},"repositories":[{"type":"vcs","url":"https://example.test/laravel-zammad"}]}';
    file_put_contents($this->fixtureDir.'/composer.json', $manifest);
    $before = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0)
        ->and(json_decode(file_get_contents($this->fixtureDir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR)['extra'])->toBe($before['extra']);
});

it('runs Composer in the selected app with safe argv and environment', function () {
    $env = installMigrationComposerProbe($this->fixtureDir, <<<'PHP_SOURCE'
file_put_contents('composer-calls.log', json_encode(['argv' => array_slice($argv, 1), 'env' => getenv(), 'cwd' => getcwd()])."\n", FILE_APPEND);
PHP_SOURCE);
    $outside = '{"require":{"outside/package":"*"}}';
    file_put_contents($this->fixtureDir.'/other.json', $outside);
    $env += [
        'COMPOSER' => $this->fixtureDir.'/other.json',
        'COMPOSER_ROOT_VERSION' => '99.0.0',
        'COMPOSER_HOME' => $this->fixtureDir.'/composer-home',
        'COMPOSER_CACHE_DIR' => $this->fixtureDir.'/composer-cache',
        'MIGRATION_ENV_PROBE' => 'kept',
    ];
    $result = runMigrateScript([$this->fixtureDir], $env);
    expect($result['exitCode'])->toBe(0);
    $calls = array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($this->fixtureDir.'/composer-calls.log', FILE_IGNORE_NEW_LINES));
    expect(array_column($calls, 'argv'))->toBe([
        ['remove', 'codebar-ag/laravel-zammad', '--no-interaction', '--no-scripts', '--working-dir='.$this->fixtureDir],
        ['require', 'alexhackney/laravel-zammad', '--no-interaction', '--working-dir='.$this->fixtureDir],
    ]);
    foreach ($calls as $call) {
        expect($call['env'])->not->toHaveKeys(['COMPOSER', 'COMPOSER_ROOT_VERSION'])
            ->and($call['env']['COMPOSER_HOME'])->toBe($env['COMPOSER_HOME'])
            ->and($call['env']['COMPOSER_CACHE_DIR'])->toBe($env['COMPOSER_CACHE_DIR'])
            ->and($call['env']['MIGRATION_ENV_PROBE'])->toBe('kept')
            ->and($call['cwd'])->toBe($this->fixtureDir);
    }
    expect(file_get_contents($this->fixtureDir.'/other.json'))->toBe($outside);
});

it('refuses linked lockfiles before Composer or any rewrites', function (bool $hardLink) {
    file_put_contents($this->fixtureDir.'/outside.lock', '{"packages":[]}');
    $target = $this->fixtureDir.'/outside.lock';
    if ($hardLink) {
        link($target, $this->fixtureDir.'/composer.lock');
    } else {
        symlink($target, $this->fixtureDir.'/composer.lock');
    }
    $manifest = file_get_contents($this->fixtureDir.'/composer.json');
    $source = file_get_contents($this->fixtureDir.'/app/Services/SupportService.php');
    $env = installMigrationComposerProbe($this->fixtureDir, "file_put_contents('composer-calls.log', 'called');");
    $result = runMigrateScript([$this->fixtureDir], $env);

    expect($result['exitCode'])->not->toBe(0)
        ->and($result['stderr'])->toContain('composer.lock', 'link')
        ->and(file_exists($this->fixtureDir.'/composer-calls.log'))->toBeFalse()
        ->and(file_get_contents($target))->toBe('{"packages":[]}')
        ->and(file_get_contents($this->fixtureDir.'/composer.json'))->toBe($manifest)
        ->and(file_get_contents($this->fixtureDir.'/app/Services/SupportService.php'))->toBe($source);
})->with([true, false]);

it('skips PHP files containing serialized old namespaces and lists them for review', function (string $literal) {
    $source = "<?php\nuse CodebarAg\\Zammad\\DTO\\User;\nreturn ".$literal.";\n";
    $path = $this->fixtureDir.'/config/serialized.php';
    file_put_contents($path, $source);
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0)
        ->and(file_get_contents($path))->toBe($source)
        ->and($result['stdout'])->toContain('Review manually:', '[Skipped serialized-string literal]', 'config/serialized.php')
        ->and(file_get_contents($this->fixtureDir.'/app/Services/SupportService.php'))->toContain('AlexHackney\\Zammad\\Data');
})->with([
    <<<'SOURCE'
'a:1:{s:5:"class";s:25:"CodebarAg\\Zammad\\DTO\\User";}'
SOURCE,
    <<<'SOURCE'
"s:25:\"CodebarAg\\Zammad\\DTO\\User\";"
SOURCE,
    <<<'SOURCE'
's:40:"prefix \"quoted\" CodebarAg\\Zammad\\DTO\\User";'
SOURCE,
    'fully qualified serialized name' => [<<<'SOURCE'
's:26:"\CodebarAg\\Zammad\\DTO\\User";'
SOURCE],
    'long serialized value' => ["'s:1500025:\"".str_repeat('x', 1_500_000).'CodebarAg\\\\Zammad\\\\DTO\\\\User";\''],
]);

it('migrates development dependencies in their original requirement sections', function (bool $alsoRuntime) {
    $manifest = json_decode(file_get_contents($this->fixtureDir.'/composer.json'));
    $manifest->{'require-dev'} = (object) ['codebar-ag/laravel-zammad' => '^12.0'];
    if (! $alsoRuntime) {
        unset($manifest->require->{'codebar-ag/laravel-zammad'});
    }
    file_put_contents($this->fixtureDir.'/composer.json', json_encode($manifest));
    $env = installMigrationComposerProbe($this->fixtureDir, <<<'PHP_SOURCE'
file_put_contents('composer-calls.log', json_encode(array_slice($argv, 1))."\n", FILE_APPEND);
$manifest = json_decode(file_get_contents('composer.json'));
$section = in_array('--dev', $argv, true) ? 'require-dev' : 'require';
if ($argv[1] === 'remove') {
    if (! isset($manifest->{$section}->{'codebar-ag/laravel-zammad'})) {
        exit(18);
    }
    unset($manifest->{$section}->{'codebar-ag/laravel-zammad'});
} else {
    $manifest->{$section}->{'alexhackney/laravel-zammad'} = '^1.0';
}
file_put_contents('composer.json', json_encode($manifest));
PHP_SOURCE);
    $result = runMigrateScript([$this->fixtureDir], $env);
    expect($result['exitCode'])->toBe(0);
    $calls = array_map(fn (string $line): array => json_decode($line, true), file($this->fixtureDir.'/composer-calls.log', FILE_IGNORE_NEW_LINES));
    $expected = [];
    foreach ($alsoRuntime ? [false, true] : [true] as $dev) {
        $flags = $dev ? ['--dev'] : [];
        $expected[] = ['remove', 'codebar-ag/laravel-zammad', '--no-interaction', '--no-scripts', ...$flags, '--working-dir='.$this->fixtureDir];
        $expected[] = ['require', 'alexhackney/laravel-zammad', '--no-interaction', ...$flags, '--working-dir='.$this->fixtureDir];
    }
    expect($calls)->toBe($expected)
        ->and($result['stdout'])->toContain('Files changed:', 'Review manually:');
    $after = json_decode(file_get_contents($this->fixtureDir.'/composer.json'), true);
    foreach ($alsoRuntime ? ['require', 'require-dev'] : ['require-dev'] as $section) {
        expect($after[$section])->toHaveKey('alexhackney/laravel-zammad')->not->toHaveKey('codebar-ag/laravel-zammad');
    }
    $again = runMigrateScript([$this->fixtureDir], $env);
    expect($again['exitCode'])->toBe(0)
        ->and($again['stdout'])->toContain('already requires alexhackney/laravel-zammad');
})->with([true, false]);

it('rewrites DTO members in root grouped imports including multiline nested segments', function () {
    $source = <<<'SOURCE'
<?php
use CodebarAg\Zammad\{DTO\User, Resources\UserResource};
use CodebarAg\Zammad\{
    DTO\Nested\Ticket as Ticket,
    DTO\Comment,
    Resources\DTO\Keep,
};
use OtherVendor\Zammad\{DTO\Keep};
SOURCE;
    file_put_contents($this->fixtureDir.'/app/Groups.php', $source);
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0)
        ->and(file_get_contents($this->fixtureDir.'/app/Groups.php'))->toBe(str_replace(
            ['CodebarAg\\Zammad', '{DTO\\User', '    DTO\\'],
            ['AlexHackney\\Zammad', '{Data\\User', '    Data\\'],
            $source,
        ));
});

it('finishes DTO moves below an already renamed root and preserves other namespaces', function () {
    $source = <<<'SOURCE'
<?php
use AlexHackney\Zammad\DTO\User;
use AlexHackney\Zammad\DTO\{Ticket, Comment};
use App\DTO\User as AppUser;
use OtherVendor\DTO\User as OtherUser;
use App\AlexHackney\Zammad\DTO\User as NestedUser;
$class = 'AlexHackney\\Zammad\\DTO\\Attachment';
SOURCE;
    file_put_contents($this->fixtureDir.'/app/Partial.php', $source);
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
    $expected = str_replace(
        ['use AlexHackney\\Zammad\\DTO', "'AlexHackney\\\\Zammad\\\\DTO"],
        ['use AlexHackney\\Zammad\\Data', "'AlexHackney\\\\Zammad\\\\Data"],
        $source,
    );
    expect($result['exitCode'])->toBe(0)
        ->and(file_get_contents($this->fixtureDir.'/app/Partial.php'))->toBe($expected);
});

it('rewrites large PHP files within the time limit and reports the skipped review scan', function () {
    $source = "<?php\n/*".str_repeat('x', 12_000_000)."*/\nuse CodebarAg\\Zammad\\DTO\\User;\n";
    file_put_contents($this->fixtureDir.'/app/Generated.php', $source);
    $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0)
        ->and(file_get_contents($this->fixtureDir.'/app/Generated.php'))->toBe(str_replace('CodebarAg\\Zammad\\DTO', 'AlexHackney\\Zammad\\Data', $source))
        ->and($result['stdout'])->toContain('Review manually:', '[Review scan skipped: larger than 2 MB]', 'app/Generated.php');
});

it('scans smaller generated files within the time limit and still reports qualified resources', function () {
    $source = "<?php\n/*".str_repeat('x', 1_900_000)."*/\n".<<<'SOURCE'
use CodebarAg\Zammad\Resources\UserResource;
function change(\CodebarAg\Zammad\Resources\UserResource $users): void {
    $users->show(0);
}
(new \CodebarAg\Zammad\Resources\UserResource($connector))->delete('abc');
$stored = $this->connector->user();
$stored->update('abc', []);
SOURCE;
    file_put_contents($this->fixtureDir.'/app/Generated.php', $source);
    $result = runMigrateScript(['--dry-run', '--skip-composer', $this->fixtureDir]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->not->toContain('Review scan skipped:')
        ->toContain('app/Generated.php:5:', 'app/Generated.php:7:', 'app/Generated.php:9:')
        ->and(file_get_contents($this->fixtureDir.'/app/Generated.php'))->toBe($source);
});

it('aborts on an unreadable subdirectory without applying any prepared changes', function () {
    $directory = $this->fixtureDir.'/app/Private';
    mkdir($directory);
    $original = '<?php use CodebarAg\\Zammad\\DTO\\User;';
    file_put_contents($directory.'/Secret.php', $original);
    $manifest = file_get_contents($this->fixtureDir.'/composer.json');
    $source = file_get_contents($this->fixtureDir.'/app/Services/SupportService.php');
    chmod($directory, 0000);
    try {
        $result = runMigrateScript(['--skip-composer', $this->fixtureDir]);
        expect($result['exitCode'])->not->toBe(0)
            ->and($result['stderr'])->toContain('Private')
            ->and(file_get_contents($this->fixtureDir.'/composer.json'))->toBe($manifest)
            ->and(file_get_contents($this->fixtureDir.'/app/Services/SupportService.php'))->toBe($source);
    } finally {
        chmod($directory, 0755);
    }
    expect(file_get_contents($directory.'/Secret.php'))->toBe($original);
});

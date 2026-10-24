<?php
/**
 * Builds a ready-to-upload release zip (production dependencies included, no secrets, no tests).
 *
 *   php scripts/build-release.php [version]        -> dist/mailpanel-<version>.zip
 *
 * Needs Composer (on PATH, or composer.phar in this folder or the one above) and the PHP zip extension.
 * The zip contains one top-level folder, mailpanel/. Upload it to the server, unzip, point the
 * domain's document root at mailpanel/public and open the site: the setup wizard starts by itself.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run from the command line.');
}
if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$version = preg_replace('/[^A-Za-z0-9._-]/', '', $argv[1] ?? (require $root.'/config/panel.php')['version'] ?? 'dev');
$stage = sys_get_temp_dir().'/mailpanel-release-'.bin2hex(random_bytes(4));
$dist = $root.'/dist';

// Anything matching is left out of the zip.
$exclude = [
    '#^(\.git|\.github|\.idea|\.vscode|\.phpunit\.cache|node_modules|vendor|tests|dist)(/|$)#',
    '#^(\.env|\.env\.(?!example$).*|\.phpunit\.result\.cache|phpunit\.xml|app\.zip|auth\.json)$#',
    '#^database/.*\.sqlite.*$#',
    '#^storage/logs/(?!\.gitignore$)#',
    '#^storage/framework/(sessions|cache|views|testing)/(?!\.gitignore$)#',
    '#^storage/app/(?!\.gitignore$|private/\.gitignore$|public/\.gitignore$)#',
    '#^bootstrap/cache/(?!\.gitignore$)#',
    '#^public/(hot|storage)(/|$)#',
];

function copyTree(string $from, string $to, array $exclude, string $rel = ''): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = ($rel === '' ? '' : $rel.'/').$name;
        foreach ($exclude as $pattern) {
            if (preg_match($pattern, $path)) {
                continue 2;
            }
        }
        is_dir("$from/$name") ? copyTree("$from/$name", "$to/$name", $exclude, $path) : copy("$from/$name", "$to/$name");
    }
}

function rrmdir(string $dir): void
{
    foreach (is_dir($dir) ? scandir($dir) : [] as $n) {
        if ($n !== '.' && $n !== '..') {
            is_dir("$dir/$n") ? rrmdir("$dir/$n") : @unlink("$dir/$n");
        }
    }
    @rmdir($dir);
}

echo "Copying files...\n";
copyTree($root, "$stage/mailpanel", $exclude);

$composer = null;
foreach ([getenv('COMPOSER_BIN') ?: null, $root.'/composer.phar', dirname($root).'/composer.phar'] as $c) {
    if ($c && is_file($c)) {
        $composer = escapeshellarg(PHP_BINARY).' '.escapeshellarg($c);
        break;
    }
}
$composer ??= 'composer';

echo "Installing production dependencies...\n";
passthru($composer.' install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir='.escapeshellarg("$stage/mailpanel"), $code);
if ($code !== 0) {
    rrmdir($stage);
    fwrite(STDERR, "composer install failed.\n");
    exit(1);
}

@mkdir($dist, 0777, true);
$zipPath = "$dist/mailpanel-$version.zip";
@unlink($zipPath);
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$stage/mailpanel", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$count = 0;
foreach ($it as $file) {
    $local = 'mailpanel/'.str_replace('\\', '/', substr($file->getPathname(), strlen("$stage/mailpanel/")));
    if ($file->isDir()) {
        $zip->addEmptyDir($local);
    } else {
        $zip->addFile($file->getPathname(), $local);
        $count++;
    }
}
$zip->close();
rrmdir($stage);

printf("Built %s (%d files, %.1f MB)\n", $zipPath, $count, filesize($zipPath) / 1048576);

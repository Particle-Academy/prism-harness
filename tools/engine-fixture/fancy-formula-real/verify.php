<?php

declare(strict_types=1);

/** Verify the entire Packagist dist against its reviewed Git tree BEFORE code runs. */
function verifyFormulaFixture(): string
{
    $pin = json_decode(file_get_contents(__DIR__.'/pin.json'), true, flags: JSON_THROW_ON_ERROR);
    $commit = '9dcef871f0703167923f21a8720c44243d78e2d8';
    $tree = '27b3c56911e4eaa84b7e707b1aa6aa60d22745e9';
    $installed = json_decode(file_get_contents(__DIR__.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = $installed['packages'] ?? [];
    if (count($packages) !== 1 || $pin['commit'] !== $commit || $pin['tree'] !== $tree) {
        throw new RuntimeException('Unexpected formula fixture dependency set or pin.');
    }
    $package = $packages[0];
    if ($package['name'] !== 'particle-academy/fancy-formula' || ltrim($package['version'], 'v') !== '0.1.0'
        || $package['installation-source'] !== 'dist' || $package['dist']['reference'] !== $commit || $package['source']['reference'] !== $commit) {
        throw new RuntimeException('Formula fixture is not the exact reviewed Packagist dist.');
    }
    $directory = __DIR__.'/vendor/particle-academy/fancy-formula';
    $entries = [];
    $files = [];
    foreach ($pin['entries'] as $entry) {
        $path = $entry['path'];
        if (preg_match('~\A(?:[a-zA-Z0-9_.-]+/)*[a-zA-Z0-9_.-]+\z~D', $path) !== 1 || str_contains($path, '..')) {
            throw new RuntimeException('Invalid pin path.');
        }
        if ($entry['type'] === 'blob') {
            $full = $directory.'/'.$path;
            $bytes = $entry['mode'] === '120000' && is_link($full) ? readlink($full) : file_get_contents($full);
            // PHP on Windows expands a relative symlink to its absolute target.
            // Recover the Git link bytes only for a target within this directory.
            if ($entry['mode'] === '120000' && is_link($full) && PHP_OS_FAMILY === 'Windows' && is_string($bytes)) {
                $parent = str_replace('\\', '/', realpath(dirname($full))).'/';
                $target = str_replace('\\', '/', $bytes);
                if (str_starts_with($target, $parent)) {
                    $bytes = substr($target, strlen($parent));
                }
            }
            if ($bytes === false || sha1('blob '.strlen($bytes)."\0".$bytes) !== $entry['sha']) {
                throw new RuntimeException('Formula dist blob differs: '.$path);
            }
            $files[] = $path;
        } elseif ($entry['type'] !== 'tree' || $entry['mode'] !== '040000') {
            throw new RuntimeException('Unsupported formula pin entry.');
        }
        $entries[$path] = $entry;
    }
    $actual = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $actual[] = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
    }
    sort($actual, SORT_STRING);
    sort($files, SORT_STRING);
    if ($actual !== $files) {
        throw new RuntimeException('Formula dist contains missing or additional files.');
    }
    $directories = ['' => $tree];
    foreach ($entries as $path => $entry) {
        if ($entry['type'] === 'tree') {
            $directories[$path] = $entry['sha'];
        }
    }
    foreach ($directories as $directoryPath => $expected) {
        $children = [];
        foreach ($entries as $path => $entry) {
            $parent = dirname($path);
            if (($parent === '.' ? '' : $parent) === $directoryPath) {
                $name = basename($path);
                $sortKey = $name.($entry['type'] === 'tree' ? '/' : '');
                $children[$sortKey] = ltrim($entry['mode'], '0').' '.$name."\0".hex2bin($entry['sha']);
            }
        }
        ksort($children, SORT_STRING);
        $body = implode('', $children);
        if (sha1('tree '.strlen($body)."\0".$body) !== $expected) {
            throw new RuntimeException('Formula pin tree is inconsistent: '.$directoryPath);
        }
    }

    return $commit.' tree '.$tree.' / '.count($files).' verified blobs';
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    echo verifyFormulaFixture().PHP_EOL;
}

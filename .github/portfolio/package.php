<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc < 4) {
    exit("Usage: php package.php <source> <plugin-slug> <output-directory> [tag]\n");
}
[$script, $source, $slug, $output] = $argv;
if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) {
    throw new InvalidArgumentException('Expected a valid plugin folder name.');
}
$source = realpath($source);
if (false === $source || !is_file("$source/$slug.php") || !is_file("$source/LICENSE")) {
    throw new RuntimeException('The main plugin file and LICENSE are required.');
}
$header = file_get_contents("$source/$slug.php", false, null, 0, 8192);
if (!preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/m', $header, $version)) {
    throw new RuntimeException('Expected a versioned WordPress plugin header.');
}
if (isset($argv[4]) && $argv[4] !== 'v' . $version[1]) {
    throw new RuntimeException('The Git tag must match the plugin header.');
}
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    throw new RuntimeException('Could not create the package directory.');
}

$runtime = ['LICENSE'];
foreach (new DirectoryIterator($source) as $file) {
    if ($file->isFile() && !$file->isLink() &&
        (in_array($file->getExtension(), ['php', 'js', 'css'], true) || 'block.json' === $file->getFilename())) {
        $runtime[] = $file->getFilename();
    }
}
foreach (['assets', 'includes', 'languages'] as $folder) {
    if (!is_dir("$source/$folder")) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$source/$folder", FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $runtime[] = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
        }
    }
}
sort($runtime);
$basename = "$slug-{$version[1]}.zip";
$target = "$output/$basename";
$zip = new ZipArchive();
if (true !== $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    throw new RuntimeException('Could not create the plugin ZIP.');
}
foreach ($runtime as $path) {
    if (!$zip->addFile("$source/$path", "$slug/$path")) {
        throw new RuntimeException("Could not package $path.");
    }
}
if (!$zip->close()) {
    throw new RuntimeException('Could not finish the package.');
}
$hash = hash_file('sha256', $target);
if (!$hash || false === file_put_contents("$target.sha256", "$hash  $basename\n")) {
    throw new RuntimeException('Could not write the SHA-256 manifest.');
}
echo "$target\n";

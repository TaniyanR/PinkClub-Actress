<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Backward compatibility: old links sometimes point to actresses.php?id=123.
// Treat those as actress detail links instead of returning the directory/404.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (is_int($id) && $id > 0) {
    header('Location: ' . public_url('actress.php?id=' . $id), true, 301);
    exit;
}

$pcaDirectoryAmateur = false;
require __DIR__ . '/actress_directory_page.php';

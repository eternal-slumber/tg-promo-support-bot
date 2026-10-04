<?php

test('excludes the local Vite hot file from the Docker build context', function () {
    $patterns = file(dirname(__DIR__, 2).'/.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($patterns)->toContain('public/hot');
});

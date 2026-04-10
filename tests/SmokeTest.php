<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitApacheNifi\NiFiToolkit;

test('nifi toolkit can be instantiated from environment', function () {
    putenv('NIFI_BASE_URL=https://nifi.example.test');
    putenv('NIFI_USERNAME=test-user');
    putenv('NIFI_PASSWORD=test-pass');

    $toolkit = new NiFiToolkit();

    expect($toolkit)->toBeInstanceOf(NiFiToolkit::class);
    expect($toolkit->tools())->not->toBeEmpty();
});
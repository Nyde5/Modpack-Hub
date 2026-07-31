<?php

use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\InstallCleanupService;

$summary = app(InstallCleanupService::class)->sweep();

if ($summary['checked'] === 0) {
    $this->line('modpackhub: no stale installations.');
} else {
    $this->line(sprintf(
        'modpackhub: %d stale installation(s) closed, %d server egg(s) restored.',
        $summary['failed'],
        $summary['restored']
    ));
}

foreach ($summary['errors'] as $error) {
    $this->error('modpackhub: egg restore failed for ' . $error);
}

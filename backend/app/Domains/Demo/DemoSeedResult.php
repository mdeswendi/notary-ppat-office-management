<?php

namespace App\Domains\Demo;

/**
 * What one successful {@see DemoDataSeeder::seed()} run produced.
 *
 * Counts only — no name, no email, no record id. A command reporting layer
 * needs to say how much was created, never what any of it was called.
 */
final class DemoSeedResult
{
    public function __construct(
        public readonly string $officeCode,
        public readonly int $users,
        public readonly int $parties,
        public readonly int $projects,
        public readonly int $matters,
        public readonly int $documents,
        public readonly int $tasks,
        public readonly int $notaryDeeds,
    ) {}
}

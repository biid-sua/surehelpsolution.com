<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * docs/schema.md is generated from the migrations (`php artisan docs:schema`) and must stay in step.
 */
class SchemaDocsTest extends TestCase
{
    public function test_the_schema_reference_matches_the_migrations(): void
    {
        $this->artisan('docs:schema --check')
            ->assertSuccessful();
    }
}

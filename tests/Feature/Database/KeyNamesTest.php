<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KeyNamesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MySQL refuses identifiers over 64 characters, and the names Laravel
     * makes up for keys on several columns pass that easily. SQLite accepts
     * them, so this catches a long name before a MySQL migration fails.
     * (SQLite does not keep foreign key names; MySQL checks those itself.)
     */
    public function test_every_index_and_foreign_key_name_fits_in_mysql()
    {
        $names = [];

        foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), false) as $table) {
            foreach ([...Schema::getIndexes($table), ...Schema::getForeignKeys($table)] as $key) {
                if (is_string($key['name'])) {
                    $names[] = $key['name'];
                }
            }
        }

        $this->assertSame([], array_values(array_filter($names, fn (string $name) => strlen($name) > 64)));
        $this->assertContains('orders_tracking_number_unique', $names);
    }
}

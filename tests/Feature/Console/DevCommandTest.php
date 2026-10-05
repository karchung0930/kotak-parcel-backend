<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\DevCommands;
use Tests\TestCase;

class DevCommandTest extends TestCase
{
    public function test_composer_run_dev_starts_the_site_the_queue_and_reverb()
    {
        // composer run dev runs "php artisan dev", which starts each of these.
        $commands = array_column(DevCommands::commands(), 'command', 'name');

        $this->assertSame('php artisan serve', $commands['server'] ?? null);
        // The live delivery progress queue first, as on the server.
        $this->assertSame('php artisan queue:listen --queue=live,default --tries=1 --timeout=0', $commands['queue'] ?? null);
        $this->assertSame('php artisan reverb:start', $commands['reverb'] ?? null);
    }
}

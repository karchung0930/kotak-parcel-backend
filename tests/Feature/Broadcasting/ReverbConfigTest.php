<?php

namespace Tests\Feature\Broadcasting;

use Tests\TestCase;

class ReverbConfigTest extends TestCase
{
    public function test_allowed_origins_may_be_written_with_spaces()
    {
        $app = $this->reverbApp(['REVERB_ALLOWED_ORIGINS' => 'kotak.example.com, 127.0.0.1 ,']);

        $this->assertSame(['kotak.example.com', '127.0.0.1'], $app['allowed_origins']);
    }

    public function test_without_a_list_only_the_sites_own_host_may_connect()
    {
        $app = $this->reverbApp(['REVERB_ALLOWED_ORIGINS' => null, 'APP_URL' => 'https://kotak.example.com']);

        $this->assertSame(['kotak.example.com'], $app['allowed_origins']);
    }

    public function test_reverb_listens_on_this_machine_only_unless_told_otherwise()
    {
        $config = $this->reverbConfig(['REVERB_SERVER_HOST' => null]);

        $this->assertSame('127.0.0.1', $config['servers']['reverb']['host']);
    }

    public function test_connections_are_capped_and_chatty_ones_are_closed()
    {
        $app = $this->reverbApp([
            'REVERB_APP_MAX_CONNECTIONS' => null,
            'REVERB_APP_RATE_LIMITING_ENABLED' => null,
            'REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS' => null,
            'REVERB_APP_RATE_LIMIT_DECAY_SECONDS' => null,
            'REVERB_APP_RATE_LIMIT_TERMINATE' => null,
            'REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM' => null,
        ]);

        $this->assertSame(5000, $app['max_connections']);
        $this->assertSame(['enabled' => true, 'max_attempts' => 30, 'decay_seconds' => 60, 'terminate_on_limit' => true], $app['rate_limiting']);
        $this->assertSame('none', $app['accept_client_events_from']);
    }

    /**
     * Get the Reverb app from config/reverb.php as it reads with these
     * environment values (null: not set).
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    private function reverbApp(array $env): array
    {
        return $this->reverbConfig($env)['apps']['apps'][0];
    }

    /**
     * Read config/reverb.php with these environment values (null: not set),
     * then put the environment back as it was.
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    private function reverbConfig(array $env): array
    {
        $saved = [];

        foreach ($env as $name => $value) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $this->setEnv($name, $value, $value, $value);
        }

        try {
            return require config_path('reverb.php');
        } finally {
            foreach ($saved as $name => [$server, $environment, $process]) {
                $this->setEnv($name, $server, $environment, $process === false ? null : $process);
            }
        }
    }

    /**
     * Set or unset an environment variable everywhere env() looks for it.
     */
    private function setEnv(string $name, ?string $server, ?string $environment, ?string $process): void
    {
        if ($server === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $server;
        }

        if ($environment === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $environment;
        }

        putenv($process === null ? $name : "{$name}={$process}");
    }
}

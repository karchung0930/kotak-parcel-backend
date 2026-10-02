<?php

namespace Tests\Feature\Console;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_verified_admin()
    {
        $this->artisan('kotak:create-admin')
            ->expectsQuestion('Name', 'Nur Aina')
            ->expectsQuestion('Email', 'Nur.Aina@Kotak.test')
            ->expectsQuestion('Password', 'a-long-Secret-1')
            ->expectsOutputToContain('Admin account created for nur.aina@kotak.test.')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'nur.aina@kotak.test')->firstOrFail();

        $this->assertSame('Nur Aina', $admin->name);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNull($admin->branch_id);
        $this->assertTrue(Hash::check('a-long-Secret-1', $admin->password));
    }

    public function test_it_refuses_an_email_that_is_already_taken()
    {
        User::factory()->create(['email' => 'taken@kotak.test']);

        $this->artisan('kotak:create-admin')
            ->expectsQuestion('Name', 'Nur Aina')
            ->expectsQuestion('Email', 'taken@kotak.test')
            ->expectsOutputToContain('The email has already been taken.')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }
}

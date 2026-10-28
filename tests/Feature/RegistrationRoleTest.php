<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_registration_cannot_create_an_admin_account(): void
    {
        $response = $this->postJson('/api/auth/register-client', [
            'name' => 'Mallory Client',
            'email' => 'mallory.client@gmail.com',
            'phone_number' => '09171234567',
            'password' => 'Str0ngPass!23',
            'password_confirmation' => 'Str0ngPass!23',
            'terms_accepted' => true,
            'account_type' => 'admin',
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'mallory.client@gmail.com',
            'account_type' => 'admin',
        ]);

        if ($response->getStatusCode() === 201) {
            $this->assertDatabaseHas('users', [
                'email' => 'mallory.client@gmail.com',
                'account_type' => 'client',
            ]);
        }
    }

    public function test_photographer_registration_cannot_create_an_admin_account(): void
    {
        $response = $this->postJson('/api/auth/register-photographer', [
            'name' => 'Mallory Photographer',
            'email' => 'mallory.photog@gmail.com',
            'phone_number' => '09171234567',
            'password' => 'Str0ngPass!23',
            'password_confirmation' => 'Str0ngPass!23',
            'photographer_type' => 'freelancer',
            'terms_accepted' => true,
            'account_type' => 'admin',
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'mallory.photog@gmail.com',
            'account_type' => 'admin',
        ]);

        if ($response->getStatusCode() === 201) {
            $this->assertDatabaseHas('users', [
                'email' => 'mallory.photog@gmail.com',
                'account_type' => 'photographer',
            ]);
        }
    }
}
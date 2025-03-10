<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_it_allows_a_user_to_log_in_with_valid_credentials()
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        Auth::shouldReceive('attempt')
            ->once()
            ->with([
                'email' => $user->email,
                'password' => 'password123'
            ])
            ->andReturn(true);

        Auth::shouldReceive('user')->andReturn($user);

        $response = $this->post('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
    }

    public function test_it_fails_when_the_user_provides_incorrect_credentials()
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        Auth::shouldReceive('attempt')
            ->once()
            ->with([
                'email' => $user->email,
                'password' => 'wrongpassword'
            ])
            ->andReturn(false);

        $response = $this->post('/api/login', [
            'email' => $user->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);  
    }

    public function test_user_receives_auth_token_after_successful_login()
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);
    
        $response = $this->post('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);
    
        $response->assertStatus(200);
    
        $response->assertJsonStructure([
            'access_token',
            'token_type',
            'user' => [
                'id',
                'name',
                'email',
            ],
        ]);
    }

    public function test_it_fails_when_email_is_missing()
    {
        $response = $this->post('/api/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422) // Assuming you validate request data
            ->assertJsonValidationErrors(['email']);
    }

    public function test_it_fails_when_password_is_missing()
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/api/login', [
            'email' => $user->email,
        ]);

        $response->assertStatus(422) // Assuming you validate request data
            ->assertJsonValidationErrors(['password']);
    }

    public function test_it_fails_when_email_not_found()
    {
        $response = $this->post('/api/login', [
            'email' => 'notfound@example.com',
            'password' => 'password123',
        ]);

        // Assuming the application returns 401 for invalid credentials
        $response->assertStatus(401);
    }
}

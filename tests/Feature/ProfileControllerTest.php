<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ProfileControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::first();

        if (!$this->user) {
            $this->user = User::factory()->create([
                'password' => Hash::make('testpassword'),
            ]);
        }

        $this->user->update(['password' => Hash::make('testpassword')]);
    }

    public function test_it_can_update_the_profile_with_valid_data()
    {
        $this->actingAs($this->user, 'sanctum');

        $newData = [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com',
            'bio' => 'This is a test bio',
            'confirmPassword' => 'testpassword',
            'newPassword' => 'newpassword123',
        ];

        $response = $this->postJson('/api/update-profile', $newData);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Profile updated successfully',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'bio' => 'This is a test bio',
        ]);

        $this->assertTrue(Hash::check('newpassword123', $this->user->fresh()->password));
    }

    public function test_it_fails_to_update_profile_with_invalid_current_password()
    {
        $this->actingAs($this->user, 'sanctum');

        $invalidPasswordData = [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com',
            'confirmPassword' => 'wrongpassword',
        ];

        $response = $this->postJson('/api/update-profile', $invalidPasswordData);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Current password is incorrect',
            ]);
    }

    public function test_it_fails_validation_for_invalid_data()
    {
        $this->actingAs($this->user, 'sanctum');

        $invalidData = [
            'firstName' => '',
            'lastName' => '',
            'email' => 'invalid-email',
            'confirmPassword' => 'testpassword',
        ];

        $response = $this->postJson('/api/update-profile', $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['firstName', 'lastName', 'email']);
    }

    public function test_it_can_upload_and_update_profile_image()
    {
        $this->actingAs($this->user, 'sanctum');

        Storage::fake('public');

        $image = UploadedFile::fake()->image('profile.jpg');

        $response = $this->postJson('/api/update-profile', [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com',
            'bio' => 'This is a test bio',
            'confirmPassword' => 'testpassword',
            'image' => $image,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Profile updated successfully',
            ]);

        Storage::disk('public')->assertExists('profile_images/' . $image->hashName());

        $this->assertEquals('profile_images/' . $image->hashName(), $this->user->fresh()->profile_image);
    }

    public function test_it_can_get_user_profile()
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/get-profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'firstName',
                    'lastName',
                    'email',
                    'bio',
                    'profileImage'
                ]
            ]);
    }

    public function test_it_returns_401_for_unauthenticated_user()
    {
        $response = $this->postJson('/api/update-profile', []);

        $response->assertStatus(401);
    }
}
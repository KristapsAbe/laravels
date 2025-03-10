<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Capsule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Carbon\Carbon;

class CapsuleControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_it_can_create_a_capsule_with_valid_data()
    {
        $this->actingAs($this->user, 'sanctum');

        Storage::fake('public');

        $image1 = UploadedFile::fake()->image('photo1.jpg');
        $image2 = UploadedFile::fake()->image('photo2.jpg');

        $capsuleData = [
            'images' => [$image1, $image2],
            'image_comments' => ['Nice photo 1', 'Awesome photo 2'],
            'title' => 'My First Capsule',
            'description' => 'This is a test capsule',
            'time' => Carbon::now()->addDays(7)->toDateTimeString(),
            'vision' => 'My vision for this capsule',
            'privacy' => 'friends',
            'design' => 'default',
            'shared_with' => [User::factory()->create()->id],
        ];

        $response = $this->postJson('/api/capsule/create', $capsuleData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'capsule_id',
                'images',
                'image_comments',
                'time',
                'vision',
                'privacy',
                'design',
                'shared_with',
                'status',
            ]);

        $this->assertDatabaseHas('capsules', [
            'user_id' => $this->user->id,
            'title' => 'My First Capsule',
            'description' => 'This is a test capsule',
            'vision' => 'My vision for this capsule',
            'privacy' => 'friends',
            'design' => 'default',
            'status' => 'pending',
        ]);

        $capsuleId = $response->json('capsule_id');
        $capsule = Capsule::find($capsuleId);

        $this->assertCount(2, json_decode($capsule->images, true));
        $this->assertCount(2, json_decode($capsule->image_comments, true));
        $this->assertCount(1, $capsule->sharedUsers);

        Storage::disk('public')->assertExists(json_decode($capsule->images, true)[0]);
        Storage::disk('public')->assertExists(json_decode($capsule->images, true)[1]);
    }

    public function test_it_fails_to_create_capsule_with_invalid_data()
    {
        $this->actingAs($this->user, 'sanctum');

        $invalidData = [
            'title' => '',
            'description' => '',
            'time' => 'invalid-date',
            'vision' => '',
            'privacy' => 'invalid-privacy',
            'design' => '',
        ];

        $response = $this->postJson('/api/capsule/create', $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['images', 'title', 'description', 'time', 'vision', 'privacy', 'design']);

        $this->assertDatabaseMissing('capsules', [
            'user_id' => $this->user->id,
            'title' => '',
            'description' => '',
        ]);
    }
    public function test_it_creates_capsule_with_different_privacy_settings()
    {
        $this->actingAs($this->user, 'sanctum');

        $privacySettings = ['private', 'public', 'friends'];

        foreach ($privacySettings as $privacy) {
            $capsuleData = [
                'images' => [UploadedFile::fake()->image('photo.jpg')],
                'image_comments' => ['Test comment'],
                'title' => "Capsule with {$privacy} privacy",
                'description' => 'Test description',
                'time' => Carbon::now()->addDays(7)->toDateTimeString(),
                'vision' => 'Test vision',
                'privacy' => $privacy,
                'design' => 'default',
            ];

            $response = $this->postJson('/api/capsule/create', $capsuleData);

            $response->assertStatus(201)
                ->assertJson([
                    'privacy' => $privacy,
                ]);

            $this->assertDatabaseHas('capsules', [
                'user_id' => $this->user->id,
                'title' => "Capsule with {$privacy} privacy",
                'privacy' => $privacy,
            ]);
        }
    }
    public function test_it_fails_to_create_capsule_without_images()
    {
        $this->actingAs($this->user, 'sanctum');

        $invalidData = [
            'title' => 'Test Capsule',
            'description' => 'Test Description',
            'time' => Carbon::now()->addDays(7)->toDateTimeString(),
            'vision' => 'Test Vision',
            'privacy' => 'private',
            'design' => 'default',
        ];

        $response = $this->postJson('/api/capsule/create', $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        $this->assertDatabaseMissing('capsules', [
            'user_id' => $this->user->id,
            'title' => 'Test Capsule',
        ]);
    }

    public function test_it_handles_large_image_upload()
    {
        $this->actingAs($this->user, 'sanctum');

        $largeImage = UploadedFile::fake()->image('large_photo.jpg')->size(3000); // 3MB

        $capsuleData = [
            'images' => [$largeImage],
            'image_comments' => ['Large image'],
            'title' => 'Capsule with large image',
            'description' => 'Test description',
            'time' => Carbon::now()->addDays(7)->toDateTimeString(),
            'vision' => 'Test vision',
            'privacy' => 'private',
            'design' => 'default',
        ];

        $response = $this->postJson('/api/capsule/create', $capsuleData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
    }
}
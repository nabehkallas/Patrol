<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LandingContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Platform > Landing page: the visual editor's publish and media endpoints. */
class LandingEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_the_editor_or_publish(): void
    {
        $this->get(route('platform.landing.edit'))->assertRedirect(route('login'));
        $this->post(route('platform.landing.publish'), ['content' => ['sections' => []]])->assertRedirect(route('login'));
    }

    public function test_publishing_stores_a_cleaned_version_that_the_public_page_receives(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('platform.landing.publish'), [
            'content' => [
                'sections' => [
                    ['id' => 'features', 'visible' => true],
                    ['id' => 'hero', 'visible' => false],
                    ['id' => 'not-a-section', 'visible' => true],
                ],
                'text' => [
                    'en' => ['heroTitle1' => '  New headline  ', 'trust' => ['One', 'Two'], 'bad key!' => 'x'],
                    'ar' => ['heroTitle1' => 'عنوان جديد'],
                ],
                'video' => ['src' => 'https://evil.example/x.mp4', 'poster' => '/landing/demo-poster.jpg'],
                'slides' => [
                    ['id' => 's1', 'image_en' => '/landing/statistics-en.jpg', 'title_en' => 'Stats'],
                    ['id' => 's2', 'image_en' => 'javascript:alert(1)'],
                ],
                'showRoadmapSlide' => false,
            ],
        ])->assertRedirect(route('platform.landing.edit'));

        $saved = app(LandingContent::class)->published();

        $this->assertSame([['id' => 'features', 'visible' => true], ['id' => 'hero', 'visible' => false]], $saved['sections']);
        $this->assertSame('New headline', $saved['text']['en']['heroTitle1']);
        $this->assertArrayNotHasKey('bad key!', $saved['text']['en']);
        $this->assertNull($saved['video']['src'], 'external video URLs are dropped');
        $this->assertSame('/landing/demo-poster.jpg', $saved['video']['poster']);
        $this->assertCount(1, $saved['slides'], 'slides with an unsafe image are dropped');
        $this->assertFalse($saved['showRoadmapSlide']);

        auth()->logout();

        $this->get(route('home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->where('content.text.en.heroTitle1', 'New headline'));
    }

    public function test_uploaded_media_is_stored_and_served(): void
    {
        Storage::fake('landing');
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson(route('platform.landing.upload'), ['file' => UploadedFile::fake()->image('shot.png', 1280, 720)])
            ->assertOk();

        $url = $response->json('url');
        $this->assertMatchesRegularExpression('#^/landing-media/[A-Za-z0-9]{32}\.png$#', $url);
        Storage::disk('landing')->assertExists(basename($url));

        $this->get($url)->assertOk();
    }

    public function test_uploads_other_than_videos_and_images_are_refused(): void
    {
        Storage::fake('landing');
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->postJson(route('platform.landing.upload'), ['file' => UploadedFile::fake()->create('evil.php', 1, 'application/x-php')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk('landing')->files());
    }
}

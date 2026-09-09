<?php

namespace Tests\Feature;

use App\Http\Controllers\CalendarFeedController;
use Illuminate\Http\Request;
use Tests\TestCase;

class FrontendRoutesTest extends TestCase
{
    public function test_frontend_pages_serve_the_build_without_intercepting_api_or_assets(): void
    {
        $directory = sys_get_temp_dir().'/oscalendar-frontend-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $this->app->usePublicPath($directory);

        try {
            $this->get('/')->assertStatus(503);
            file_put_contents($directory.'/index.html', '<!doctype html><html><body>Frontend fixture</body></html>');

            foreach (['/', '/login', '/dashboard', '/profile', '/workplan', '/history', '/deviations', '/admin/users', '/admin/permissions'] as $page) {
                $response = $this->get($page)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
                $this->assertSame($directory.'/index.html', $response->baseResponse->getFile()->getPathname());
                $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
            }

            $this->getJson('/api/account')->assertUnauthorized();
            $this->getJson('/api/not-a-route')->assertNotFound();
            $this->get('/assets/missing.js')->assertNotFound();
            $this->get('/not-a-page')->assertNotFound();
            $this->post('/deviations')->assertStatus(405);

            $calendar = app('router')->getRoutes()->match(Request::create('/api/calendar/test-token.ics'));
            $this->assertSame(CalendarFeedController::class.'@show', $calendar->getActionName());
        } finally {
            if (is_file($directory.'/index.html')) {
                unlink($directory.'/index.html');
            }
            rmdir($directory);
        }
    }
}

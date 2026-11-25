<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MeteoReports
{
    public function configured(): bool
    {
        return $this->baseUrl() !== null
            && (string) config('services.meteo.login') !== ''
            && (string) config('services.meteo.password') !== '';
    }

    /** @param list<string> $codes */
    public function fetch(array $codes): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Meteo service is not configured.');
        }

        $codes = array_values(array_unique($codes));
        foreach ($codes as $code) {
            if (! preg_match('/\A[A-Z]{4}\z/', $code)) {
                throw new RuntimeException('Invalid ICAO station code.');
            }
        }
        if ($codes === []) {
            return [];
        }

        $key = 'meteo:session:'.hash('sha256', $this->baseUrl()."\0".config('services.meteo.login')."\0".config('services.meteo.password'));
        $session = $this->session($key);
        $responses = $this->requestReports($codes, $session);
        $expired = array_keys(array_filter($responses, fn ($response) => $response instanceof Response && $this->needsLogin($response)));
        if ($expired !== []) {
            try {
                $responses = array_replace($responses, $this->requestReports($expired, $this->session($key, $session)));
            } catch (RuntimeException $exception) {
                // Reports already returned by the source are still usable.
            }
        }

        $reports = [];
        foreach ($codes as $code) {
            $response = $responses[$code] ?? null;
            $reports[$code] = $response instanceof Response && $response->successful() ? $this->reportText($response->body(), $code) : null;
        }
        return $reports;
    }

    private function session(string $key, ?string $rejected = null): string
    {
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== $rejected) {
            return $cached;
        }

        $session = Cache::lock($key.':login', 10)->get(function () use ($key, $rejected) {
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== $rejected) {
                return $cached;
            }

            $cookies = new CookieJar();
            try {
                $response = Http::asForm()->connectTimeout(2)->timeout(3)->withoutRedirecting()
                    ->withOptions(['cookies' => $cookies])
                    ->post($this->baseUrl().'/', [
                        'login' => config('services.meteo.login'),
                        'pass' => config('services.meteo.password'),
                    ]);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Meteo login is unavailable.');
            }
            if ((! $response->successful() && ! $response->redirect()) || $cookies->toArray() === []) {
                throw new RuntimeException('Meteo login failed.');
            }

            $session = Crypt::encryptString(json_encode($cookies->toArray(), JSON_THROW_ON_ERROR));
            Cache::put($key, $session, now()->addHours(8));
            return $session;
        });
        if (! is_string($session)) {
            throw new RuntimeException('Meteo login is already in progress.');
        }
        return $session;
    }

    private function requestReports(array $codes, string $session): array
    {
        $cookies = json_decode(Crypt::decryptString($session), true, 512, JSON_THROW_ON_ERROR);
        return Http::pool(function (Pool $pool) use ($codes, $cookies) {
            foreach ($codes as $code) {
                $pool->as($code)->connectTimeout(2)->timeout(3)->withoutRedirecting()
                    ->withOptions(['cookies' => new CookieJar(false, $cookies)])
                    ->withHeaders(['Cache-Control' => 'no-cache'])
                    ->get($this->baseUrl().'/app/lib/bamd.php', ['query' => 'SA FT FC '.$code, '_' => microtime(true)]);
            }
        }, concurrency: count($codes));
    }

    private function needsLogin(Response $response): bool
    {
        return $response->redirect() || in_array($response->status(), [401, 403], true)
            || preg_match('/<input\b[^>]*\btype\s*=\s*["\x27]?password\b/i', $response->body()) === 1;
    }

    private function reportText(string $html, string $code): ?string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $html = preg_replace('~<br\s*/?>|</(?:pre|p|div)>~i', "\n", $html);
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $pattern = '/\b'.$code.'[ \t]+(?:\d{6}Z\b|NIL\b)/i';

        return preg_match($pattern, $text) === 1 ? $text : null;
    }

    private function baseUrl(): ?string
    {
        $base = rtrim((string) config('services.meteo.base_url'), '/');
        $parts = parse_url($base);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        return $base;
    }
}

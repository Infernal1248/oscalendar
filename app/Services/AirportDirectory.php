<?php

namespace App\Services;

class AirportDirectory
{
    public const MAX_QUERY_AIRPORTS = 10;

    private ?array $airports = null;
    private array $aliases = [];

    public function find(string $name): ?array
    {
        $this->load();
        $name = trim($name);
        $key = $this->normalize($name);
        // The source has both «БОДРУМ» and «Бодрум». Preserve exact matches.
        $matches = isset($this->airports[$name]) ? [$name] : ($this->aliases[$key] ?? []);

        return count($matches) === 1 ? $this->airports[reset($matches)] : null;
    }

    /** @return list<string> */
    public function splitInput(string $input): array
    {
        $input = trim($input);
        if ($this->find($input) !== null) {
            return [$input];
        }

        $separator = preg_match('/[,;\r\n]/', $input) === 1 ? '/\s*[,;\r\n]+\s*/u' : '/\s+/u';
        return preg_split($separator, $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public function formatRoute(?string $route, bool $compact): ?string
    {
        if ($route === null) {
            return null;
        }

        $parts = $this->splitRoute($route);
        for ($i = 0; $i < count($parts); $i += 2) {
            [$airport, $suffix] = $this->matchStop($parts[$i]);
            if ($airport !== null) {
                $parts[$i] = ($compact ? ($airport['crt'] ?? $airport['iata'] ?? $airport['icao'] ?? $airport['short_name'])
                    : $airport['name']).$suffix;
            }
        }

        return implode('', $parts);
    }

    /** @return list<array{label: string, airport: ?array}> */
    public function routeStops(?string $route): array
    {
        $stops = [];
        foreach ($this->splitRoute($route ?? '') as $i => $part) {
            if ($i % 2 === 0 && trim($part) !== '') {
                [$airport] = $this->matchStop($part);
                $stops[] = ['label' => trim($part), 'airport' => $airport];
            }
        }

        return $stops;
    }

    private function splitRoute(string $route): array
    {
        // Slashes in parentheses and in portal annotations do not separate airports.
        return preg_split('~(?:\([^()]*\)|\[[^\]]*\])(*SKIP)(*F)|(\s*/\s*|\s+[–—-]\s+|\s*→\s*)~u',
            $route, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$route];
    }

    private function matchStop(string $part): array
    {
        preg_match('/\A(.*?)(\s*\[.*)?\z/us', trim($part), $matches);
        $name = $matches[1] ?? $part;
        $annotation = $matches[2] ?? '';
        $airport = $this->find($name);
        if ($airport !== null) {
            return [$airport, $annotation];
        }

        // A terminal suffix is meaningful only if the remaining text is an airport.
        if (preg_match('/\A(.+)(-[A-ZА-ЯЁ0-9]{1,3})\z/iu', $name, $terminal)) {
            return [$this->find($terminal[1]), $terminal[2].$annotation];
        }

        return [null, ''];
    }

    private function load(): void
    {
        if ($this->airports !== null) {
            return;
        }

        $this->airports = json_decode(file_get_contents(resource_path('data/opensky-airports.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($this->airports as $shortName => &$airport) {
            $airport['short_name'] = (string) $shortName;
            preg_match('/\(([^()]+)\)$/u', $airport['name'], $localName);
            foreach ([$shortName, $airport['name'], $airport['crt'], $airport['iata'], $airport['icao'],
                $airport['icao_cyrillic'], $localName[1] ?? null] as $alias) {
                if ($alias !== null && $alias !== '') {
                    $this->aliases[$this->normalize((string) $alias)][$shortName] = $shortName;
                }
            }
        }
    }

    private function normalize(string $name): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($name)) ?? $name);
    }
}

<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

/**
 * ISO 639-2 bibliographic codes (MARC-style) from the Library of Congress list.
 *
 * @see https://www.loc.gov/standards/iso639-2/php/code_list.php
 */
class Iso6392LanguageCatalog
{
    /** @var array<string, string> lowercase bibliographic code => canonical bibliographic code */
    protected array $byBibliographic = [];

    /** @var array<string, string> lowercase terminology code => bibliographic code */
    protected array $byTerminology = [];

    /** @var array<string, string> lowercase ISO 639-1 code => bibliographic code */
    protected array $byIso6391 = [];

    /** @var array<string, string> normalized English name => bibliographic code */
    protected array $byEnglishName = [];

    /** @var array<string, string> custom alias => bibliographic code */
    protected array $aliases = [];

    public function __construct(?string $dataFile = null, ?string $aliasesFile = null)
    {
        $base = dirname(__DIR__, 2) . '/data';
        $this->loadIso6392File($dataFile ?? $base . '/iso639-2.txt');
        $this->loadAliases($aliasesFile ?? $base . '/language-aliases.json');
    }

    /**
     * Resolve a language label, code, or name to an ISO 639-2 bibliographic (MARC) code.
     */
    public function resolveBibliographic(string $input): ?string
    {
        $key = strtolower(trim($input));
        if ($key === '') {
            return null;
        }

        if (isset($this->aliases[$key])) {
            return $this->aliases[$key];
        }

        if (strlen($key) === 3 && isset($this->byBibliographic[$key])) {
            return $this->byBibliographic[$key];
        }

        if (isset($this->byTerminology[$key])) {
            return $this->byTerminology[$key];
        }

        if (strlen($key) === 2 && isset($this->byIso6391[$key])) {
            return $this->byIso6391[$key];
        }

        $englishKey = $this->normalizeEnglishName($key);
        if (isset($this->byEnglishName[$englishKey])) {
            return $this->byEnglishName[$englishKey];
        }

        return null;
    }

    protected function loadIso6392File(string $path): void
    {
        if (!is_readable($path)) {
            throw new \RuntimeException('ISO 639-2 data file not found: ' . $path);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Could not read ISO 639-2 data file: ' . $path);
        }

        foreach ($lines as $line) {
            $parts = explode('|', $line, 5);
            if (count($parts) < 4) {
                continue;
            }

            $bibliographic = strtolower(trim($parts[0]));
            $terminology = strtolower(trim($parts[1]));
            $iso6391 = strtolower(trim($parts[2]));
            $english = trim($parts[3]);

            $canonical = $bibliographic !== '' ? $bibliographic : ($terminology !== '' ? $terminology : null);
            if ($canonical === null) {
                continue;
            }

            if ($bibliographic !== '') {
                $this->byBibliographic[$bibliographic] = $bibliographic;
            }
            if ($terminology !== '') {
                $this->byTerminology[$terminology] = $canonical;
            }
            if ($iso6391 !== '') {
                $this->byIso6391[$iso6391] = $canonical;
            }
            if ($english !== '') {
                $this->registerEnglishNames($english, $canonical);
            }
        }
    }

    protected function registerEnglishNames(string $english, string $bibliographic): void
    {
        foreach (explode(';', $english) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $key = $this->normalizeEnglishName($part);
            if (!isset($this->byEnglishName[$key])) {
                $this->byEnglishName[$key] = $bibliographic;
            }
        }
    }

    protected function normalizeEnglishName(string $name): string
    {
        $name = strtolower(trim($name));
        return preg_replace('/\s+/', ' ', $name) ?? $name;
    }

    protected function loadAliases(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return;
        }
        foreach ($decoded as $alias => $code) {
            $aliasKey = strtolower(trim((string) $alias));
            $marc = strtolower(trim((string) $code));
            if ($aliasKey !== '' && strlen($marc) === 3) {
                $this->aliases[$aliasKey] = $marc;
            }
        }
    }
}

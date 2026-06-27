<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class ModuleSettings
{
    public const KEY_PREFIX = 'internet_archive_outbound_';

    public const DEFAULT_USER_AGENT = 'Omeka-InternetArchiveOutboundSync/1.0 (https://omeka.org/s)';

    protected $settings;

    public function __construct($settings)
    {
        $this->settings = $settings;
    }

    public function getOmekaSettings()
    {
        return $this->settings;
    }

    protected function key(string $name): string
    {
        return self::KEY_PREFIX . $name;
    }

    public function userAgent(): string
    {
        return self::valueAsString(
            $this->settings->get($this->key('user_agent')),
            self::DEFAULT_USER_AGENT
        ) ?: self::DEFAULT_USER_AGENT;
    }

    public function requestDelaySeconds(): float
    {
        return (float) $this->settings->get($this->key('request_delay_seconds'), 0.5);
    }

    public function chunkSize(): int
    {
        return max(1, (int) $this->settings->get($this->key('chunk_size'), 5));
    }

    public function defaultIaCollection(): string
    {
        return self::valueAsString($this->settings->get($this->key('default_ia_collection'), ''));
    }

    /**
     * Optional collection for Contribute publish uploads only (not metadata push).
     * Environment variable IA_PUBLISH_TEST_COLLECTION takes precedence over module settings.
     */
    public function publishTestCollectionOverride(): string
    {
        $env = getenv('IA_PUBLISH_TEST_COLLECTION');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return self::valueAsString($this->settings->get($this->key('publish_test_collection'), ''));
    }

    public function publishTestCollectionFromEnv(): bool
    {
        $env = getenv('IA_PUBLISH_TEST_COLLECTION');
        return is_string($env) && trim($env) !== '';
    }

    public function publishTestCollectionActive(): bool
    {
        return $this->publishTestCollectionOverride() !== '';
    }

    /**
     * Collection identifier used when publishing Contribute uploads.
     */
    public function publishIaCollection(): string
    {
        $override = $this->publishTestCollectionOverride();
        if ($override !== '') {
            return $override;
        }

        return $this->defaultIaCollection();
    }

    public function contributionsItemSetId(): int
    {
        return max(0, (int) $this->settings->get($this->key('contributions_item_set_id'), 0));
    }

    public function deleteStagingItemAfterPublish(): bool
    {
        $v = $this->settings->get($this->key('delete_staging_item_after_publish'), true);
        return $v !== false && $v !== '0' && $v !== 0;
    }

    public function identifierSuffix(): string
    {
        return self::valueAsString($this->settings->get($this->key('identifier_suffix'), ''));
    }

    public function defaultMediatype(): string
    {
        return self::valueAsString($this->settings->get($this->key('default_mediatype'), 'texts'));
    }

    public function allowLocalFileDeletion(): bool
    {
        $v = $this->settings->get($this->key('allow_local_file_deletion'), true);
        return $v !== false && $v !== '0' && $v !== 0;
    }

    public function dryRunDefault(): bool
    {
        $v = $this->settings->get($this->key('dry_run_default'), false);
        return $v === true || $v === '1' || $v === 1;
    }

    public function metadataPushEnabled(): bool
    {
        $v = $this->settings->get($this->key('metadata_push_enabled'), false);
        return $v === true || $v === '1' || $v === 1;
    }

    public function typeConfirmThreshold(): int
    {
        return max(1, (int) $this->settings->get($this->key('type_confirm_threshold'), 10));
    }

    public function iaS3AccessKey(): string
    {
        $env = getenv('IA_S3_ACCESS_KEY');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        return self::valueAsString($this->settings->get($this->key('ia_s3_access_key'), ''));
    }

    public function iaS3SecretKey(): string
    {
        $env = getenv('IA_S3_SECRET_KEY');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        return self::valueAsString($this->settings->get($this->key('ia_s3_secret_key'), ''));
    }

    public function hasCredentials(): bool
    {
        return $this->iaS3AccessKey() !== '' && $this->iaS3SecretKey() !== '';
    }

    public function credentialsFromEnv(): bool
    {
        $access = getenv('IA_S3_ACCESS_KEY');
        $secret = getenv('IA_S3_SECRET_KEY');
        return is_string($access) && trim($access) !== ''
            && is_string($secret) && trim($secret) !== '';
    }

    public function hasStoredAccessKey(): bool
    {
        return self::valueAsString($this->settings->get($this->key('ia_s3_access_key'), '')) !== '';
    }

    public function hasStoredSecretKey(): bool
    {
        return self::valueAsString($this->settings->get($this->key('ia_s3_secret_key'), '')) !== '';
    }

    public function hasStoredCredentialsInDatabase(): bool
    {
        return $this->hasStoredAccessKey() && $this->hasStoredSecretKey();
    }

    public function storedAccessKeyHint(): string
    {
        $key = self::valueAsString($this->settings->get($this->key('ia_s3_access_key'), ''));
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 4) {
            return '••••';
        }
        return '••••' . substr($key, -4);
    }

    /**
     * @return array<int, string> item_set_id => ia_collection_id
     */
    public function itemSetCollectionMap(): array
    {
        $raw = $this->settings->get($this->key('item_set_collection_map'), '{}');
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $itemSetId => $collectionId) {
            $itemSetId = (int) $itemSetId;
            $collectionId = trim((string) $collectionId);
            if ($itemSetId > 0 && $collectionId !== '') {
                $out[$itemSetId] = $collectionId;
            }
        }
        return $out;
    }

    /**
     * @return array<string, array{bcp47?: string, marc?: string, label?: string}>
     */
    public function iaLanguageMap(): array
    {
        $defaults = [
            'en' => ['bcp47' => 'en', 'marc' => 'eng', 'label' => 'English'],
            'eng' => ['bcp47' => 'en', 'marc' => 'eng', 'label' => 'English'],
            'english' => ['bcp47' => 'en', 'marc' => 'eng', 'label' => 'English'],
            'geo' => ['bcp47' => 'ka', 'marc' => 'geo', 'label' => 'Georgian'],
            'ka' => ['bcp47' => 'ka', 'marc' => 'geo', 'label' => 'Georgian'],
            'georgian' => ['bcp47' => 'ka', 'marc' => 'geo', 'label' => 'Georgian'],
            'ru' => ['bcp47' => 'ru', 'marc' => 'rus', 'label' => 'Russian'],
            'rus' => ['bcp47' => 'ru', 'marc' => 'rus', 'label' => 'Russian'],
            'russian' => ['bcp47' => 'ru', 'marc' => 'rus', 'label' => 'Russian'],
        ];
        $raw = $this->settings->get($this->key('ia_language_map'));
        if (!$raw) {
            return $defaults;
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        return array_merge($defaults, $decoded);
    }

    public function connectionTestPassed(): bool
    {
        return (bool) $this->settings->get($this->key('connection_test_passed'), false);
    }

    public function setConnectionTestPassed(bool $passed): void
    {
        $this->settings->set($this->key('connection_test_passed'), $passed);
    }

    public static function valueAsString($value, string $default = ''): string
    {
        if ($value === null || is_array($value)) {
            return $default;
        }
        return trim((string) $value);
    }

    public static function defaultInstallSettings(): array
    {
        $p = self::KEY_PREFIX;
        return [
            $p . 'user_agent' => self::DEFAULT_USER_AGENT,
            $p . 'request_delay_seconds' => 0.5,
            $p . 'chunk_size' => 5,
            $p . 'default_ia_collection' => '',
            $p . 'publish_test_collection' => '',
            $p . 'contributions_item_set_id' => 0,
            $p . 'delete_staging_item_after_publish' => true,
            $p . 'identifier_suffix' => '',
            $p . 'default_mediatype' => 'texts',
            $p . 'allow_local_file_deletion' => true,
            $p . 'dry_run_default' => false,
            $p . 'metadata_push_enabled' => false,
            $p . 'type_confirm_threshold' => 10,
            $p . 'ia_s3_access_key' => '',
            $p . 'ia_s3_secret_key' => '',
            $p . 'item_set_collection_map' => json_encode([]),
            $p . 'connection_test_passed' => false,
            $p . 'ia_language_map' => json_encode([
                'en' => ['bcp47' => 'en', 'marc' => 'eng', 'label' => 'English'],
                'eng' => ['bcp47' => 'en', 'marc' => 'eng', 'label' => 'English'],
                'geo' => ['bcp47' => 'ka', 'marc' => 'geo', 'label' => 'Georgian'],
                'ka' => ['bcp47' => 'ka', 'marc' => 'geo', 'label' => 'Georgian'],
                'ru' => ['bcp47' => 'ru', 'marc' => 'rus', 'label' => 'Russian'],
                'rus' => ['bcp47' => 'ru', 'marc' => 'rus', 'label' => 'Russian'],
            ]),
        ];
    }
}

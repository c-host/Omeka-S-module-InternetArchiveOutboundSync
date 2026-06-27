<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Form;

use InternetArchiveOutboundSync\Service\ModuleSettings;
use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    protected ?ModuleSettings $moduleSettings = null;

    protected bool $initialized = false;

    /** @var array<int|string, string> */
    protected array $itemSetOptions = [];

    public function setItemSetOptions(array $options): void
    {
        $this->itemSetOptions = $options;
        if ($this->initialized && $this->has('contributions_item_set_id')) {
            $this->get('contributions_item_set_id')->setValueOptions($options);
        }
    }

    public function init(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        $this->setAttribute('id', 'internet-archive-outbound-config');

        $this->add([
            'name' => 'change_credentials',
            'type' => Element\Hidden::class,
            'attributes' => ['value' => '0', 'id' => 'ia_outbound_change_credentials'],
        ]);
        $this->add([
            'name' => 'ia_s3_access_key',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'S3 access key', // @translate
                'info' => 'Or set IA_S3_ACCESS_KEY in environment.', // @translate
            ],
            'attributes' => [
                'class' => 'ia-outbound-credential-field',
                'autocomplete' => 'off',
            ],
        ]);
        $this->add([
            'name' => 'ia_s3_secret_key',
            'type' => Element\Password::class,
            'options' => [
                'label' => 'S3 secret key', // @translate
                'info' => 'Leave blank to keep the existing secret when changing other settings.', // @translate
            ],
            'attributes' => [
                'class' => 'ia-outbound-credential-field',
                'autocomplete' => 'new-password',
            ],
        ]);
        $this->add([
            'name' => 'default_ia_collection',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Default IA collection', // @translate
                'info' => 'Internet Archive collection identifier for new uploads and metadata push mapping.', // @translate
            ],
        ]);
        $this->add([
            'name' => 'publish_test_collection',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Publish test collection override', // @translate
                'info' => 'When set, user-submitted item uploads go here instead of the default collection (e.g. test_collection). Metadata push is unchanged. Or set IA_PUBLISH_TEST_COLLECTION in the environment.', // @translate
            ],
            'attributes' => [
                'placeholder' => 'test_collection',
            ],
        ]);
        $this->add([
            'name' => 'contributions_item_set_id',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Contributions item set', // @translate
                'info' => 'Omeka item set for Collecting submissions (e.g. Internet Archive Contributions). Used by Publish user-submitted items.', // @translate
                'empty_option' => '-- Select item set --', // @translate
            ],
        ]);
        $this->add([
            'name' => 'item_set_collection_map_json',
            'type' => Element\Hidden::class,
            'attributes' => ['id' => 'item_set_collection_map_json'],
        ]);
        $this->add([
            'name' => 'identifier_suffix',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'New item identifier suffix', // @translate
                'info' => 'Optional suffix appended after the title slug when publishing new items (e.g. ia-logo-mysite when suffix is mysite).', // @translate
            ],
            'attributes' => [
                'placeholder' => 'mysite',
            ],
        ]);
        $this->add([
            'name' => 'default_mediatype',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Default mediatype', // @translate
                'value_options' => [
                    'texts' => 'texts',
                    'image' => 'image',
                    'audio' => 'audio',
                    'movies' => 'movies',
                ],
            ],
        ]);
        $this->add([
            'name' => 'chunk_size',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Items per background job', // @translate
                'value_options' => [
                    '1' => '1',
                    '3' => '3',
                    '5' => '5 (recommended)',
                    '10' => '10',
                ],
            ],
        ]);
        $this->add([
            'name' => 'request_delay_seconds',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Pause between items (seconds)', // @translate
                'value_options' => [
                    '0' => 'No delay',
                    '0_5' => '0.5 seconds (recommended)',
                    '1' => '1 second',
                    '2' => '2 seconds',
                ],
            ],
        ]);
        $this->add([
            'name' => 'type_confirm_threshold',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Type-to-confirm threshold', // @translate
                'info' => 'Users must type PUSH when pushing this many or more items in one batch.', // @translate
            ],
            'attributes' => ['inputmode' => 'numeric', 'pattern' => '[0-9]*'],
        ]);
        $this->add([
            'name' => 'allow_local_file_deletion',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Delete local upload files after IA verify', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
        ]);
        $this->add([
            'name' => 'delete_staging_item_after_publish',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Delete staging Omeka item after successful user-submitted upload', // @translate
                'info' => 'When enabled, Collecting staging items are removed from Omeka after verified IA upload (recommended).', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
        ]);
        $this->add([
            'name' => 'metadata_push_enabled',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable metadata push to Internet Archive', // @translate
                'info' => 'When disabled, preview still works but live pushes are blocked.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
        ]);

        if ($this->itemSetOptions !== [] && $this->has('contributions_item_set_id')) {
            $this->get('contributions_item_set_id')->setValueOptions($this->itemSetOptions);
        }

        if ($this->moduleSettings) {
            $this->loadFromModuleSettings();
        }
    }

    public function setModuleSettings(ModuleSettings $settings): void
    {
        $this->moduleSettings = $settings;
        if ($this->initialized) {
            $this->loadFromModuleSettings();
        }
    }

    protected function loadFromModuleSettings(): void
    {
        $s = $this->moduleSettings;
        $map = $s->itemSetCollectionMap();
        $mapJson = json_encode((object) array_map('strval', $map), JSON_PRETTY_PRINT);
        if ($mapJson === false) {
            $mapJson = '{}';
        }

        $delay = (string) $s->requestDelaySeconds();
        if ($delay === '0.5' || $delay === '0.50') {
            $delay = '0_5';
        }

        $values = [
            'change_credentials' => '0',
            'default_ia_collection' => $s->defaultIaCollection(),
            'publish_test_collection' => $s->publishTestCollectionFromEnv()
                ? ''
                : $s->publishTestCollectionOverride(),
            'item_set_collection_map_json' => $mapJson,
            'identifier_suffix' => $s->identifierSuffix(),
            'default_mediatype' => $s->defaultMediatype(),
            'chunk_size' => (string) $s->chunkSize(),
            'request_delay_seconds' => $delay,
            'type_confirm_threshold' => (string) $s->typeConfirmThreshold(),
            'allow_local_file_deletion' => $s->allowLocalFileDeletion() ? '1' : '0',
            'delete_staging_item_after_publish' => $s->deleteStagingItemAfterPublish() ? '1' : '0',
            'metadata_push_enabled' => $s->metadataPushEnabled() ? '1' : '0',
            'contributions_item_set_id' => (string) $s->contributionsItemSetId(),
        ];

        if (!$s->credentialsFromEnv() && !$s->hasStoredCredentialsInDatabase()) {
            $values['ia_s3_access_key'] = $s->hasStoredAccessKey()
                ? self::valueAsString($s->getOmekaSettings()->get(ModuleSettings::KEY_PREFIX . 'ia_s3_access_key'))
                : '';
        }

        $this->populateValues($values);
    }

    private static function valueAsString($value, string $default = ''): string
    {
        return ModuleSettings::valueAsString($value, $default);
    }

    public function save(): void
    {
        $data = $this->getData();
        $omeka = $this->moduleSettings->getOmekaSettings();
        $p = ModuleSettings::KEY_PREFIX;

        if (!$this->moduleSettings->credentialsFromEnv()) {
            $changeCredentials = ($data['change_credentials'] ?? '0') === '1';
            $hasStored = $this->moduleSettings->hasStoredCredentialsInDatabase();
            if (!$hasStored || $changeCredentials) {
                $access = trim((string) ($data['ia_s3_access_key'] ?? ''));
                if ($access !== '') {
                    $omeka->set($p . 'ia_s3_access_key', $access);
                }
                $secret = trim((string) ($data['ia_s3_secret_key'] ?? ''));
                if ($secret !== '') {
                    $omeka->set($p . 'ia_s3_secret_key', $secret);
                }
            }
        }

        $omeka->set($p . 'default_ia_collection', trim((string) ($data['default_ia_collection'] ?? '')));

        if (!$this->moduleSettings->publishTestCollectionFromEnv()) {
            $omeka->set(
                $p . 'publish_test_collection',
                trim((string) ($data['publish_test_collection'] ?? ''))
            );
        }

        $mapJson = trim((string) ($data['item_set_collection_map_json'] ?? '{}'));
        $decoded = json_decode($mapJson !== '' ? $mapJson : '{}', true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Item set mapping must be valid JSON.');
        }
        $map = [];
        foreach ($decoded as $k => $v) {
            $map[(int) $k] = (string) $v;
        }
        $omeka->set($p . 'item_set_collection_map', json_encode($map));

        $omeka->set($p . 'identifier_suffix', trim(trim((string) ($data['identifier_suffix'] ?? '')), '-'));
        $omeka->set($p . 'default_mediatype', (string) ($data['default_mediatype'] ?? 'texts'));
        $omeka->set($p . 'chunk_size', max(1, (int) ($data['chunk_size'] ?? 5)));

        $delayKey = (string) ($data['request_delay_seconds'] ?? '0_5');
        $delay = $delayKey === '0_5' ? 0.5 : (float) $delayKey;
        $omeka->set($p . 'request_delay_seconds', $delay);

        $omeka->set($p . 'type_confirm_threshold', max(1, (int) ($data['type_confirm_threshold'] ?? 10)));
        $omeka->set($p . 'allow_local_file_deletion', ($data['allow_local_file_deletion'] ?? '0') === '1');
        $omeka->set(
            $p . 'delete_staging_item_after_publish',
            ($data['delete_staging_item_after_publish'] ?? '1') === '1'
        );
        $omeka->set($p . 'contributions_item_set_id', max(0, (int) ($data['contributions_item_set_id'] ?? 0)));
        $omeka->set($p . 'metadata_push_enabled', ($data['metadata_push_enabled'] ?? '0') === '1');
    }
}

<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class PublishRevisionsForm extends Form
{
    public function init(): void
    {
        $this->setAttribute('id', 'ia-outbound-publish-revisions-form');

        $this->add([
            'name' => 'queue_ids',
            'type' => Element\MultiCheckbox::class,
            'options' => ['label' => 'Metadata revisions'], // @translate
        ]);

        $this->add([
            'name' => 'preview_token',
            'type' => Element\Hidden::class,
            'attributes' => ['id' => 'publish_revisions_preview_token'],
        ]);

        $this->add([
            'name' => 'acknowledge',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'I understand this will overwrite Internet Archive metadata and cannot be undone from Omeka.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
        ]);

        $this->add([
            'name' => 'confirm_text',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Type PUSH to confirm large batch', // @translate
            ],
            'attributes' => ['id' => 'publish_revisions_confirm_text', 'autocomplete' => 'off'],
        ]);

        $this->add([
            'name' => 'submit_preview',
            'type' => Element\Submit::class,
            'attributes' => ['value' => 'Preview changes'], // @translate
        ]);

        $this->add([
            'name' => 'submit_push',
            'type' => Element\Submit::class,
            'attributes' => ['value' => 'Push to Internet Archive'], // @translate
        ]);
    }
}

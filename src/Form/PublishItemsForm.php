<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class PublishItemsForm extends Form
{
    public function init(): void
    {
        $this->setAttribute('id', 'ia-outbound-publish-items-form');

        $this->add([
            'name' => 'item_ids',
            'type' => Element\MultiCheckbox::class,
            'options' => ['label' => 'User-submitted items'], // @translate
            'attributes' => ['class' => 'ia-publish-items-checkboxes'],
        ]);

        $this->add([
            'name' => 'preview_token',
            'type' => Element\Hidden::class,
            'attributes' => ['id' => 'publish_preview_token'],
        ]);

        $this->add([
            'name' => 'acknowledge',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'I understand this will publish to Internet Archive permanently and cannot be undone from Omeka.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
        ]);

        $this->add([
            'name' => 'confirm_text',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Type PUBLISH to confirm large batch', // @translate
            ],
            'attributes' => ['id' => 'publish_confirm_text', 'autocomplete' => 'off'],
        ]);

        $this->add([
            'name' => 'submit_preview',
            'type' => Element\Submit::class,
            'attributes' => ['value' => 'Preview upload'], // @translate
        ]);

        $this->add([
            'name' => 'submit_publish',
            'type' => Element\Submit::class,
            'attributes' => ['value' => 'Publish to Internet Archive'], // @translate
        ]);
    }
}

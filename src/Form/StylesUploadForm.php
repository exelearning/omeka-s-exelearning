<?php
declare(strict_types=1);

namespace ExeLearning\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Upload form for eXeLearning style ZIP packages.
 *
 * Uses a native multi-file <input type="file" multiple> which renders
 * consistently across Omeka admin themes without requiring the full
 * Omeka Media entity upload pipeline.
 */
class StylesUploadForm extends Form
{
    public function init(): void
    {
        $this->setAttribute('enctype', 'multipart/form-data');
        $this->setAttribute('method', 'post');

        $this->add([
            'name' => 'styles_zip',
            'type' => Element\File::class,
            'options' => [
                'label' => 'Style ZIP package(s)', // @translate
                'info'  => 'Select one or more .zip files containing a valid config.xml.', // @translate
            ],
            'attributes' => [
                'multiple' => 'multiple',
                'accept'   => '.zip,application/zip,application/x-zip-compressed',
                'required' => true,
                'id'       => 'styles_zip',
            ],
        ]);

        $this->add([
            'name' => 'csrf',
            'type' => Element\Csrf::class,
            'options' => [
                'csrf_options' => [
                    'timeout' => 3600,
                ],
            ],
        ]);

        $this->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'value' => 'Upload styles', // @translate
                'class' => 'button',
            ],
        ]);
    }
}

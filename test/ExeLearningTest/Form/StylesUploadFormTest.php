<?php
declare(strict_types=1);

namespace ExeLearningTest\Form;

use ExeLearning\Form\StylesUploadForm;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ExeLearning\Form\StylesUploadForm
 */
class StylesUploadFormTest extends TestCase
{
    public function testInitRegistersFileCsrfAndSubmit(): void
    {
        $form = new StylesUploadForm('styles_upload');
        $form->init();

        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertSame('multipart/form-data', $form->getAttribute('enctype'));

        $this->assertTrue($form->has('styles_zip'));
        $this->assertTrue($form->has('csrf'));
        $this->assertTrue($form->has('submit'));

        $fileElement = $form->get('styles_zip');
        $this->assertSame('multiple', $fileElement->getAttribute('multiple'));
        $this->assertStringContainsString('.zip', $fileElement->getAttribute('accept'));
        $this->assertSame('styles_zip', $fileElement->getAttribute('id'));
        $this->assertTrue((bool) $fileElement->getAttribute('required'));
    }

    public function testSubmitElementIsRegisteredWithButtonClass(): void
    {
        $form = new StylesUploadForm('styles_upload');
        $form->init();
        $submit = $form->get('submit');
        $this->assertSame('button', $submit->getAttribute('class'));
    }
}

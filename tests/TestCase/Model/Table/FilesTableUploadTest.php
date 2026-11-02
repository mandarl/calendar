<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\TestCase\Model\ModelDbTestCase;
use Laminas\Diactoros\UploadedFile;

/**
 * FilesTable with Upload 8: a PSR-7 uploaded file saved through the table must land under
 * webroot/files/{event_id}/ with its content intact. The uploaded file and the moved file are
 * removed again, and the row is rolled back by ModelDbTestCase, so the working copy is clean.
 */
class FilesTableUploadTest extends ModelDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The 'upload.file' column type is registered by UploadPlugin::bootstrap(), which the
        // production Application runs but PHPUnit's bootstrap does not; without it the marshaller
        // falls back to StringType and an UploadedFile cannot be marshalled at all.
        $this->loadPlugins(['Josegonzalez/Upload']);
    }

    public function testSaveMovesUploadedFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cake5b-upload');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'hello upload');
        $uploaded = new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'hello.txt', 'text/plain');

        $t = $this->table('Files');
        $entity = $t->newEntity(['event_id' => '1001', 'file' => $uploaded, 'private' => false]);
        $this->assertSame([], $entity->getErrors());
        $saved = $t->save($entity);
        $this->assertNotFalse($saved, 'Files save failed: ' . json_encode($entity->getErrors()));

        $dest = WWW_ROOT . 'files' . DS . '1001' . DS . 'hello.txt';
        try {
            $this->assertFileExists($dest);
            $this->assertSame('hello upload', file_get_contents($dest));
        } finally {
            if (file_exists($dest)) {
                unlink($dest);
            }
            if (is_dir(WWW_ROOT . 'files' . DS . '1001') && count(scandir(WWW_ROOT . 'files' . DS . '1001')) === 2) {
                rmdir(WWW_ROOT . 'files' . DS . '1001');
            }
            if (is_dir(WWW_ROOT . 'files') && count(scandir(WWW_ROOT . 'files')) === 2) {
                rmdir(WWW_ROOT . 'files');
            }
        }
    }
}


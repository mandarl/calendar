<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

/**
 * Files::delete (the only Files action): it removes the file row, the same
 * file's rows on the continuation events of the given event, and the physical
 * file itself once no file row references that name any more.
 */
class FilesControllerTest extends CalendarAdminTestSupport
{
    use EventsSeedTrait;

    private string $dir = 'webroot/files/ahl-del-test/';
    private string $file = 'shared.pdf';
    private string $diskPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskPath = WWW_ROOT . 'files/ahl-del-test/' . $this->file;
        $dir = dirname($this->diskPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->diskPath, 'test file content');
    }

    protected function tearDown(): void
    {
        if ($this->diskPath !== '' && is_file($this->diskPath)) {
            unlink($this->diskPath);
        }
        $dir = WWW_ROOT . 'files/ahl-del-test';
        if (is_dir($dir)) {
            rmdir($dir);
        }
        parent::tearDown();
    }

    /**
     * A primary event, two of its continuations, and a file row on each
     * continuation with the same file name and directory.
     *
     * @return array{primary: \Cake\Datasource\EntityInterface, first: \Cake\Datasource\EntityInterface, second: \Cake\Datasource\EntityInterface}
     */
    private function seedContinuationFiles(): array
    {
        $primary = $this->seedEvent(['name' => 'Primary Event']);
        $first = $this->seedEvent(['name' => 'Continuation One', 'part_of_id' => $primary->id]);
        $second = $this->seedEvent(['name' => 'Continuation Two', 'part_of_id' => $primary->id]);
        $fileOne = $this->make('Files', [
            'file' => $this->file, 'dir' => $this->dir, 'type' => 'application/pdf',
            'event_id' => $first->id, 'private' => 0,
        ]);
        $fileTwo = $this->make('Files', [
            'file' => $this->file, 'dir' => $this->dir, 'type' => 'application/pdf',
            'event_id' => $second->id, 'private' => 0,
        ]);

        return ['primary' => $primary, 'first' => $fileOne, 'second' => $fileTwo];
    }

    public function testDeleteRemovesContinuationRowsAndPhysicalFile(): void
    {
        $seeded = $this->seedContinuationFiles();
        $this->assertFileExists($this->diskPath);
        $this->loginAs('Calendar Admins');
        $this->post('/files/delete/' . $seeded['first']->id . '/' . $seeded['primary']->id);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/files');
        $this->assertSame(0, $this->countRows('Files', ['id' => $seeded['first']->id]));
        $this->assertSame(0, $this->countRows('Files', ['id' => $seeded['second']->id]));
        $this->assertFileDoesNotExist($this->diskPath);
    }

    public function testDeleteKeepsPhysicalFileWhileAnotherEventReferencesIt(): void
    {
        $seeded = $this->seedContinuationFiles();
        $other = $this->seedEvent(['name' => 'Unrelated Event']);
        $this->make('Files', [
            'file' => $this->file, 'dir' => $this->dir, 'type' => 'application/pdf',
            'event_id' => $other->id, 'private' => 0,
        ]);
        $this->loginAs('Calendar Admins');
        $this->post('/files/delete/' . $seeded['first']->id . '/' . $seeded['primary']->id);
        $this->assertResponseCode(302);
        $this->assertSame(1, $this->countRows('Files', ['file' => $this->file]));
        $this->assertFileExists($this->diskPath);
    }

    public function testDeleteWithGetDoesNotDelete(): void
    {
        $seeded = $this->seedContinuationFiles();
        $this->loginAs('Calendar Admins');
        $this->get('/files/delete/' . $seeded['first']->id . '/' . $seeded['primary']->id);
        $this->assertNotSame(200, $this->_response->getStatusCode());
        $this->assertSame(1, $this->countRows('Files', ['id' => $seeded['first']->id]));
    }

    public function testDeleteIsDeniedForOtherRoles(): void
    {
        $seeded = $this->seedContinuationFiles();
        foreach (self::roles() as $role => $groups) {
            if ($role === 'calendarAdmin') {
                continue;
            }
            $this->cleanup();
            $this->loginAsRole($role);
            $this->post('/files/delete/' . $seeded['first']->id . '/' . $seeded['primary']->id);
            $this->assertDenied($role);
        }
        $this->assertSame(1, $this->countRows('Files', ['id' => $seeded['first']->id]));
        $this->assertFileExists($this->diskPath);
    }

    public function cleanup(): void
    {
        parent::cleanup();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
    }
}

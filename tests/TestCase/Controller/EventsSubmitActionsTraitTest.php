<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\Concern\EventsSubmitActionsTrait;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Marshal logic of EventsSubmitActionsTrait, exercised without a database,
 * HTTP stack, Crud or auth middleware: the stub below supplies only what
 * __constructPostForMarshal() / __resetCategorySplits() touch (request,
 * currentUser/currentUsername, fetchTable('Contacts')).
 *
 * The continued-events, save and mail paths are covered by the differential
 * HTTP runs in calendar-cake5-c-CHANGES.md (section d), not here: they need
 * a live stack (validation reads Configurations, _afterCreate mails).
 */
class EventsSubmitMarshalStub
{
    use EventsSubmitActionsTrait;

    public ServerRequest $request;

    public function __construct(
        array $data,
        private array $identity,
        private object $contactsTable,
        string $method = 'POST',
    ) {
        $this->request = (new ServerRequest())->withMethod($method)->withParsedBody($data);
    }

    public function currentUser(): ?array
    {
        return $this->identity;
    }

    public function currentUsername(): ?string
    {
        return $this->identity['samaccountname'] ?? null;
    }

    public function fetchTable(string $name): object
    {
        return $this->contactsTable;
    }

    public function setRequest(ServerRequest $request): void
    {
        $this->request = $request;
    }

    public function postedData(): array
    {
        return $this->request->getData() ?? [];
    }
}

class FakeContactsTable
{
    public function get(int $id): object
    {
        return (object)[
            'id' => $id,
            'name' => 'Test Instructor A',
            'email' => 'instructor.a@example.test',
            'phone' => '555-0100',
            'w9_on_file' => true,
        ];
    }
}

class EventsSubmitActionsTraitTest extends TestCase
{
    private function stub(array $data, string $method = 'POST'): EventsSubmitMarshalStub
    {
        return new EventsSubmitMarshalStub(
            $data,
            ['samaccountname' => 'user1', 'contact_id' => 7, 'contact_error' => false, 'blacklisted' => false],
            new FakeContactsTable(),
            $method,
        );
    }

    private function marshal(EventsSubmitMarshalStub $stub, string $mode): array
    {
        $ref = new \ReflectionMethod($stub, '__constructPostForMarshal');
        $ref->setAccessible(true);
        $ref->invoke($stub, $mode);

        return $stub->postedData();
    }

    private function resetSplits(EventsSubmitMarshalStub $stub): array
    {
        $ref = new \ReflectionMethod($stub, '__resetCategorySplits');
        $ref->setAccessible(true);
        $ref->invoke($stub);

        return $stub->postedData();
    }

    public function testAddSetsCreatedByAndResolvesNonSponsoredContactFromIdentity(): void
    {
        $out = $this->marshal($this->stub([
            'sponsored' => 0,
            'contact' => ['name' => 'Typed Name', 'email' => 'typed@example.test', 'phone' => '555-0199'],
            'name' => 'Fixture: Free Open Class',
        ]), 'add');

        $this->assertSame('user1', $out['created_by']);
        $this->assertSame(7, $out['contact_id']);
        $this->assertSame('Test Instructor A', $out['contact']['name']);
        $this->assertSame('instructor.a@example.test', $out['contact']['email']);
        $this->assertTrue($out['contact']['w9_on_file']);
    }

    public function testAddKeepsSponsoredContactLookupAndDropsEmptyContactId(): void
    {
        $out = $this->marshal($this->stub([
            'sponsored' => 1,
            'contact_id' => 7,
            'contact' => ['name' => 'Typed Name'],
        ]), 'add');
        $this->assertSame(7, $out['contact_id']);
        $this->assertSame('Test Instructor A', $out['contact']['name']);

        $out = $this->marshal($this->stub(['sponsored' => 1, 'contact_id' => '']), 'add');
        $this->assertArrayNotHasKey('contact_id', $out);
    }

    public function testAddDropsEmptyContactAndHonorariumWhenNotRequested(): void
    {
        $out = $this->marshal($this->stub([
            'sponsored' => 1,
            'contact' => [],
            'request_honorarium' => 0,
            'honorarium' => ['committee_id' => 3],
        ]), 'add');
        $this->assertArrayNotHasKey('contact', $out);
        $this->assertArrayNotHasKey('honorarium', $out);

        $out = $this->marshal($this->stub([
            'sponsored' => 1,
            'request_honorarium' => 1,
            'honorarium' => ['committee_id' => 3],
        ]), 'add');
        $this->assertSame(['committee_id' => 3], $out['honorarium']);
    }

    public function testAddSetsMembersOnlyForPrerequisiteAndMergesCategories(): void
    {
        $out = $this->marshal($this->stub([
            'requires_prerequisite_id' => 5,
            'categories' => ['_ids' => [1]],
            'optional_categories' => ['_ids' => [3, 4]],
        ]), 'add');
        $this->assertSame(1, $out['members_only']);
        $this->assertSame([1, 3, 4], $out['categories']['_ids']);
    }

    public function testAddDropsEmptyUploadsButKeepsRealOnes(): void
    {
        $emptyFile = $this->createMock(UploadedFileInterface::class);
        $emptyFile->method('getClientFilename')->willReturn('');
        $realFile = $this->createMock(UploadedFileInterface::class);
        $realFile->method('getClientFilename')->willReturn('handout.pdf');

        $out = $this->marshal($this->stub([
            // A name keeps the contact array itself alive: with only an empty w9,
            // marshal also drops the now-empty contact (same as Cake 3).
            'contact' => ['name' => 'Someone', 'w9' => ['file' => $emptyFile]],
            'files' => [
                ['file' => $emptyFile, 'private' => 0],
                ['file' => $realFile, 'private' => 1],
                ['file' => ['name' => '']],
            ],
        ]), 'add');
        $this->assertArrayNotHasKey('w9', $out['contact']);
        $this->assertCount(1, $out['files']);
        $this->assertSame($realFile, $out['files'][1]['file']);
    }

    public function testEditDoesNotSetCreatedByOrTouchContactButStillMergesCategories(): void
    {
        $out = $this->marshal($this->stub([
            'contact' => ['name' => 'Left Alone'],
            'categories' => ['_ids' => [2]],
            'optional_categories' => ['_ids' => [5]],
        ]), 'edit');
        $this->assertArrayNotHasKey('created_by', $out);
        $this->assertSame('Left Alone', $out['contact']['name']);
        $this->assertSame([2, 5], $out['categories']['_ids']);
    }

    public function testGetRequestLeavesDataUntouched(): void
    {
        $data = ['name' => 'Untouched'];
        $out = $this->marshal($this->stub($data, 'GET'), 'add');
        $this->assertSame($data, $out);
    }

    public function testResetCategorySplitsRestoresSingleCategory(): void
    {
        $out = $this->resetSplits($this->stub([
            'categories' => ['_ids' => [1, 3, 4]],
            'optional_categories' => ['_ids' => [3, 4]],
        ]));
        $this->assertSame(1, $out['categories']['_ids']);
    }

    public function testResetCategorySplitsWithoutCategoriesDoesNotCrash(): void
    {
        $out = $this->resetSplits($this->stub([
            'optional_categories' => ['_ids' => [3]],
        ]));
        $this->assertArrayNotHasKey('categories', $out);
    }
}

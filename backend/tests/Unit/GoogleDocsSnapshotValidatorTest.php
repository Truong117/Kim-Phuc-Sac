<?php

namespace Tests\Unit;

use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsSnapshotValidator;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsSourceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleDocsSnapshotValidatorTest extends TestCase
{
    public function test_valid_empty_tab_is_accepted_without_inventing_passages(): void
    {
        $validated = (new GoogleDocsSnapshotValidator)->validate(
            $this->snapshot([]),
            'document-safe-1234567890',
        );

        $this->assertSame(0, $validated['passage_count']);
        $this->assertSame(0, $validated['character_count']);
        $this->assertSame([], $validated['passages']);
        $this->assertFalse($validated['tabs'][0]['source_group']['identity_verified']);
    }

    public function test_tab_manifest_is_normalized_to_the_explicit_allowlist(): void
    {
        $snapshot = $this->snapshot([]);
        $snapshot['tabs'][0]['provider_payload'] = ['private' => true];
        $snapshot['tabs'][0]['source_group']['unexpected'] = 'hidden';
        $snapshot['tabs'][0]['source_group']['evidence']['document_id'] = 'hidden';

        $validated = (new GoogleDocsSnapshotValidator)->validate(
            $snapshot,
            'document-safe-1234567890',
        );
        $tab = $validated['tabs'][0];

        $this->assertArrayNotHasKey('provider_payload', $tab);
        $this->assertArrayNotHasKey('unexpected', $tab['source_group']);
        $this->assertArrayNotHasKey('document_id', $tab['source_group']['evidence']);
        $this->assertSame('tab-1', $tab['tab_id']);
        $this->assertSame('Nhóm một', $tab['title']);
        $this->assertFalse($tab['source_group']['identity_verified']);
    }

    public function test_duplicate_source_keys_are_rejected(): void
    {
        $passage = $this->passage(1, 'Nội dung một');
        $duplicate = $this->passage(2, 'Nội dung hai');
        $duplicate['source_key'] = $passage['source_key'];

        $this->expectException(GoogleDocsSourceException::class);
        (new GoogleDocsSnapshotValidator)->validate(
            $this->snapshot([$passage, $duplicate]),
            'document-safe-1234567890',
        );
    }

    #[DataProvider('invalidPassageContainers')]
    public function test_invalid_passage_containers_are_rejected(mixed $passages): void
    {
        $snapshot = $this->snapshot([]);
        $snapshot['passages'] = $passages;

        $this->expectException(GoogleDocsSourceException::class);
        (new GoogleDocsSnapshotValidator)->validate(
            $snapshot,
            'document-safe-1234567890',
        );
    }

    /** @return array<string, array{mixed}> */
    public static function invalidPassageContainers(): array
    {
        return [
            'null' => [null],
            'object' => [(object) []],
            'scalar' => ['invalid'],
            'associative array' => [['passage' => []]],
        ];
    }

    public function test_tampered_provenance_or_content_hash_is_rejected(): void
    {
        foreach (['group', 'context', 'hash'] as $field) {
            $passage = $this->passage(1, 'Nội dung');
            if ($field === 'group') {
                $passage['metadata']['source_group']['evidence']['tab_id'] = 'other-tab';
            } elseif ($field === 'context') {
                $passage['metadata']['source_context']['key'] = 'other-group';
            } else {
                $passage['content_sha256'] = str_repeat('0', 64);
            }

            try {
                (new GoogleDocsSnapshotValidator)->validate(
                    $this->snapshot([$passage]),
                    'document-safe-1234567890',
                );
                $this->fail("Tampered {$field} was accepted.");
            } catch (GoogleDocsSourceException $exception) {
                $this->assertSame('GOOGLE_DOCS_INVALID_SNAPSHOT', $exception->errorCode);
            }
        }
    }

    /** @param list<array<string, mixed>> $passages
     * @return array<string, mixed>
     */
    private function snapshot(array $passages): array
    {
        $characters = array_sum(array_map(
            static fn (array $passage): int => mb_strlen($passage['text']),
            $passages,
        ));
        $group = $this->group();

        return [
            'document_id' => 'document-safe-1234567890',
            'title' => 'Tài liệu giả lập',
            'revision_id' => 'revision-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'tabs' => [[
                'tab_id' => 'tab-1',
                'title' => 'Nhóm một',
                'parent_tab_id' => null,
                'index' => 0,
                'nesting_level' => 0,
                'order' => 1,
                'passage_count' => count($passages),
                'character_count' => $characters,
                'source_group' => $group,
            ]],
            'passages' => $passages,
        ];
    }

    /** @return array<string, mixed> */
    private function passage(int $sequence, string $text): array
    {
        $group = $this->group();

        return [
            'source_key' => 'gdoc:aaaaaaaaaaaaaaaa:'
                .substr(hash('sha256', 'tab-1'), 0, 16)
                .':'.$sequence,
            'sequence' => $sequence,
            'kind' => 'paragraph',
            'text' => $text,
            'content_sha256' => hash('sha256', $text),
            'metadata' => [
                'tab_id' => 'tab-1',
                'tab_title' => 'Nhóm một',
                'source_group' => $group,
                'source_context' => [
                    'version' => 1,
                    'key' => $group['key'],
                    'origin' => 'GOOGLE_DOCS_TAB',
                    'order' => 1,
                    'evidence' => $group['evidence'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function group(): array
    {
        return [
            'version' => 1,
            'key' => 'google-docs-tab:'.hash('sha256', 'document-safe-1234567890|tab-1'),
            'title' => 'Nhóm một',
            'origin' => 'GOOGLE_DOCS_TAB',
            'order' => 1,
            'identity_verified' => false,
            'evidence' => [
                'type' => 'GOOGLE_DOCS_TAB',
                'tab_id' => 'tab-1',
                'parent_tab_id' => null,
                'nesting_level' => 0,
                'index' => 0,
            ],
        ];
    }
}

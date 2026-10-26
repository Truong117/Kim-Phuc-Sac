<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDocsApiClient;
use JsonException;

final class GoogleDocsDocumentReader
{
    public function __construct(private readonly GoogleDocsApiClient $client) {}

    /**
     * @return array{
     *     document_id: string,
     *     title: string,
     *     revision_id: string|null,
     *     snapshot_sha256: string,
     *     tabs: list<array<string, mixed>>,
     *     passages: list<array<string, mixed>>
     * }
     */
    public function read(string $documentId): array
    {
        $documentId = GoogleDocsDocumentReference::validateDocumentId($documentId);
        $timeoutSeconds = max(1, (int) config('google_docs.timeout_seconds', 15));
        $maxResponseBytes = max(1, (int) config('google_docs.max_response_bytes', 5242880));

        try {
            $document = $this->client->getDocument(
                $documentId,
                true,
                $timeoutSeconds,
                $maxResponseBytes,
            );
        } catch (GoogleDocsApiException $exception) {
            throw $this->safeApiException($exception);
        }

        try {
            $encodedDocument = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE', false, $exception);
        }
        if (strlen($encodedDocument) > $maxResponseBytes) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_RESPONSE_TOO_LARGE');
        }
        if (($document['documentId'] ?? null) !== $documentId
            || ! is_string($document['title'] ?? null)
            || ! is_array($document['tabs'] ?? null)
            || ! array_is_list($document['tabs'])
            || $document['tabs'] === []) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
        }

        $revisionId = $document['revisionId'] ?? null;
        if ($revisionId !== null && (! is_string($revisionId) || trim($revisionId) === '')) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
        }
        $snapshotSha256 = hash('sha256', implode('|', [
            $documentId,
            is_string($revisionId) ? $revisionId : hash('sha256', $encodedDocument),
        ]));
        $state = [
            'tab_count' => 0,
            'sequence' => 0,
            'characters' => 0,
            'tab_ids' => [],
        ];
        $tabs = [];
        $passages = [];
        foreach ($document['tabs'] as $rootIndex => $tab) {
            $this->readTab(
                $tab,
                null,
                0,
                $rootIndex,
                $documentId,
                $snapshotSha256,
                $tabs,
                $passages,
                $state,
            );
        }

        return [
            'document_id' => $documentId,
            'title' => $document['title'],
            'revision_id' => $revisionId,
            'snapshot_sha256' => $snapshotSha256,
            'tabs' => $tabs,
            'passages' => $passages,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tabs
     * @param  list<array<string, mixed>>  $passages
     * @param  array{tab_count: int, sequence: int, characters: int, tab_ids: array<string, true>}  $state
     */
    private function readTab(
        mixed $tab,
        ?string $parentTabId,
        int $depth,
        int $siblingIndex,
        string $documentId,
        string $snapshotSha256,
        array &$tabs,
        array &$passages,
        array &$state,
    ): void {
        if (! is_array($tab)
            || ! is_array($tab['tabProperties'] ?? null)
            || ! is_array($tab['documentTab'] ?? null)
            || ! is_array($tab['documentTab']['body'] ?? null)
            || ! is_array($tab['documentTab']['body']['content'] ?? null)
            || ! array_is_list($tab['documentTab']['body']['content'])) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
        }
        if ($depth > max(0, (int) config('google_docs.max_nesting_depth', 10))) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_LIMIT_EXCEEDED');
        }

        $properties = $tab['tabProperties'];
        $tabId = $properties['tabId'] ?? null;
        $title = $properties['title'] ?? null;
        $index = $properties['index'] ?? $siblingIndex;
        $declaredParentId = $properties['parentTabId'] ?? null;
        if (! is_string($tabId)
            || $tabId === ''
            || ! is_string($title)
            || ! is_int($index)
            || $index < 0
            || ($declaredParentId !== null && ! is_string($declaredParentId))
            || ($parentTabId === null && $declaredParentId !== null)
            || ($parentTabId !== null && $declaredParentId !== $parentTabId)
            || isset($state['tab_ids'][$tabId])) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
        }

        $state['tab_count']++;
        if ($state['tab_count'] > max(1, (int) config('google_docs.max_tabs', 100))) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_LIMIT_EXCEEDED');
        }
        $state['tab_ids'][$tabId] = true;
        $tabOrder = $state['tab_count'];
        $passageCountBefore = count($passages);
        $characterCountBefore = $state['characters'];
        $tabIdentity = hash('sha256', $documentId.'|'.$tabId);
        $group = [
            'version' => 1,
            'key' => 'google-docs-tab:'.$tabIdentity,
            'title' => $title,
            'origin' => 'GOOGLE_DOCS_TAB',
            'order' => $tabOrder,
            'identity_verified' => false,
            'evidence' => [
                'type' => 'GOOGLE_DOCS_TAB',
                'tab_id' => $tabId,
                'parent_tab_id' => $parentTabId,
                'nesting_level' => $depth,
                'index' => $index,
            ],
        ];
        $context = [
            'version' => 1,
            'key' => 'google-docs-tab:'.$tabIdentity,
            'origin' => 'GOOGLE_DOCS_TAB',
            'order' => $tabOrder,
            'evidence' => $group['evidence'],
        ];

        $this->readStructuralElements(
            $tab['documentTab']['body']['content'],
            $tabId,
            $title,
            $snapshotSha256,
            $group,
            $context,
            $passages,
            $state,
        );
        $tabs[] = [
            'tab_id' => $tabId,
            'title' => $title,
            'parent_tab_id' => $parentTabId,
            'index' => $index,
            'nesting_level' => $depth,
            'order' => $tabOrder,
            'passage_count' => count($passages) - $passageCountBefore,
            'character_count' => $state['characters'] - $characterCountBefore,
            'source_group' => $group,
        ];

        $children = $tab['childTabs'] ?? [];
        if (! is_array($children) || ! array_is_list($children)) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
        }
        foreach ($children as $childIndex => $childTab) {
            $this->readTab(
                $childTab,
                $tabId,
                $depth + 1,
                $childIndex,
                $documentId,
                $snapshotSha256,
                $tabs,
                $passages,
                $state,
            );
        }
    }

    /**
     * @param  list<mixed>  $elements
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $passages
     * @param  array{tab_count: int, sequence: int, characters: int, tab_ids: array<string, true>}  $state
     */
    private function readStructuralElements(
        array $elements,
        string $tabId,
        string $tabTitle,
        string $snapshotSha256,
        array $group,
        array $context,
        array &$passages,
        array &$state,
    ): void {
        $paragraph = 0;
        $table = 0;
        foreach ($elements as $element) {
            if (! is_array($element)) {
                throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
            }
            if (isset($element['paragraph'])) {
                $paragraph++;
                if (! is_array($element['paragraph'])) {
                    throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
                }
                $text = $this->paragraphText($element['paragraph']);
                if ($text === '') {
                    continue;
                }
                $bullet = $element['paragraph']['bullet'] ?? null;
                $kind = is_array($bullet) ? 'list_item' : 'paragraph';
                $metadata = [
                    'paragraph' => $paragraph,
                    'bullet' => is_array($bullet) ? [
                        'list_id' => is_string($bullet['listId'] ?? null) ? $bullet['listId'] : null,
                        'nesting_level' => is_int($bullet['nestingLevel'] ?? null) ? $bullet['nestingLevel'] : 0,
                    ] : null,
                ];
                $this->appendPassage(
                    $passages,
                    $state,
                    $snapshotSha256,
                    $tabId,
                    $tabTitle,
                    $kind,
                    $text,
                    $group,
                    $context,
                    $metadata,
                );

                continue;
            }
            if (isset($element['table'])) {
                $table++;
                $tableValue = $element['table'];
                if (! is_array($tableValue)
                    || ! is_array($tableValue['tableRows'] ?? null)
                    || ! array_is_list($tableValue['tableRows'])) {
                    throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
                }
                foreach ($tableValue['tableRows'] as $rowIndex => $row) {
                    if (! is_array($row)
                        || ! is_array($row['tableCells'] ?? null)
                        || ! array_is_list($row['tableCells'])) {
                        throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
                    }
                    $cells = [];
                    foreach ($row['tableCells'] as $cell) {
                        if (! is_array($cell)
                            || ! is_array($cell['content'] ?? null)
                            || ! array_is_list($cell['content'])) {
                            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
                        }
                        $cells[] = $this->structuralText($cell['content']);
                    }
                    $text = $this->normalizeText(implode(' | ', $cells));
                    if ($text === '') {
                        continue;
                    }
                    $this->appendPassage(
                        $passages,
                        $state,
                        $snapshotSha256,
                        $tabId,
                        $tabTitle,
                        'table_row',
                        $text,
                        $group,
                        $context,
                        [
                            'table' => $table,
                            'row' => $rowIndex + 1,
                            'cells' => $cells,
                        ],
                    );
                }
            }
        }
    }

    /** @param array<string, mixed> $paragraph */
    private function paragraphText(array $paragraph): string
    {
        $elements = $paragraph['elements'] ?? null;
        if (! is_array($elements) || ! array_is_list($elements)) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
        }
        $parts = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
            }
            $content = $element['textRun']['content'] ?? null;
            if ($content !== null && ! is_string($content)) {
                throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
            }
            if (is_string($content)) {
                $parts[] = $content;
            }
        }

        return $this->normalizeText(implode('', $parts));
    }

    /** @param list<mixed> $elements */
    private function structuralText(array $elements): string
    {
        $parts = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
            }
            if (isset($element['paragraph'])) {
                if (! is_array($element['paragraph'])) {
                    throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
                }
                $parts[] = $this->paragraphText($element['paragraph']);
            } elseif (isset($element['table'])) {
                $table = $element['table'];
                if (! is_array($table)
                    || ! is_array($table['tableRows'] ?? null)
                    || ! array_is_list($table['tableRows'])) {
                    throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
                }
                foreach ($table['tableRows'] as $row) {
                    if (! is_array($row)
                        || ! is_array($row['tableCells'] ?? null)
                        || ! array_is_list($row['tableCells'])) {
                        throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
                    }
                    foreach ($row['tableCells'] as $cell) {
                        if (! is_array($cell)
                            || ! is_array($cell['content'] ?? null)
                            || ! array_is_list($cell['content'])) {
                            throw new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE');
                        }
                        $parts[] = $this->structuralText($cell['content']);
                    }
                }
            }
        }

        return $this->normalizeText(implode("\n", array_filter($parts, fn (string $part): bool => $part !== '')));
    }

    /**
     * @param  list<array<string, mixed>>  $passages
     * @param  array{tab_count: int, sequence: int, characters: int, tab_ids: array<string, true>}  $state
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $metadata
     */
    private function appendPassage(
        array &$passages,
        array &$state,
        string $snapshotSha256,
        string $tabId,
        string $tabTitle,
        string $kind,
        string $text,
        array $group,
        array $context,
        array $metadata,
    ): void {
        $characters = mb_strlen($text);
        if ($characters > max(1, (int) config('google_docs.max_passage_characters', 10000))
            || count($passages) >= max(1, (int) config('google_docs.max_passages', 500))
            || ($state['characters'] + $characters) > max(1, (int) config('google_docs.max_characters', 200000))) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_LIMIT_EXCEEDED');
        }

        $state['sequence']++;
        $state['characters'] += $characters;
        $sourceKey = implode(':', [
            'gdoc',
            substr($snapshotSha256, 0, 16),
            substr(hash('sha256', $tabId), 0, 16),
            (string) $state['sequence'],
        ]);
        $passages[] = [
            'source_key' => $sourceKey,
            'sequence' => $state['sequence'],
            'kind' => $kind,
            'text' => $text,
            'content_sha256' => hash('sha256', $text),
            'metadata' => $metadata + [
                'tab_id' => $tabId,
                'tab_title' => $tabTitle,
                'source_context' => $context,
                'source_group' => $group,
            ],
        ];
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);

        return trim($text);
    }

    private function safeApiException(GoogleDocsApiException $exception): GoogleDocsReadException
    {
        if ($exception->reason === 'RESPONSE_TOO_LARGE') {
            return new GoogleDocsReadException('GOOGLE_DOCS_RESPONSE_TOO_LARGE', false, $exception);
        }
        if ($exception->reason === 'MALFORMED_RESPONSE') {
            return new GoogleDocsReadException('GOOGLE_DOCS_MALFORMED_RESPONSE', false, $exception);
        }
        if ($exception->reason === 'TIMEOUT') {
            return new GoogleDocsReadException('GOOGLE_DOCS_UNAVAILABLE', true, $exception);
        }

        return match (true) {
            in_array($exception->statusCode, [401, 403], true) => new GoogleDocsReadException(
                'GOOGLE_DOCS_ACCESS_DENIED',
                false,
                $exception,
            ),
            $exception->statusCode === 404 => new GoogleDocsReadException(
                'GOOGLE_DOCS_NOT_FOUND',
                false,
                $exception,
            ),
            $exception->statusCode === 429 => new GoogleDocsReadException(
                'GOOGLE_DOCS_RATE_LIMITED',
                true,
                $exception,
            ),
            $exception->statusCode >= 500 => new GoogleDocsReadException(
                'GOOGLE_DOCS_UNAVAILABLE',
                true,
                $exception,
            ),
            default => new GoogleDocsReadException(
                'GOOGLE_DOCS_REQUEST_FAILED',
                false,
                $exception,
            ),
        };
    }
}

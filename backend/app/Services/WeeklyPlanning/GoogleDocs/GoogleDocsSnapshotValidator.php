<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

final class GoogleDocsSnapshotValidator
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{tabs: list<array<string, mixed>>, passages: list<array<string, mixed>>, passage_count: int, character_count: int}
     */
    public function validate(array $snapshot, string $authorizedDocumentId): array
    {
        $tabs = $snapshot['tabs'] ?? null;
        $passages = $snapshot['passages'] ?? null;
        if (($snapshot['document_id'] ?? null) !== $authorizedDocumentId
            || ! is_string($snapshot['title'] ?? null)
            || ! is_string($snapshot['snapshot_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $snapshot['snapshot_sha256']) !== 1
            || (($snapshot['revision_id'] ?? null) !== null
                && ! is_string($snapshot['revision_id']))
            || ! is_array($tabs)
            || ! array_is_list($tabs)
            || $tabs === []
            || ! is_array($passages)
            || ! array_is_list($passages)) {
            throw $this->invalid();
        }

        $tabMap = [];
        $manifest = [];
        $passageCount = 0;
        $characterCount = 0;
        foreach ($tabs as $offset => $tab) {
            if (! is_array($tab)) {
                throw $this->invalid();
            }
            $tabId = $tab['tab_id'] ?? null;
            $title = $tab['title'] ?? null;
            $parentId = $tab['parent_tab_id'] ?? null;
            $group = $tab['source_group'] ?? null;
            $order = $offset + 1;
            if (! is_string($tabId)
                || $tabId === ''
                || isset($tabMap[$tabId])
                || ! is_string($title)
                || ($parentId !== null && (! is_string($parentId) || ! isset($tabMap[$parentId])))
                || ! is_int($tab['nesting_level'] ?? null)
                || $tab['nesting_level'] < 0
                || ($parentId === null && $tab['nesting_level'] !== 0)
                || ($parentId !== null
                    && $tab['nesting_level'] !== ($tabMap[$parentId]['nesting_level'] + 1))
                || ($tab['order'] ?? null) !== $order
                || ! is_int($tab['index'] ?? null)
                || $tab['index'] < 0
                || ! is_int($tab['passage_count'] ?? null)
                || $tab['passage_count'] < 0
                || ! is_int($tab['character_count'] ?? null)
                || $tab['character_count'] < 0
                || ($group['key'] ?? null) !== 'google-docs-tab:'.hash('sha256', $authorizedDocumentId.'|'.$tabId)
                || ! $this->validGroup($group, $tab)) {
                throw $this->invalid();
            }

            $tabMap[$tabId] = $tab;
            $passageCount += $tab['passage_count'];
            $characterCount += $tab['character_count'];
            $manifest[] = [
                'tab_id' => $tabId,
                'title' => $title,
                'parent_tab_id' => $parentId,
                'nesting_level' => $tab['nesting_level'],
                'index' => $tab['index'],
                'order' => $order,
                'passage_count' => $tab['passage_count'],
                'character_count' => $tab['character_count'],
                'source_group' => $this->safeGroup($group),
            ];
        }

        $sourceKeys = [];
        $actualCharacters = 0;
        $passagesPerTab = array_fill_keys(array_keys($tabMap), 0);
        $charactersPerTab = array_fill_keys(array_keys($tabMap), 0);
        foreach ($passages as $offset => $passage) {
            if (! is_array($passage)) {
                throw $this->invalid();
            }
            $sequence = $offset + 1;
            $sourceKey = $passage['source_key'] ?? null;
            $text = $passage['text'] ?? null;
            $metadata = $passage['metadata'] ?? null;
            $tabId = is_array($metadata) ? ($metadata['tab_id'] ?? null) : null;
            if (($passage['sequence'] ?? null) !== $sequence
                || ! is_string($sourceKey)
                || $sourceKey === ''
                || isset($sourceKeys[$sourceKey])
                || $sourceKey !== implode(':', [
                    'gdoc',
                    substr($snapshot['snapshot_sha256'], 0, 16),
                    substr(hash('sha256', (string) $tabId), 0, 16),
                    (string) $sequence,
                ])
                || ! in_array($passage['kind'] ?? null, ['paragraph', 'list_item', 'table_row'], true)
                || ! is_string($text)
                || trim($text) === ''
                || ($passage['content_sha256'] ?? null) !== hash('sha256', $text)
                || ! is_string($tabId)
                || ! isset($tabMap[$tabId])
                || ($metadata['tab_title'] ?? null) !== $tabMap[$tabId]['title']
                || ! $this->validGroup($metadata['source_group'] ?? null, $tabMap[$tabId])
                || ! $this->validContext($metadata['source_context'] ?? null, $tabMap[$tabId])) {
                throw $this->invalid();
            }

            $sourceKeys[$sourceKey] = true;
            $characters = mb_strlen($text);
            $actualCharacters += $characters;
            $passagesPerTab[$tabId]++;
            $charactersPerTab[$tabId] += $characters;
        }

        foreach ($tabMap as $tabId => $tab) {
            if ($passagesPerTab[$tabId] !== $tab['passage_count']
                || $charactersPerTab[$tabId] !== $tab['character_count']) {
                throw $this->invalid();
            }
        }
        if (count($passages) !== $passageCount || $actualCharacters !== $characterCount) {
            throw $this->invalid();
        }

        return [
            'tabs' => $manifest,
            'passages' => $passages,
            'passage_count' => $passageCount,
            'character_count' => $characterCount,
        ];
    }

    /** @param array<string, mixed> $tab */
    private function validGroup(mixed $group, array $tab): bool
    {
        if (! is_array($group) || ! is_array($group['evidence'] ?? null)) {
            return false;
        }
        $evidence = $group['evidence'];

        return ($group['version'] ?? null) === 1
            && is_string($group['key'] ?? null)
            && $group['key'] !== ''
            && ($group['title'] ?? null) === $tab['title']
            && ($group['origin'] ?? null) === 'GOOGLE_DOCS_TAB'
            && ($group['order'] ?? null) === $tab['order']
            && ($group['identity_verified'] ?? null) === false
            && ($evidence['type'] ?? null) === 'GOOGLE_DOCS_TAB'
            && ($evidence['tab_id'] ?? null) === $tab['tab_id']
            && ($evidence['parent_tab_id'] ?? null) === $tab['parent_tab_id']
            && ($evidence['nesting_level'] ?? null) === $tab['nesting_level']
            && ($evidence['index'] ?? null) === $tab['index'];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function safeGroup(array $group): array
    {
        $evidence = $group['evidence'];

        return [
            'version' => 1,
            'key' => $group['key'],
            'title' => $group['title'],
            'origin' => 'GOOGLE_DOCS_TAB',
            'order' => $group['order'],
            'identity_verified' => false,
            'evidence' => [
                'type' => 'GOOGLE_DOCS_TAB',
                'tab_id' => $evidence['tab_id'],
                'parent_tab_id' => $evidence['parent_tab_id'],
                'nesting_level' => $evidence['nesting_level'],
                'index' => $evidence['index'],
            ],
        ];
    }

    /** @param array<string, mixed> $tab */
    private function validContext(mixed $context, array $tab): bool
    {
        if (! is_array($context) || ! is_array($context['evidence'] ?? null)) {
            return false;
        }

        return ($context['version'] ?? null) === 1
            && is_string($context['key'] ?? null)
            && $context['key'] === ($tab['source_group']['key'] ?? null)
            && ($context['origin'] ?? null) === 'GOOGLE_DOCS_TAB'
            && ($context['order'] ?? null) === $tab['order']
            && ($context['evidence'] ?? null) === ($tab['source_group']['evidence'] ?? null);
    }

    private function invalid(): GoogleDocsSourceException
    {
        return new GoogleDocsSourceException(
            'GOOGLE_DOCS_INVALID_SNAPSHOT',
            422,
            'Nội dung Google Docs không đáp ứng hợp đồng dữ liệu an toàn.',
        );
    }
}

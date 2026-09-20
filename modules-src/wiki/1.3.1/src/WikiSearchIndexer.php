<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

use PDO;
use RuntimeException;

final class WikiSearchIndexer
{
    public const VERSION = 1;

    public function __construct(private readonly PDO $pdo) {}

    /** Incrementally updates new/changed/deleted visible pages. Must run inside the sync transaction. */
    public function synchronize(int $sourceId, string $contentPath): array
    {
        $pages = $this->pages($sourceId);
        $documents = $this->documents($sourceId);
        $visibleIds = [];
        $indexed = 0;
        foreach ($pages as $page) {
            if ((int) $page['hidden'] === 1) continue;
            $id = (int) $page['id'];
            $visibleIds[] = $id;
            $existing = $documents[$id] ?? null;
            if (is_array($existing) && hash_equals((string) $existing['content_hash'], $this->fingerprint($page)) && (int) $existing['index_version'] === self::VERSION) continue;
            $this->indexPage($sourceId, $page, $contentPath);
            $indexed++;
        }
        $this->deleteMissingDocuments($sourceId, $visibleIds);
        $this->removeUnusedTerms($sourceId);
        $this->updateState($sourceId, 'current');
        return ['indexed' => $indexed, 'pages' => count($visibleIds)];
    }

    /** Rebuilds within a transaction, so readers retain the prior index until commit. */
    public function rebuild(int $sourceId, string $contentPath): array
    {
        $this->pdo->prepare('DELETE p FROM wiki_search_postings p JOIN wiki_pages w ON w.id=p.page_id WHERE w.source_id=:id')->execute(['id'=>$sourceId]);
        $this->pdo->prepare('DELETE FROM wiki_search_documents WHERE source_id=:id')->execute(['id' => $sourceId]);
        $this->pdo->prepare('DELETE FROM wiki_search_terms WHERE source_id=:id')->execute(['id' => $sourceId]);
        $count = 0;
        foreach ($this->pages($sourceId) as $page) {
            if ((int) $page['hidden'] === 1) continue;
            $this->indexPage($sourceId, $page, $contentPath);
            $count++;
        }
        $this->updateState($sourceId, 'current');
        return ['pages' => $count, 'terms' => $this->termCount($sourceId)];
    }

    private function indexPage(int $sourceId, array $page, string $contentPath): void
    {
        $file = $contentPath . '/' . (string) $page['relative_path'];
        if (!is_file($file) || !is_readable($file)) throw new RuntimeException('wiki_search_content_unavailable');
        $markdown = file_get_contents($file);
        if (!is_string($markdown)) throw new RuntimeException('wiki_search_content_unavailable');
        $text = WikiSearchText::extract((string) $page['title'], $markdown, (string) $page['relative_path']);
        $pageId = (int) $page['id'];
        $this->pdo->prepare('DELETE FROM wiki_search_postings WHERE page_id=:id')->execute(['id' => $pageId]);
        $this->pdo->prepare('DELETE FROM wiki_search_documents WHERE page_id=:id')->execute(['id' => $pageId]);
        $document = $this->pdo->prepare('INSERT INTO wiki_search_documents(page_id,source_id,content_hash,title_text,headings_text,body_text,code_text,path_text,index_version) VALUES(:page_id,:source_id,:hash,:title,:headings,:body,:code,:path,:version)');
        $document->execute(['page_id'=>$pageId,'source_id'=>$sourceId,'hash'=>$this->fingerprint($page),'title'=>$text['title'],'headings'=>$text['headings'],'body'=>$text['body'],'code'=>$text['code'],'path'=>$text['path'],'version'=>self::VERSION]);
        $fields = ['title'=>'title_hits','headings'=>'heading_hits','body'=>'body_hits','code'=>'code_hits','path'=>'path_hits'];
        $counts = [];
        foreach ($fields as $field => $column) foreach (WikiSearchText::terms($text[$field]) as $term) $counts[$term][$column] = min(65535, ($counts[$term][$column] ?? 0) + 1);
        $termHits = [];
        foreach ($counts as $term => $hits) {
            $termId = $this->termId($sourceId, (string) $term);
            foreach (['title_hits','heading_hits','body_hits','code_hits','path_hits'] as $column) $termHits[$termId][$column]=min(65535,($termHits[$termId][$column]??0)+($hits[$column]??0));
        }
        foreach ($termHits as $termId => $hits) {
            $posting = $this->pdo->prepare('INSERT INTO wiki_search_postings(term_id,page_id,title_hits,heading_hits,body_hits,code_hits,path_hits) VALUES(:term,:page,:title,:heading,:body,:code,:path)');
            $posting->execute(['term'=>$termId,'page'=>$pageId,'title'=>$hits['title_hits']??0,'heading'=>$hits['heading_hits']??0,'body'=>$hits['body_hits']??0,'code'=>$hits['code_hits']??0,'path'=>$hits['path_hits']??0]);
        }
    }

    private function termId(int $sourceId, string $term): int
    {
        $statement = $this->pdo->prepare('INSERT IGNORE INTO wiki_search_terms(source_id,term) VALUES(:source,:term)');
        $statement->execute(['source'=>$sourceId,'term'=>$term]);
        $lookup=$this->pdo->prepare('SELECT id FROM wiki_search_terms WHERE source_id=:source AND term=:term');$lookup->execute(['source'=>$sourceId,'term'=>$term]);$id=(int)$lookup->fetchColumn();
        $insert = $this->pdo->prepare('INSERT IGNORE INTO wiki_search_trigrams(source_id,trigram,term_id) VALUES(:source,:trigram,:term)');
        foreach (WikiSearchText::trigrams($term) as $trigram) $insert->execute(['source'=>$sourceId,'trigram'=>$trigram,'term'=>$id]);
        return $id;
    }

    private function deleteMissingDocuments(int $sourceId, array $ids): void
    {
        if ($ids === []) { $this->pdo->prepare('DELETE p FROM wiki_search_postings p JOIN wiki_pages w ON w.id=p.page_id WHERE w.source_id=:id')->execute(['id'=>$sourceId]);$this->pdo->prepare('DELETE FROM wiki_search_documents WHERE source_id=:id')->execute(['id'=>$sourceId]); return; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $posting=$this->pdo->prepare("DELETE p FROM wiki_search_postings p JOIN wiki_pages w ON w.id=p.page_id WHERE w.source_id=? AND p.page_id NOT IN ({$placeholders})");$posting->execute(array_merge([$sourceId],$ids));
        $statement = $this->pdo->prepare("DELETE FROM wiki_search_documents WHERE source_id=? AND page_id NOT IN ({$placeholders})");
        $statement->execute(array_merge([$sourceId], $ids));
    }
    private function removeUnusedTerms(int $sourceId): void { $this->pdo->prepare('DELETE t FROM wiki_search_terms t LEFT JOIN wiki_search_postings p ON p.term_id=t.id WHERE t.source_id=:id AND p.term_id IS NULL')->execute(['id'=>$sourceId]); }
    private function pages(int $sourceId): array { $s=$this->pdo->prepare('SELECT * FROM wiki_pages WHERE source_id=:id ORDER BY id');$s->execute(['id'=>$sourceId]);return $s->fetchAll()?:[]; }
    private function documents(int $sourceId): array { $s=$this->pdo->prepare('SELECT page_id,content_hash,index_version FROM wiki_search_documents WHERE source_id=:id');$s->execute(['id'=>$sourceId]);$r=[];foreach($s->fetchAll()?:[] as $row)$r[(int)$row['page_id']]=$row;return $r; }
    private function termCount(int $sourceId): int { $s=$this->pdo->prepare('SELECT COUNT(*) FROM wiki_search_terms WHERE source_id=:id');$s->execute(['id'=>$sourceId]);return (int)$s->fetchColumn(); }
    private function updateState(int $sourceId,string $status): void { $s=$this->pdo->prepare('INSERT INTO wiki_search_state(source_id,index_version,status,indexed_pages,term_count) VALUES(:id,:version,:status,(SELECT COUNT(*) FROM wiki_search_documents WHERE source_id=:id2),(SELECT COUNT(*) FROM wiki_search_terms WHERE source_id=:id3)) ON DUPLICATE KEY UPDATE index_version=VALUES(index_version),status=VALUES(status),indexed_pages=VALUES(indexed_pages),term_count=VALUES(term_count),updated_at=CURRENT_TIMESTAMP');$s->execute(['id'=>$sourceId,'version'=>self::VERSION,'status'=>$status,'id2'=>$sourceId,'id3'=>$sourceId]); }
    private function fingerprint(array $page): string { return hash('sha256',(string)$page['content_hash']."\0".(string)$page['title']."\0".(string)$page['relative_path']); }
}

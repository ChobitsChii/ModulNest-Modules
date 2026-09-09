<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

use PDO;
use Throwable;

final class WikiSearchService
{
    public const MIN_QUERY_LENGTH = 2;
    public const MAX_QUERY_LENGTH = 120;
    private const MAX_RESULTS = 20;
    private const MAX_CANDIDATE_TERMS = 80;

    public function __construct(private readonly PDO $pdo, private readonly WikiSearchIndexer $indexer, private readonly string $contentPath) {}

    /** @return array{query:string,too_short:bool,available:bool,results:list<array<string,mixed>>} */
    public function search(string $query): array
    {
        $query = trim(mb_substr($query, 0, self::MAX_QUERY_LENGTH));
        $tokens = array_values(array_unique(WikiSearchText::terms($query)));
        if ($query === '' || $tokens === [] || mb_strlen($query) < self::MIN_QUERY_LENGTH) return ['query'=>$query,'too_short'=>true,'available'=>$this->isAvailable(),'results'=>[]];
        if (count($tokens) > 8) $tokens = array_slice($tokens, 0, 8);
        try {
            $sourceId = $this->sourceId();
            if ($sourceId === null || !$this->isAvailable()) return ['query'=>$query,'too_short'=>false,'available'=>false,'results'=>[]];
            $matches = [];
            foreach ($tokens as $token) foreach ($this->candidateTerms($sourceId, $token) as $candidate) {
                $termId = (int) $candidate['id'];
                $quality = (float) $candidate['quality'];
                $matches[$termId]['quality'] = max($matches[$termId]['quality'] ?? 0.0, $quality);
                $matches[$termId]['term'] = (string) $candidate['term'];
                $matches[$termId]['tokens'][$token] = true;
            }
            if ($matches === []) return ['query'=>$query,'too_short'=>false,'available'=>true,'results'=>[]];
            $postings = $this->postings(array_keys($matches));
            $documents = [];
            foreach ($postings as $row) {
                $pageId = (int) $row['page_id']; $termId=(int)$row['term_id']; $quality=$matches[$termId]['quality'];
                $fieldScore=(int)$row['title_hits']*120+(int)$row['heading_hits']*55+(int)$row['body_hits']*15+(int)$row['code_hits']*5+(int)$row['path_hits']*25;
                $documents[$pageId]['score']=($documents[$pageId]['score']??0)+$fieldScore*$quality;
                foreach (array_keys($matches[$termId]['tokens']) as $token) $documents[$pageId]['tokens'][$token]=true;
                $documents[$pageId]['matched_terms'][(string)$matches[$termId]['term']]=true;
            }
            if ($documents === []) return ['query'=>$query,'too_short'=>false,'available'=>true,'results'=>[]];
            $rows = $this->documentRows(array_keys($documents));
            $normalizedQuery=WikiSearchText::normalize($query);$results=[];
            foreach($rows as $row){$id=(int)$row['page_id'];$score=(float)$documents[$id]['score'];$coverage=count($documents[$id]['tokens']??[]);$score*=1+max(0,$coverage-1)*0.35;if(WikiSearchText::normalize((string)$row['title'])===$normalizedQuery)$score+=1000;elseif(str_contains(WikiSearchText::normalize((string)$row['title']),$normalizedQuery))$score+=350;$matchedTerms=array_keys($documents[$id]['matched_terms']??[]);$results[]=['title'=>(string)$row['title'],'route_path'=>(string)$row['route_path'],'context'=>$this->context((string)$row['route_path']),'snippet'=>$this->snippet((string)$row['body_text'],(string)$row['headings_text'],$matchedTerms),'matched_terms'=>$matchedTerms,'score'=>$score];}
            usort($results,static fn(array $a,array $b):int=>$b['score']<=>$a['score']?:strcmp($a['title'],$b['title']));
            return ['query'=>$query,'too_short'=>false,'available'=>true,'results'=>array_slice($results,0,self::MAX_RESULTS)];
        } catch (Throwable) {
            return ['query'=>$query,'too_short'=>false,'available'=>false,'results'=>[]];
        }
    }

    public function status(): array
    {
        try {
            $sourceId=$this->sourceId();if($sourceId===null)return ['status'=>'missing','version'=>WikiSearchIndexer::VERSION,'pages'=>0,'terms'=>0,'updated_at'=>null];
            $s=$this->pdo->prepare('SELECT * FROM wiki_search_state WHERE source_id=:id');$s->execute(['id'=>$sourceId]);$row=$s->fetch();
            if(!is_array($row))return ['status'=>'missing','version'=>WikiSearchIndexer::VERSION,'pages'=>0,'terms'=>0,'updated_at'=>null];
            $status=((int)$row['index_version']===WikiSearchIndexer::VERSION)?(string)$row['status']:'stale';
            return ['status'=>$status,'version'=>(int)$row['index_version'],'pages'=>(int)$row['indexed_pages'],'terms'=>(int)$row['term_count'],'updated_at'=>$row['updated_at']];
        }catch(Throwable){return ['status'=>'missing','version'=>WikiSearchIndexer::VERSION,'pages'=>0,'terms'=>0,'updated_at'=>null];}
    }

    public function rebuild(): array
    {
        $sourceId=$this->sourceId();if($sourceId===null)throw new \RuntimeException('Kein Wiki-Stand verfügbar.');
        $this->pdo->beginTransaction();
        try{$result=$this->indexer->rebuild($sourceId,$this->contentPath);$this->pdo->commit();return $result;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function isAvailable(): bool { $status=$this->status();return $status['status']==='current'&&$status['version']===WikiSearchIndexer::VERSION; }
    private function sourceId(): ?int { $r=$this->pdo->query('SELECT id FROM wiki_sources ORDER BY id LIMIT 1')->fetchColumn();return $r===false?null:(int)$r; }
    private function candidateTerms(int $sourceId,string $token): array
    {
        $found=[];$exact=$this->pdo->prepare('SELECT id,term FROM wiki_search_terms WHERE source_id=:source AND term=:term LIMIT 1');$exact->execute(['source'=>$sourceId,'term'=>$token]);foreach($exact->fetchAll()?:[] as $r)$found[(int)$r['id']]=['id'=>$r['id'],'term'=>$r['term'],'quality'=>1.0];
        if(mb_strlen($token)>=3){$prefix=$this->pdo->prepare("SELECT id,term FROM wiki_search_terms WHERE source_id=:source AND term LIKE :prefix ESCAPE '!' ORDER BY CHAR_LENGTH(term),term LIMIT 30");$prefix->execute(['source'=>$sourceId,'prefix'=>$this->like($token).'%']);foreach($prefix->fetchAll()?:[] as $r)$found[(int)$r['id']]=['id'=>$r['id'],'term'=>$r['term'],'quality'=>max($found[(int)$r['id']]['quality']??0,0.72)];}
        if(mb_strlen($token)>=4){$grams=WikiSearchText::trigrams($token);if($grams!==[]){$marks=implode(',',array_fill(0,count($grams),'?'));$sql="SELECT t.id,t.term,COUNT(*) overlap_count FROM wiki_search_trigrams g JOIN wiki_search_terms t ON t.id=g.term_id WHERE g.source_id=? AND g.trigram IN ({$marks}) GROUP BY t.id,t.term ORDER BY overlap_count DESC LIMIT 50";$s=$this->pdo->prepare($sql);$s->execute(array_merge([$sourceId],$grams));foreach($s->fetchAll()?:[] as $r){$candidate=(string)$r['term'];$candidateGrams=WikiSearchText::trigrams($candidate);$union=count(array_unique(array_merge($grams,$candidateGrams)));$similarity=$union>0?(int)$r['overlap_count']/$union:0;if($similarity<0.34)continue;$distance=levenshtein($token,$candidate);$limit=max(1,(int)floor(max(mb_strlen($token),mb_strlen($candidate))*0.34));if($distance>$limit)continue;$quality=max(0.35,0.62*$similarity+0.38*(1-$distance/max(mb_strlen($token),mb_strlen($candidate))));$found[(int)$r['id']]=['id'=>$r['id'],'term'=>$candidate,'quality'=>max($found[(int)$r['id']]['quality']??0,$quality)];}}}
        uasort($found,static fn(array $a,array $b):int=>$b['quality']<=>$a['quality']);return array_slice(array_values($found),0,self::MAX_CANDIDATE_TERMS);
    }
    private function postings(array $termIds): array { $marks=implode(',',array_fill(0,count($termIds),'?'));$s=$this->pdo->prepare("SELECT * FROM wiki_search_postings WHERE term_id IN ({$marks})");$s->execute($termIds);return $s->fetchAll()?:[]; }
    private function documentRows(array $ids): array { $marks=implode(',',array_fill(0,count($ids),'?'));$s=$this->pdo->prepare("SELECT d.*,p.title,p.route_path FROM wiki_search_documents d JOIN wiki_pages p ON p.id=d.page_id WHERE d.page_id IN ({$marks}) AND p.hidden=0");$s->execute($ids);return $s->fetchAll()?:[]; }
    private function snippet(string $body,string $headings,array $tokens): string { $text=trim($headings.' '.$body);if($text==='')return '';$lower=mb_strtolower($text);$position=null;foreach($tokens as $token){$p=mb_strpos($lower,mb_strtolower($token));if($p!==false&&($position===null||$p<$position))$position=$p;}$start=max(0,(int)($position??0)-70);$snippet=mb_substr($text,$start,220);return ($start>0?'…':'').$snippet.(mb_strlen($text)>$start+220?'…':''); }
    private function context(string $route): string { $parts=explode('/',$route);array_pop($parts);return $parts===[]?'Wiki':implode(' › ',array_map(static fn(string $p):string=>ucwords(str_replace(['-','_'],' ',$p)),$parts)); }
    private function like(string $value): string { return str_replace(['!','%','_'],['!!','!%','!_'],$value); }
}

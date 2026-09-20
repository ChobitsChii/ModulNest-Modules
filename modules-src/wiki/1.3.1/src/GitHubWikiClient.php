<?php

declare(strict_types=1);

namespace ModulNest\Wiki;

/** Public GitHub-only archive client. No arbitrary URL or credentials are accepted. */
final class GitHubWikiClient
{
    /** @return array{archive:string,sha:string} */
    public function download(string $owner, string $repository, string $ref): array
    {
        $encodedRef = implode('/', array_map('rawurlencode', explode('/', $ref)));
        $api = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repository) . '/commits/' . $encodedRef;
        $commit = $this->request($api, 1_000_000);
        $data = json_decode($commit, true);
        $sha = is_array($data) ? (string) ($data['sha'] ?? '') : '';
        if (!preg_match('/^[a-f0-9]{40}$/i', $sha)) { throw new WikiSyncException('github_commit_unavailable'); }
        $archive = $this->request('https://codeload.github.com/' . rawurlencode($owner) . '/' . rawurlencode($repository) . '/zip/' . rawurlencode($sha), 20_000_000);
        return ['archive' => $archive, 'sha' => strtolower($sha)];
    }
    private function request(string $url, int $maxBytes): string
    {
        if (!function_exists('curl_init')) { throw new WikiSyncException('curl_unavailable'); }
        $body = ''; $handle = curl_init($url);
        if ($handle === false) { throw new WikiSyncException('network_unavailable'); }
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_USERAGENT=>'ModulNest-Wiki/1.0',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json'],CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$body,$maxBytes): int { if(strlen($body)+strlen($chunk)>$maxBytes)return 0;$body.=$chunk;return strlen($chunk); }]);
        $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);unset($handle);
        if($ok!==true || $status<200 || $status>=300){throw new WikiSyncException($status===404?'github_not_found':'github_request_failed');}
        return $body;
    }
}

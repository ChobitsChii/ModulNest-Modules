<?php

declare(strict_types=1);

namespace ModulNest\Mirror;

use ModulNest\Mirror\Repository\MirrorConfigRepository;
use ModulNest\Mirror\Service\MirrorPathResolver;
use ModulNest\Mirror\Service\MirrorSyncService;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Throwable;

final class MirrorController
{
    public function __construct(
        private readonly Session $session,
        private readonly MirrorConfigRepository $repository,
        private readonly MirrorSyncService $syncService,
        private readonly MirrorPathResolver $pathResolver,
        private readonly string $basePath,
    ) {
    }

    public function index(Request $request): Response
    {
        $mirrors = $this->repository->findAll();
        $editId = (int) $request->query('edit', '0');
        $editMirror = $editId > 0 ? $this->repository->findById($editId) : null;

        return new Response(View::render('@modulnest.mirror/admin', [
            'title' => 'Mirror Manager',
            'admin_section' => 'mirror',
            'current_path' => $request->path(),
            'mirrors' => $mirrors,
            'edit_mirror' => $editMirror,
            'home_directory' => $this->pathResolver->home(),
            'message' => $this->session->pullFlash('mirror_info'),
            'error' => $this->session->pullFlash('mirror_error'),
        ]));
    }

    public function save(Request $request): Response
    {
        try {
            $id = (int) $request->input('id', '0');
            $name = trim((string) $request->input('name', ''));
            $type = (string) $request->input('type', 'repository');
            $sourceType = (string) $request->input('source_type', 'github');
            $sourceUrl = trim((string) $request->input('source_url', ''));
            $sourceBranch = trim((string) $request->input('source_branch', 'main'));
            $targetPath = trim((string) $request->input('target_path', ''));
            $publicUrl = trim((string) $request->input('public_url', ''));
            $enabled = !empty($request->input('enabled'));

            if ($name === '') {
                throw new \InvalidArgumentException('Bitte gib eine Bezeichnung für den Mirror an.');
            }
            if ($sourceUrl === '') {
                throw new \InvalidArgumentException('Bitte gib eine Quell-URL an.');
            }
            if ($targetPath === '') {
                throw new \InvalidArgumentException('Bitte wähle ein Zielverzeichnis aus.');
            }

            // Validierung über Path-Resolver (Home-Scoped)
            $resolvedPath = $this->pathResolver->resolvePath($targetPath);

            $data = [
                'name' => $name,
                'type' => in_array($type, ['repository', 'updates'], true) ? $type : 'repository',
                'source_type' => $sourceType,
                'source_url' => $sourceUrl,
                'source_branch' => $sourceBranch !== '' ? $sourceBranch : 'main',
                'target_path' => $resolvedPath,
                'public_url' => $publicUrl !== '' ? $publicUrl : null,
                'enabled' => $enabled,
            ];

            if ($id > 0) {
                $this->repository->update($id, $data);
                $this->session->flash('mirror_info', 'Mirror "' . $name . '" erfolgreich aktualisiert.');
            } else {
                $this->repository->create($data);
                $this->session->flash('mirror_info', 'Mirror "' . $name . '" erfolgreich angelegt.');
            }
        } catch (Throwable $e) {
            $this->session->flash('mirror_error', $e->getMessage());
        }

        return Response::redirect('/admin/mirror');
    }

    public function toggle(Request $request): Response
    {
        $id = (int) $request->input('id', '0');
        if ($id > 0) {
            $this->repository->toggleEnabled($id);
            $this->session->flash('mirror_info', 'Status des Mirrors aktualisiert.');
        }

        return Response::redirect('/admin/mirror');
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->input('id', '0');
        if ($id > 0) {
            $this->repository->delete($id);
            $this->session->flash('mirror_info', 'Mirror-Konfiguration gelöscht.');
        }

        return Response::redirect('/admin/mirror');
    }

    public function sync(Request $request): Response
    {
        $id = (int) $request->input('id', '0');
        $isAjax = $request->header('Accept') === 'application/json' || $request->header('X-Requested-With') === 'XMLHttpRequest';

        if ($id <= 0) {
            if ($isAjax) {
                return new Response(json_encode(['success' => false, 'message' => 'Ungültige Mirror-ID.'], JSON_THROW_ON_ERROR), 400, ['Content-Type' => 'application/json']);
            }
            $this->session->flash('mirror_error', 'Ungültige Mirror-ID.');
            return Response::redirect('/admin/mirror');
        }

        $res = $this->syncService->triggerSync($id);

        if ($isAjax) {
            return new Response(
                json_encode($res, JSON_THROW_ON_ERROR),
                $res['success'] ? 200 : 400,
                ['Content-Type' => 'application/json; charset=UTF-8']
            );
        }

        if ($res['success']) {
            $this->session->flash('mirror_info', $res['message']);
        } else {
            $this->session->flash('mirror_error', $res['message']);
        }

        return Response::redirect('/admin/mirror');
    }

    public function status(Request $request): Response
    {
        $mirrors = $this->repository->findAll();
        $data = [];

        foreach ($mirrors as $m) {
            $data[$m->id] = [
                'id' => $m->id,
                'name' => $m->name,
                'type' => $m->type,
                'status' => $m->lastStatus,
                'last_synced_at' => $m->lastSyncedAt,
                'last_trigger' => $m->lastTrigger,
                'last_log' => $m->lastLog,
            ];
        }

        return new Response(
            json_encode(['mirrors' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            200,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']
        );
    }

    public function directories(Request $request): Response
    {
        try {
            $path = (string) $request->query('path', '');
            $data = $this->pathResolver->listDirectories($path);

            return new Response(
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                200,
                ['Content-Type' => 'application/json; charset=UTF-8']
            );
        } catch (Throwable $e) {
            return new Response(
                json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                422,
                ['Content-Type' => 'application/json; charset=UTF-8']
            );
        }
    }
}

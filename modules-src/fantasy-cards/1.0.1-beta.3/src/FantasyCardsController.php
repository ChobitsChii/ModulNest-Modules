<?php

declare(strict_types=1);

namespace ModulNest\FantasyCards;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use RuntimeException;
use Throwable;

final class FantasyCardsController
{
    public function __construct(
        private readonly FantasyCardsRepository $repository,
        private readonly FantasyCardsService $service,
        private readonly FantasyCardsBoosterService $boosterService,
        private readonly FantasyCardsUploadService $uploadService,
        private readonly FantasyCardsSubnavigationProvider $subnavigation,
        private readonly Session $session,
        private readonly ?AuthService $auth,
        private readonly string $access,
    ) {
    }

    public function index(Request $request): Response
    {
        return new Response(View::render('@modulnest.fantasy-cards/index', $this->viewData($request, [
            'title' => 'Fantasy Cards',
            'sets' => $this->service->activeSets(),
        ])));
    }

    public function subRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if (preg_match('/^fantasy-cards\/set\/([a-z0-9][a-z0-9\-]*)$/', $path, $matches) === 1) {
            $detail = $this->service->activeSetDetail($matches[1]);
            if ($detail === null) {
                return $this->notFound($request);
            }

            return new Response(View::render('@modulnest.fantasy-cards/set', $this->viewData($request, [
                'title' => (string) ($detail['set']['name'] ?? 'Set'),
                'set' => $detail['set'],
                'cards' => $detail['cards'],
                'rarities' => FantasyCardsRarity::all(),
            ])));
        }

        if ($path === '@modulnest.fantasy-cards/boosters') {
            $userId = $this->currentUserId();
            return new Response(View::render('@modulnest.fantasy-cards/boosters', $this->viewData($request, array_merge([
                'title' => 'Meine Booster',
                'message' => $this->session->pullFlash('fantasycards_info'),
                'error' => $this->session->pullFlash('fantasycards_error'),
                'timezone_name' => $this->userTimezoneName(),
            ], $this->boosterService->dashboard($userId)))));
        }

        if ($path === '@modulnest.fantasy-cards/collection') {
            $userId = $this->currentUserId();
            $setId = (int) ($request->query('set', '0') ?? '0');
            return new Response(View::render('@modulnest.fantasy-cards/collection', $this->viewData($request, array_merge([
                'title' => 'Meine Sammlung',
            ], $this->boosterService->collection($userId, $setId > 0 ? $setId : null)))));
        }

        return $this->notFound($request);
    }

    public function claimBooster(Request $request): Response
    {
        try {
            $message = $this->boosterService->claimFreeBooster($this->currentUserId(), (int) $request->input('set_id', '0'));
            $this->session->flash('fantasycards_info', $message);
        } catch (RuntimeException $exception) {
            $this->session->flash('fantasycards_error', $exception->getMessage());
        }

        return Response::redirect('/fantasy-cards/boosters');
    }

    public function openBooster(Request $request): Response
    {
        try {
            $opening = $this->boosterService->openBooster($this->currentUserId(), (int) $request->input('booster_type_id', '0'));
            return $this->json([
                'ok' => true,
                'message' => 'Booster geöffnet.',
                'opening_id' => $opening['opening_id'],
                'booster' => $opening['booster'],
                'cards' => array_map([$this, 'cardJson'], $opening['cards']),
                'rarities' => FantasyCardsRarity::all(),
            ]);
        } catch (RuntimeException $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function adminSets(Request $request): Response
    {
        return new Response(View::render('@modulnest.fantasy-cards/admin-sets', $this->adminData($request, [
            'title' => 'Fantasy Cards Sets',
            'sets' => $this->repository->listSetsForAdmin(),
        ])));
    }

    public function adminSubRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'admin/fantasy-cards/sets/create') {
            return $this->setForm($request, null);
        }
        if (preg_match('/^admin\/fantasy-cards\/sets\/([0-9]+)\/edit$/', $path, $matches) === 1) {
            return $this->setForm($request, (int) $matches[1]);
        }
        if ($path === 'admin/fantasy-cards/cards') {
            return $this->adminCards($request);
        }
        if ($path === 'admin/fantasy-cards/upload') {
            return $this->uploadForm($request);
        }
        if ($path === 'admin/fantasy-cards/cards/create') {
            return $this->cardForm($request, null);
        }
        if (preg_match('/^admin\/fantasy-cards\/cards\/([0-9]+)\/edit$/', $path, $matches) === 1) {
            return $this->cardForm($request, (int) $matches[1]);
        }

        return $this->notFound($request);
    }

    public function saveSet(Request $request): Response
    {
        $id = (int) $request->input('set_id', '0');
        $existing = $id > 0 ? $this->repository->findSetById($id) : null;
        if ($id > 0 && $existing === null) {
            $this->session->flash('fantasycards_error', 'Set nicht gefunden.');
            return Response::redirect('/admin/fantasy-cards');
        }

        $payload = $this->setPayload($request, $existing);
        if ($payload['error'] !== null) {
            $this->session->flash('fantasycards_error', $payload['error']);
            return Response::redirect($id > 0 ? '/admin/fantasy-cards/sets/' . $id . '/edit' : '/admin/fantasy-cards/sets/create');
        }

        $setId = $this->repository->saveSet($payload['data'], $id > 0 ? $id : null);
        $this->session->flash('fantasycards_info', 'Set gespeichert.');

        return Response::redirect('/admin/fantasy-cards/sets/' . $setId . '/edit');
    }

    public function toggleSet(Request $request): Response
    {
        $id = (int) $request->input('set_id', '0');
        $field = (string) $request->input('field', '');
        $enabled = $this->toBool($request->inputRaw('enabled', 0));
        if ($id <= 0 || !$this->repository->toggleSet($id, $field, $enabled)) {
            return $this->json(['ok' => false, 'message' => 'Set konnte nicht aktualisiert werden.'], 422);
        }

        return $this->json(['ok' => true, 'enabled' => $enabled]);
    }

    public function saveCard(Request $request): Response
    {
        $id = (int) $request->input('card_id', '0');
        $existing = $id > 0 ? $this->repository->findCardById($id) : null;
        if ($id > 0 && $existing === null) {
            $this->session->flash('fantasycards_error', 'Karte nicht gefunden.');
            return Response::redirect('/admin/fantasy-cards/cards');
        }

        $payload = $this->cardPayload($request, $existing);
        if ($payload['error'] !== null) {
            $this->session->flash('fantasycards_error', $payload['error']);
            return Response::redirect($id > 0 ? '/admin/fantasy-cards/cards/' . $id . '/edit' : '/admin/fantasy-cards/cards/create');
        }

        $cardId = $this->repository->saveCard($payload['data'], $id > 0 ? $id : null);
        $this->session->flash('fantasycards_info', 'Karte gespeichert.');

        return Response::redirect('/admin/fantasy-cards/cards/' . $cardId . '/edit');
    }

    public function toggleCard(Request $request): Response
    {
        $id = (int) $request->input('card_id', '0');
        $field = (string) $request->input('field', '');
        $enabled = $this->toBool($request->inputRaw('enabled', 0));
        if ($id <= 0 || !$this->repository->toggleCard($id, $field, $enabled)) {
            return $this->json(['ok' => false, 'message' => 'Karte konnte nicht aktualisiert werden.'], 422);
        }

        return $this->json(['ok' => true, 'enabled' => $enabled]);
    }

    public function uploadForm(Request $request): Response
    {
        return new Response(View::render('@modulnest.fantasy-cards/admin-upload', $this->adminData($request, [
            'title' => 'Fantasy Cards Upload',
            'sets' => $this->repository->listSetsForAdmin(),
        ])));
    }

    public function uploadCards(Request $request): Response
    {
        if ($this->isMultipartBodyTooLarge()) {
            return $this->json([
                'ok' => false,
                'message' => 'Upload zu groß. Die ausgewählten Dateien überschreiten das aktuelle Serverlimit von ' . $this->formatBytes($this->postMaxBytes()) . '.',
            ], 413);
        }

        $setId = (int) $request->input('set_id', '0');
        $files = $this->uploadedFiles('cards');
        if ($setId <= 0 || $files === []) {
            return $this->json(['ok' => false, 'message' => 'Bitte Set und mindestens eine Datei auswählen.'], 422);
        }

        $results = [];
        $allCards = [];
        $allErrors = [];
        $created = 0;
        foreach ($files as $file) {
            $result = $this->uploadService->handleUpload($setId, $file);
            $results[] = $result;
            $created += count($result['card_ids']);
            $allCards = array_merge($allCards, $result['cards']);
            $allErrors = array_merge($allErrors, $result['errors']);
            if (!$result['ok'] && $result['message'] !== '') {
                $allErrors[] = $result['message'];
            }
        }

        return $this->json([
            'ok' => $created > 0,
            'message' => $created . ' Karte(n) als Draft erzeugt.',
            'created' => $created,
            'cards' => array_map([$this, 'cardJson'], $allCards),
            'errors' => array_values(array_unique($allErrors)),
            'results' => $results,
        ], $created > 0 ? 200 : 422);
    }

    public function updateCardInline(Request $request): Response
    {
        $cardId = (int) $request->input('card_id', '0');
        $field = (string) $request->input('field', '');
        $value = $this->normalizeInlineValue($field, $request->inputRaw('value', ''));
        if ($cardId <= 0 || $value['valid'] !== true || !$this->repository->updateCardField($cardId, $field, $value['value'])) {
            return $this->json(['ok' => false, 'message' => $value['message'] ?? 'Karte konnte nicht gespeichert werden.'], 422);
        }

        return $this->json(['ok' => true, 'message' => 'Gespeichert.', 'value' => $value['value']]);
    }

    public function reorderCards(Request $request): Response
    {
        $setId = (int) $request->inputRaw('set_id', 0);
        $cardIds = $request->inputRaw('card_ids', []);
        if ($setId <= 0 || !is_array($cardIds)) {
            return $this->json(['ok' => false, 'message' => 'Ungültige Sortierung.'], 422);
        }

        $this->repository->reorderCards($setId, array_map('intval', $cardIds));
        return $this->json(['ok' => true, 'message' => 'Sortierung gespeichert.']);
    }

    public function bulkCards(Request $request): Response
    {
        $cardIds = $request->inputRaw('card_ids', []);
        if (!is_array($cardIds)) {
            return $this->json(['ok' => false, 'message' => 'Keine Karten ausgewählt.'], 422);
        }

        $cardIds = array_values(array_unique(array_filter(array_map('intval', $cardIds), static fn (int $id): bool => $id > 0)));
        if ($cardIds === []) {
            return $this->json(['ok' => false, 'message' => 'Keine Karten ausgewählt.'], 422);
        }

        $action = (string) $request->input('bulk_action', '');
        try {
            $result = match ($action) {
                'status' => $this->bulkStatus($cardIds, (string) $request->input('bulk_status', '')),
                'active' => $this->bulkBoolean($cardIds, 'is_active', (string) $request->input('bulk_enabled', '')),
                'booster' => $this->bulkBoolean($cardIds, 'available_in_boosters', (string) $request->input('bulk_enabled', '')),
                'delete' => $this->bulkDelete($cardIds),
                default => ['ok' => false, 'message' => 'Bitte eine Bulk-Aktion auswählen.'],
            };
        } catch (RuntimeException $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->json($result, ($result['ok'] ?? false) === true ? 200 : 422);
    }

    private function adminCards(Request $request): Response
    {
        $setId = (int) ($request->query('set', '0') ?? '0');
        $search = trim((string) ($request->query('q', '') ?? ''));
        $status = (string) ($request->query('status', '') ?? '');
        $page = max(1, (int) ($request->query('page', '1') ?? '1'));
        $perPage = 30;
        $total = $this->repository->countCardsForAdmin($setId > 0 ? $setId : null, $search, $status);

        return new Response(View::render('@modulnest.fantasy-cards/admin-cards', $this->adminData($request, [
            'title' => 'Fantasy Cards Karten',
            'sets' => $this->repository->listSetsForAdmin(),
            'cards' => $this->repository->listCardsForAdmin($setId > 0 ? $setId : null, $search, $status, $perPage, ($page - 1) * $perPage),
            'selected_set_id' => $setId,
            'search' => $search,
            'status' => $status,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'rarities' => FantasyCardsRarity::all(),
        ])));
    }

    private function setForm(Request $request, ?int $id): Response
    {
        $set = $id === null ? null : $this->repository->findSetById($id);
        if ($id !== null && $set === null) {
            return $this->notFound($request);
        }

        return new Response(View::render('@modulnest.fantasy-cards/admin-set-form', $this->adminData($request, [
            'title' => $id === null ? 'Set anlegen' : 'Set bearbeiten',
            'set' => $set,
        ])));
    }

    private function cardForm(Request $request, ?int $id): Response
    {
        $card = $id === null ? null : $this->repository->findCardById($id);
        if ($id !== null && $card === null) {
            return $this->notFound($request);
        }

        return new Response(View::render('@modulnest.fantasy-cards/admin-card-form', $this->adminData($request, [
            'title' => $id === null ? 'Karte anlegen' : 'Karte bearbeiten',
            'card' => $card,
            'sets' => $this->repository->listSetsForAdmin(),
            'rarities' => FantasyCardsRarity::all(),
        ])));
    }

    /**
     * @param array<int, int> $cardIds
     * @return array<string, mixed>
     */
    private function bulkStatus(array $cardIds, string $status): array
    {
        if (!in_array($status, ['draft', 'active', 'retired'], true)) {
            throw new RuntimeException('Ungültiger Status.');
        }

        $result = $this->repository->bulkUpdateCards($cardIds, 'status', $status);
        return [
            'ok' => $result['matched'] > 0,
            'message' => $result['matched'] . ' Karte(n) auf Status "' . $this->cardStatusLabel($status) . '" gesetzt.',
            'matched' => $result['matched'],
            'changed' => $result['changed'],
        ];
    }

    /**
     * @param array<int, int> $cardIds
     * @return array<string, mixed>
     */
    private function bulkBoolean(array $cardIds, string $field, string $enabled): array
    {
        if (!in_array($enabled, ['0', '1'], true)) {
            throw new RuntimeException('Ungültiger Zielwert.');
        }

        $value = $enabled === '1' ? 1 : 0;
        $result = $this->repository->bulkUpdateCards($cardIds, $field, $value);
        $label = $field === 'is_active' ? 'Aktivstatus' : 'Booster-Verfügbarkeit';
        return [
            'ok' => $result['matched'] > 0,
            'message' => $label . ' für ' . $result['matched'] . ' Karte(n) aktualisiert.',
            'matched' => $result['matched'],
            'changed' => $result['changed'],
        ];
    }

    /**
     * @param array<int, int> $cardIds
     * @return array<string, mixed>
     */
    private function bulkDelete(array $cardIds): array
    {
        $result = $this->repository->bulkDeleteCards($cardIds);
        $this->uploadService->deleteCardAssets($result['deleted_cards']);
        $message = $result['deleted'] . ' Karte(n) gelöscht.';
        if ($result['skipped'] > 0) {
            $message .= ' ' . $result['skipped'] . ' Karte(n) wurden übersprungen, weil sie bereits in Booster-Öffnungen referenziert sind.';
        }

        return [
            'ok' => $result['deleted'] > 0,
            'message' => $message,
            'deleted' => $result['deleted'],
            'skipped' => $result['skipped'],
        ];
    }

    private function cardStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktiv',
            'retired' => 'Archiviert',
            default => 'Draft',
        };
    }

    /**
     * @return array{data: array<string, mixed>, error: ?string}
     */
    private function setPayload(Request $request, ?array $existing): array
    {
        $name = trim((string) $request->input('name', ''));
        $slug = $this->slugify((string) $request->input('slug', ''));
        if ($slug === '') {
            $slug = $this->slugify($name);
        }
        if ($name === '' || $slug === '') {
            return ['data' => [], 'error' => 'Name und Slug sind erforderlich.'];
        }

        $id = (int) ($existing['id'] ?? 0);
        if ($this->repository->setSlugExists($slug, $id)) {
            return ['data' => [], 'error' => 'Dieser Set-Slug ist bereits vergeben.'];
        }

        return ['data' => [
            'uuid' => (string) ($existing['uuid'] ?? $this->uuid()),
            'slug' => $slug,
            'name' => mb_substr($name, 0, 160),
            'description' => trim((string) $request->input('description', '')),
            'cover_image' => trim((string) $request->input('cover_image', '')),
            'is_active' => $this->toBool($request->inputRaw('is_active', 0)) ? 1 : 0,
            'available_in_free_packs' => $this->toBool($request->inputRaw('available_in_free_packs', 0)) ? 1 : 0,
            'sort_order' => (int) $request->input('sort_order', '0'),
        ], 'error' => null];
    }

    /**
     * @return array{data: array<string, mixed>, error: ?string}
     */
    private function cardPayload(Request $request, ?array $existing): array
    {
        $setId = (int) $request->input('set_id', '0');
        $set = $this->repository->findSetById($setId);
        $name = trim((string) $request->input('name', ''));
        $slug = $this->slugify((string) $request->input('slug', ''));
        if ($slug === '') {
            $slug = $this->slugify($name);
        }
        if ($set === null || $name === '' || $slug === '') {
            return ['data' => [], 'error' => 'Set, Name und Slug sind erforderlich.'];
        }

        $id = (int) ($existing['id'] ?? 0);
        if ($this->repository->cardSlugExists($setId, $slug, $id)) {
            return ['data' => [], 'error' => 'Dieser Karten-Slug ist im Set bereits vergeben.'];
        }

        return ['data' => [
            'uuid' => (string) ($existing['uuid'] ?? $this->uuid()),
            'set_id' => $setId,
            'card_number' => mb_substr(trim((string) $request->input('card_number', '')), 0, 40),
            'slug' => $slug,
            'name' => mb_substr($name, 0, 160),
            'description' => trim((string) $request->input('description', '')),
            'rarity' => FantasyCardsRarity::normalize((string) $request->input('rarity', 'common')),
            'faction' => $this->nullableString((string) $request->input('faction', '')),
            'element_name' => $this->nullableString((string) $request->input('element_name', '')),
            'image_path' => trim((string) $request->input('image_path', '')),
            'thumbnail_path' => trim((string) $request->input('thumbnail_path', '')),
            'status' => $this->normalizeCardStatus((string) $request->input('status', 'active')),
            'is_active' => $this->toBool($request->inputRaw('is_active', 0)) ? 1 : 0,
            'available_in_boosters' => $this->toBool($request->inputRaw('available_in_boosters', 0)) ? 1 : 0,
            'sort_order' => (int) $request->input('sort_order', '0'),
        ], 'error' => null];
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 120);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function uploadedFiles(string $key): array
    {
        $entry = $_FILES[$key] ?? null;
        if (!is_array($entry)) {
            return [];
        }

        if (is_array($entry['name'] ?? null)) {
            $files = [];
            foreach ($entry['name'] as $index => $name) {
                $files[] = [
                    'name' => $name,
                    'type' => $entry['type'][$index] ?? '',
                    'tmp_name' => $entry['tmp_name'][$index] ?? '',
                    'error' => $entry['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $entry['size'][$index] ?? 0,
                ];
            }
            return $files;
        }

        return [$entry];
    }

    /**
     * @return array{valid:bool,value:mixed,message?:string}
     */
    private function normalizeInlineValue(string $field, mixed $value): array
    {
        if (in_array($field, ['is_active', 'available_in_boosters'], true)) {
            return ['valid' => true, 'value' => $this->toBool($value) ? 1 : 0];
        }
        if ($field === 'sort_order') {
            return ['valid' => true, 'value' => (int) $value];
        }
        if ($field === 'rarity') {
            return ['valid' => true, 'value' => FantasyCardsRarity::normalize((string) $value)];
        }
        if ($field === 'status') {
            return ['valid' => true, 'value' => $this->normalizeCardStatus((string) $value)];
        }
        if (in_array($field, ['name', 'description', 'faction', 'element_name', 'card_number'], true)) {
            $string = trim((string) $value);
            if ($field === 'name' && $string === '') {
                return ['valid' => false, 'value' => '', 'message' => 'Name darf nicht leer sein.'];
            }
            return ['valid' => true, 'value' => $field === 'description' ? $string : mb_substr($string, 0, 160)];
        }

        return ['valid' => false, 'value' => null, 'message' => 'Feld ist nicht editierbar.'];
    }

    private function normalizeCardStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['draft', 'active', 'retired'], true) ? $status : 'draft';
    }

    /**
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private function cardJson(array $card): array
    {
        return [
            'id' => (int) ($card['id'] ?? 0),
            'name' => (string) ($card['name'] ?? ''),
            'status' => (string) ($card['status'] ?? 'draft'),
            'rarity' => (string) ($card['rarity'] ?? 'common'),
            'image_path' => (string) ($card['image_path'] ?? ''),
            'thumbnail_path' => (string) ($card['thumbnail_path'] ?? ''),
            'card_number' => (string) ($card['card_number'] ?? ''),
            'description' => (string) ($card['description'] ?? ''),
            'set_name' => (string) ($card['set_name'] ?? ''),
        ];
    }

    private function currentUserId(): int
    {
        $user = $this->auth?->currentUser();
        $userId = (int) (($user['id'] ?? 0) ?: 0);
        if ($userId <= 0) {
            $userId = (int) $this->session->get('auth_user_id', 0);
        }

        return $userId;
    }

    private function userTimezoneName(): string
    {
        try {
            return $this->auth?->resolveUserTimezoneName() ?? 'UTC';
        } catch (Throwable) {
            return 'UTC';
        }
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function isMultipartBodyTooLarge(): bool
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMaxBytes = $this->postMaxBytes();

        return $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes;
    }

    private function postMaxBytes(): int
    {
        return $this->iniSizeToBytes((string) ini_get('post_max_size'));
    }

    private function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024 / 1024, 2, ',', '.'), '0'), ',') . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, ',', '.'), '0'), ',') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, ',', '.'), '0'), ',') . ' KB';
        }

        return $bytes . ' Byte';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function viewData(Request $request, array $data): array
    {
        return array_merge([
            'current_path' => $request->path(),
            'module_nav_items' => $this->subnavigation->items($request->path()),
            'module_nav_label' => 'Fantasy Cards Navigation',
            'access' => $this->access,
        ], $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function adminData(Request $request, array $data): array
    {
        return array_merge($this->viewData($request, [
            'admin_section' => 'fantasy-cards',
            'message' => $this->session->pullFlash('fantasycards_info'),
            'error' => $this->session->pullFlash('fantasycards_error'),
        ]), $data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): Response
    {
        return new Response((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    private function notFound(Request $request): Response
    {
        return new Response(View::render('errors/404', [
            'title' => '404 Not Found',
            'current_path' => $request->path(),
        ]), 404);
    }
}

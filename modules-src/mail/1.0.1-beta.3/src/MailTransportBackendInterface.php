<?php

declare(strict_types=1);

namespace ModulNest\Mail;

interface MailTransportBackendInterface
{
    /**
     * @param array<string, mixed> $connection
     * @return array<int, string>
     */
    public function listFolders(array $connection): array;

    /**
     * @param array<string, mixed> $connection
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool, next_until_uid: ?int}
     */
    public function listMessages(
        array $connection,
        string $folder,
        int $limit = 50,
        ?int $untilUid = null,
        string $direction = 'desc'
    ): array;

    /**
     * @param array<string, mixed> $connection
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool, next_until_uid: ?int}
     */
    public function listMessagesBySender(
        array $connection,
        string $folder,
        string $senderKey,
        int $limit = 50,
        ?int $untilUid = null,
        string $direction = 'desc'
    ): array;

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string> $excludedSenderKeys
     * @return array<int, array{sender_key:string,sender_label:string,total_count:int,unread_count:int,latest_timestamp:int,latest_date_label:string}>
     */
    public function listSenderStats(
        array $connection,
        string $folder,
        array $excludedSenderKeys = []
    ): array;

    /**
     * @param array<string, mixed> $connection
     * @return array<int, int>
     */
    public function listFolderUids(array $connection, string $folder): array;

    /**
     * @param array<string, mixed> $connection
     * @param array<int, int> $uids
     * @return array<int, array<string, mixed>>
     */
    public function fetchMessageMetadata(array $connection, string $folder, array $uids): array;

    /**
     * @param array<string, mixed> $connection
     * @param array<int, int> $uids
     * @return array<int, bool>
     */
    public function fetchMessageReadFlags(array $connection, string $folder, array $uids): array;

    /**
     * @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    public function getMessageDetail(array $connection, string $folder, int $uid): array;

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     */
    public function sendMessage(array $connection, array $payload): void;
}

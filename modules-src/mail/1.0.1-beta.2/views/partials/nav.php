<?php
declare(strict_types=1);

$mailSection = (string) ($mail_section ?? 'workspace');
?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link<?= $mailSection === 'workspace' ? ' active' : '' ?>" href="/mail">Arbeitsbereich</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $mailSection === 'compose' ? ' active' : '' ?>" href="/mail/compose">Verfassen</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $mailSection === 'accounts' ? ' active' : '' ?>" href="/mail/accounts">Konten &amp; Ordner</a>
    </li>
</ul>

document.addEventListener('DOMContentLoaded', function () {
    // --- Directory Picker ---
    const dirPickerModal = document.getElementById('mirrorDirectoryPickerModal');
    const dirListContainer = document.getElementById('mirror-picker-list');
    const dirLoading = document.getElementById('mirror-picker-loading');
    const dirCurrentPath = document.getElementById('mirror-picker-current-path');
    const dirParentBtn = document.getElementById('mirror-picker-btn-parent');
    const dirSelectedLabel = document.getElementById('mirror-picker-selected-label');
    const dirChooseBtn = document.getElementById('mirror-picker-btn-choose');
    const targetPathInput = document.getElementById('mirror_target_path');

    let currentNavPath = '';
    let currentParentPath = null;
    let selectedPath = '';

    function loadDirectories(path) {
        if (!dirListContainer || !dirLoading) return;
        dirLoading.classList.remove('d-none');
        dirListContainer.innerHTML = '';

        fetch('/admin/mirror/directories?path=' + encodeURIComponent(path || ''))
            .then(res => res.json())
            .then(data => {
                dirLoading.classList.add('d-none');
                if (data.error) {
                    dirListContainer.innerHTML = '<div class="p-3 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>' + data.error + '</div>';
                    return;
                }

                currentNavPath = data.current || '';
                currentParentPath = data.parent || null;
                selectedPath = currentNavPath;

                if (dirCurrentPath) dirCurrentPath.textContent = currentNavPath;
                if (dirSelectedLabel) dirSelectedLabel.textContent = currentNavPath;

                if (dirParentBtn) {
                    dirParentBtn.disabled = !currentParentPath;
                }

                const dirs = data.directories || [];
                if (dirs.length === 0) {
                    dirListContainer.innerHTML = '<div class="p-3 text-body-secondary small text-center"><i class="bi bi-folder me-1"></i>Keine weiteren Unterordner vorhanden</div>';
                } else {
                    dirs.forEach(name => {
                        const fullPath = currentNavPath + '/' + name;
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action d-flex align-items-center justify-content-between p-2';
                        item.innerHTML = `
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-folder-fill text-warning"></i>
                                <span class="small font-monospace">${name}</span>
                            </div>
                            <span class="badge text-bg-light border small"><i class="bi bi-chevron-right"></i></span>
                        `;
                        item.addEventListener('click', () => {
                            loadDirectories(fullPath);
                        });
                        dirListContainer.appendChild(item);
                    });
                }
            })
            .catch(err => {
                dirLoading.classList.add('d-none');
                dirListContainer.innerHTML = '<div class="p-3 text-danger small">Fehler beim Laden der Verzeichnisse.</div>';
            });
    }

    if (dirPickerModal) {
        dirPickerModal.addEventListener('show.bs.modal', function () {
            const initial = (targetPathInput && targetPathInput.value) ? targetPathInput.value : '';
            loadDirectories(initial);
        });

        if (dirParentBtn) {
            dirParentBtn.addEventListener('click', function () {
                if (currentParentPath) {
                    loadDirectories(currentParentPath);
                }
            });
        }

        if (dirChooseBtn) {
            dirChooseBtn.addEventListener('click', function () {
                if (targetPathInput && selectedPath) {
                    targetPathInput.value = selectedPath;
                }
                const modalInstance = bootstrap.Modal.getInstance(dirPickerModal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            });
        }
    }

    // --- Sync Trigger & Status Polling ---
    const syncButtons = document.querySelectorAll('.btn-trigger-sync');
    let pollInterval = null;

    function pollStatus() {
        fetch('/admin/mirror/status?_t=' + new Date().getTime(), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                const mirrors = data.mirrors || {};
                let hasRunning = false;

                Object.values(mirrors).forEach(m => {
                    const card = document.getElementById('mirror-card-' + m.id);
                    if (!card) return;

                    const statusWrapper = card.querySelector('.mirror-status-wrapper');
                    const syncBtn = card.querySelector('.btn-trigger-sync');
                    const syncTimestamp = card.querySelector('.sync-timestamp');
                    const btnText = syncBtn ? syncBtn.querySelector('.sync-btn-text') : null;

                    if (m.status === 'running') {
                        hasRunning = true;
                        if (statusWrapper) {
                            statusWrapper.innerHTML = '<span class="badge text-bg-info d-flex align-items-center gap-1 status-badge"><span class="spinner-border spinner-border-sm" role="status"></span><span>Synchronisiert...</span></span>';
                        }
                        if (syncBtn) syncBtn.disabled = true;
                        if (btnText) btnText.textContent = 'Synchronisiert...';
                    } else {
                        if (syncBtn) syncBtn.disabled = false;
                        if (btnText) btnText.textContent = 'Jetzt synchronisieren';

                        if (statusWrapper) {
                            if (m.status === 'success') {
                                statusWrapper.innerHTML = '<span class="badge text-bg-success status-badge"><i class="bi bi-check-circle me-1"></i>Synchronisiert</span>';
                            } else if (m.status === 'error') {
                                statusWrapper.innerHTML = '<span class="badge text-bg-danger status-badge" title="Fehler bei letzter Synchronisation"><i class="bi bi-exclamation-triangle me-1"></i>Fehler</span>';
                            } else {
                                statusWrapper.innerHTML = '<span class="badge text-bg-secondary status-badge">Bereit</span>';
                            }
                        }

                        if (syncTimestamp) {
                            if (m.last_synced_at) {
                                let txt = m.last_synced_at;
                                if (m.last_trigger) {
                                    const trigName = (m.last_trigger === 'cron') ? 'CronJob' : 'Manuell';
                                    txt += ' <span class="text-secondary fw-normal">(' + trigName + ')</span>';
                                }
                                syncTimestamp.innerHTML = txt;
                            } else {
                                syncTimestamp.textContent = 'Noch nie';
                            }
                        }

                        // Attach or update log button
                        let logBtn = card.querySelector('.btn-view-log');
                        if (m.last_log) {
                            if (!logBtn) {
                                const actionGroup = card.querySelector('.d-flex.flex-wrap.gap-2');
                                if (actionGroup) {
                                    logBtn = document.createElement('button');
                                    logBtn.type = 'button';
                                    logBtn.className = 'btn btn-outline-secondary btn-sm btn-view-log';
                                    logBtn.dataset.mirrorId = m.id;
                                    logBtn.innerHTML = '<i class="bi bi-file-earmark-text me-1"></i>Protokoll';
                                    actionGroup.appendChild(logBtn);
                                    setupLogButton(logBtn);
                                }
                            }
                            if (logBtn) {
                                logBtn.dataset.log = m.last_log;
                            }
                        }
                    }
                });

                if (!hasRunning && pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            })
            .catch(() => {});
    }

    syncButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const mirrorId = this.dataset.mirrorId;
            if (!mirrorId) return;

            this.disabled = true;
            const btnText = this.querySelector('.sync-btn-text');
            if (btnText) btnText.textContent = 'Synchronisiert...';

            const card = document.getElementById('mirror-card-' + mirrorId);
            if (card) {
                const statusWrapper = card.querySelector('.mirror-status-wrapper');
                if (statusWrapper) {
                    statusWrapper.innerHTML = '<span class="badge text-bg-info d-flex align-items-center gap-1 status-badge"><span class="spinner-border spinner-border-sm" role="status"></span><span>Synchronisiert...</span></span>';
                }
            }

            const csrfInput = document.querySelector('input[name="_csrf"]');
            const token = csrfInput ? csrfInput.value : '';

            const formData = new FormData();
            formData.append('id', mirrorId);
            formData.append('_csrf', token);

            fetch('/admin/mirror/sync', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(() => {
                if (!pollInterval) {
                    pollInterval = setInterval(pollStatus, 2000);
                }
            }).catch(() => {
                if (!pollInterval) {
                    pollInterval = setInterval(pollStatus, 2000);
                }
            });
        });
    });

    // Check if any mirror is already running on page load
    const runningCards = document.querySelectorAll('[data-status="running"]');
    if (runningCards.length > 0) {
        pollInterval = setInterval(pollStatus, 2000);
    }

    // --- Log Modal ---
    const logModalEl = document.getElementById('mirrorLogModal');
    const logContentEl = document.getElementById('mirrorLogContent');

    function setupLogButton(btn) {
        btn.addEventListener('click', function () {
            const logText = this.dataset.log || 'Kein Protokoll vorhanden.';
            if (logContentEl) {
                logContentEl.textContent = logText;
            }
            if (logModalEl) {
                const modal = new bootstrap.Modal(logModalEl);
                modal.show();
            }
        });
    }

    document.querySelectorAll('.btn-view-log').forEach(setupLogButton);
});

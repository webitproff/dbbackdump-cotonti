<!-- BEGIN: MAIN -->
<!-- plugins/dbbackdump/tpl/dbbackdump.admin.tpl -->
<div id="dbbackdump" data-ajax="{BACKUP_AJAX}" data-xk="{BACKUP_XK}" class="container-fluid py-3">

    <p class="text-muted small mb-3">
        {PHP.L.dbbackdump_engine}: <strong>{BACKUP_ENGINE}</strong>
    </p>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" id="btn-dump" class="btn btn-primary">
            {PHP.L.dbbackdump_create}
        </button>
        <button type="button" id="btn-cancel" class="btn btn-outline-secondary" style="display:none;">
            {PHP.L.dbbackdump_cancel}
        </button>
    </div>

    <div id="progress-box" class="mb-4" style="display:none;">
        <div id="progress-msg" class="small text-muted mb-2"></div>
        <div class="progress" role="progressbar" aria-label="{PHP.L.dbbackdump_title}"
             aria-valuemin="0" aria-valuemax="100" style="height: 26px;">
            <div id="progress-bar" class="progress-bar bg-primary"
                 style="width:0%; font-size:13px; line-height:26px; transition: width .3s;"></div>
        </div>
    </div>

    <h3 class="h5 mb-3">{PHP.L.dbbackdump_list}</h3>

    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th scope="col">{PHP.L.dbbackdump_name}</th>
                    <th scope="col">{PHP.L.dbbackdump_size}</th>
                    <th scope="col">{PHP.L.dbbackdump_date}</th>
                    <th scope="col">{PHP.L.dbbackdump_actions}</th>
                </tr>
            </thead>
            <tbody>
                <!-- BEGIN: ROW -->
                <tr>
                    <td>{BACKUP_NAME}</td>
                    <td>{BACKUP_SIZE}</td>
                    <td>{BACKUP_DATE}</td>
                    <td>
                        <a href="{BACKUP_URL_DL}" class="link-primary">{PHP.L.dbbackdump_download}</a>
                        <span class="text-muted mx-1">·</span>
                        <a href="#" class="restore link-primary" data-name="{BACKUP_NAME_ENC}">{PHP.L.dbbackdump_restore}</a>
                        <span class="text-muted mx-1">·</span>
                        <a href="{BACKUP_URL_DEL}" class="confirmLink link-danger">{PHP.L.dbbackdump_delete}</a>
                    </td>
                </tr>
                <!-- END: ROW -->
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const root = document.getElementById('dbbackdump');
    if (!root) return;

    const AJAX = root.dataset.ajax;
    const XK   = root.dataset.xk;

    const btnDump   = document.getElementById('btn-dump');
    const btnCancel = document.getElementById('btn-cancel');
    const box       = document.getElementById('progress-box');
    const msg       = document.getElementById('progress-msg');
    const bar       = document.getElementById('progress-bar');

    let pollTimer = null;

    function api(action, extra = '') {
        const url = AJAX + '&a=' + action + '&x=' + encodeURIComponent(XK) + (extra ? '&' + extra : '');
        return fetch(url, {credentials: 'same-origin', cache: 'no-store'})
            .then(r => r.json())
            .then(j => {
                if (!j.ok) throw new Error(j.error || 'Error');
                return j.data;
            });
    }

    function showProgress(text, pct) {
        box.style.display = '';
        msg.textContent = text || '';
        bar.style.width = (pct || 0) + '%';
        bar.textContent = (pct || 0) + '%';
    }

    function hideProgress() {
        box.style.display = 'none';
        btnCancel.style.display = 'none';
        btnDump.disabled = false;
    }

    function startPolling() {
        btnDump.disabled = true;
        btnCancel.style.display = '';
        pollTimer = setInterval(async () => {
            try {
                const s = await api('status');
                if (s.status === 'running') {
                    showProgress(s.message || 'Working…', s.percent || 0);
                } else if (s.status === 'done') {
                    clearInterval(pollTimer);
                    showProgress(s.message || 'Done', 100);
                    setTimeout(() => location.reload(), 800);
                } else if (s.status === 'error' || s.status === 'cancelled') {
                    clearInterval(pollTimer);
                    alert(s.error || s.message || 'Stopped');
                    hideProgress();
                }
            } catch (e) {
                clearInterval(pollTimer);
                alert(e.message);
                hideProgress();
            }
        }, 1200);
    }

    btnDump.addEventListener('click', async () => {
        try {
            showProgress('Starting…', 0);
            await api('start_dump');
            startPolling();
        } catch (e) {
            alert(e.message);
            hideProgress();
        }
    });

    btnCancel.addEventListener('click', () => api('cancel'));

    document.querySelectorAll('.restore').forEach(el => {
        el.addEventListener('click', async e => {
            e.preventDefault();
            if (!confirm('{PHP.L.dbbackdump_confirm_restore}')) return;
            try {
                showProgress('Restoring…', 0);
                await api('start_restore', 'name=' + encodeURIComponent(el.dataset.name));
                startPolling();
            } catch (err) {
                alert(err.message);
                hideProgress();
            }
        });
    });

    // при загрузке страницы проверяем, есть ли активная задача
    api('status').then(s => {
        if (s.status === 'running') startPolling();
    }).catch(() => {});
})();
</script>
<!-- END: MAIN -->

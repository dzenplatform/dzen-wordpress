(() => {
    document.querySelectorAll('.dzen-page-index').forEach((root) => {
        const button = root.querySelector('.dzen-page-index-refresh');
        const label = root.querySelector('.dzen-page-index-label');
        const description = root.querySelector('.dzen-page-index-description');
        const sync = root.querySelector('.dzen-page-index-sync');
        let running = false;
        let refreshAgain = false;
        const refresh = async () => {
            if (running) { refreshAgain = true; return; }
            running = true;
            button.disabled = true;
            root.dataset.state = 'loading';
            label.textContent = root.dataset.loading;
            description.textContent = '';
            sync.textContent = '';
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 20000);
            let serviceError = '';
            try {
                const response = await fetch(root.dataset.endpoint, {
                    method: 'POST', credentials: 'same-origin', signal: controller.signal,
                    body: new URLSearchParams({action: 'dzen_chat_page_status', post_id: root.dataset.postId, _ajax_nonce: root.dataset.nonce}),
                });
                const payload = await response.json();
                if (!response.ok || payload.success !== true) {
                    serviceError = typeof payload.data?.message === 'string' ? payload.data.message : '';
                    throw new Error();
                }
                root.dataset.state = payload.data.state;
                label.textContent = payload.data.label;
                description.textContent = payload.data.description;
                sync.textContent = payload.data.sync;
            } catch {
                root.dataset.state = 'unavailable';
                label.textContent = root.dataset.error;
                description.textContent = serviceError;
            } finally {
                clearTimeout(timeout);
                button.disabled = false;
                running = false;
                if (refreshAgain) { refreshAgain = false; refresh(); }
            }
        };
        button.addEventListener('click', refresh);
        refresh();
        // Gutenberg saves without reloading PHP meta boxes. Classic editors reload normally.
        let wasSaving = false;
        wp.data.subscribe(() => {
            const editor = wp.data.select('core/editor');
            if (!editor) return;
            const saving = editor.isSavingPost() && !editor.isAutosavingPost();
            const saved = wasSaving && !saving && editor.didPostSaveRequestSucceed();
            wasSaving = saving;
            if (saved) refresh();
        });
    });
})();

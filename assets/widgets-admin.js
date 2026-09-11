(() => {
    'use strict';

    const button = document.getElementById('dzen-add-widget');
    const panel = document.getElementById('dzen-create-widget');
    if (!button || !panel) return;

    const name = panel.querySelector('input[name="name"]');
    const cancel = panel.querySelector('.dzen-widget-create-cancel');
    const setOpen = (open) => {
        panel.hidden = !open;
        button.setAttribute('aria-expanded', String(open));
        (open ? name : button).focus();
    };

    button.addEventListener('click', () => setOpen(panel.hidden));
    cancel.addEventListener('click', () => setOpen(false));
    panel.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
        }
    });
})();

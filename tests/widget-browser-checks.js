// Run with Playwright CLI run-code on the isolated fixture, logged in as dzen_test.
async (page) => {
    const base = new URL(page.url()).origin;
    const adminUrl = base + '/wp-admin/admin.php?page=dzen-chat-widgets';
    const checks = [];
    const check = (ok, label) => {
        if (!ok) throw new Error(label);
        checks.push(label);
    };
    await page.goto(adminUrl);
    check(await page.getByText('Local test environment:', { exact: false }).isVisible(),
        'admin makes the fixture boundary explicit');
    const originalChoice = await page.locator('.dzen-widget-selection input[type=radio]:checked').inputValue();
    const guestContext = await page.context().browser().newContext();
    const guest = await guestContext.newPage();
    const browserErrors = [];
    guest.on('pageerror', error => browserErrors.push(error.message));
    const select = async (id) => {
        const radio = id
            ? page.locator('#dzen-widget-' + id)
            : page.getByRole('radio', { name: 'No site-wide widget', exact: true });
        await radio.check();
        await Promise.all([
            page.waitForURL(url => url.href.includes('notice=saved')),
            page.getByRole('button', { name: 'Save selection', exact: true }).click(),
        ]);
        await page.waitForLoadState('load');
    };
    try {
        const table = page.getByRole('table', { name: 'Available project widgets' });
        check(await table.isVisible(), 'project widgets are presented as one selection list');
        const first = table.locator('input[type=radio]:not(:disabled)').first();
        const id = await first.inputValue();
        const row = table.getByRole('row').filter({ has: page.locator('#dzen-widget-' + id) });
        const settings = row.getByRole('link', { name: /^Edit in Dzen Chat ↗:/ });
        const editor = new URL(await settings.getAttribute('href'));
        check(editor.origin === 'https://chat.dzen.dev' && editor.pathname.endsWith('/widgets/' + id)
            && await settings.getAttribute('target') === '_blank', 'editor link identifies this widget in Dzen Chat');
        const add = table.getByRole('button', { name: 'Add widget', exact: true });
        const creation = page.locator('#dzen-create-widget');
        check(await add.isVisible() && !await creation.isVisible(),
            'widget creation starts collapsed beside the list');
        await add.click();
        check(await creation.isVisible() && await add.getAttribute('aria-expanded') === 'true'
            && await creation.getByRole('textbox', { name: 'Name', exact: true }).evaluate(input => input === document.activeElement),
            'Add widget opens the form and focuses the name');
        await creation.getByRole('button', { name: 'Cancel', exact: true }).click();
        check(!await creation.isVisible() && await add.evaluate(button => button === document.activeElement),
            'Cancel returns focus to the list action');
        await add.click();
        await page.keyboard.press('Escape');
        check(!await creation.isVisible() && await add.getAttribute('aria-expanded') === 'false',
            'Escape closes widget creation');
        check(await page.locator('.dzen-widget-selection input[type=radio]:checked').inputValue() === originalChoice,
            'opening and cancelling creation preserves the site widget');
        await select(id);
        await page.reload();
        check(await page.locator('#dzen-widget-' + id).isChecked(), 'saved selection survives a page reload');
        await guest.goto(base + '/');
        const launcher = guest.getByRole('button', { name: 'Dzen Chat · test', exact: true });
        await launcher.waitFor({ state: 'visible' });
        check(await guest.locator('#chat-chat').count() === 1
            && await guest.locator('#dzen-chat-widget-js').count() === 1,
            'selected widget has one public mount and loader');
        check(await guest.locator('#wpadminbar').count() === 0, 'public check uses a guest session');
        await launcher.click();
        const dialog = guest.getByRole('dialog', { name: 'Dzen Chat — local test' });
        check(await dialog.isVisible(), 'selected widget opens the labelled fixture panel');
        await guest.keyboard.press('Escape');
        check(!await dialog.isVisible(), 'Escape closes the panel');
        await select('');
        await guest.reload();
        check(await guest.locator('#chat-chat, #dzen-chat-widget-js').count() === 0,
            'explicit no-widget selection removes the complete embed');
        await select(id);
        await guest.reload();
        check(await launcher.isVisible(), 'selecting the widget again restores it');
        check(browserErrors.length === 0, 'no public JavaScript errors');
    } finally {
        await page.goto(adminUrl);
        await select(originalChoice);
        await guestContext.close();
    }
    return { passed: checks.length, checks };
}

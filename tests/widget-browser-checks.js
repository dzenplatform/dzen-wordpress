// Run with Playwright CLI run-code on the local fixture, logged in as dzen_test.
async (page) => {
    const base = 'http://127.0.0.1:8868';
    const adminUrl = base + '/wp-admin/admin.php?page=dzen-chat-widgets';
    const checks = [];
    const check = (ok, label) => {
        if (!ok) throw new Error(label);
        checks.push(label);
    };
    await page.goto(adminUrl);
    const card = page.locator('section.dzen-message').filter({ has: page.getByRole('heading', { name: 'Помощник сайта', exact: true }) });
    const originalWelcome = await card.getByRole('textbox', { name: 'Приветствие' }).inputValue();
    const guestContext = await page.context().browser().newContext();
    const guest = await guestContext.newPage();
    const browserErrors = [];
    guest.on('pageerror', error => browserErrors.push(error.message));
    const submit = async (button) => {
        await Promise.all([page.waitForURL(url => url.href.includes('notice=saved')), button.click()]);
        await page.waitForLoadState('load');
    };
    try {
        check(await page.getByText('Локальный тестовый стенд:', { exact: false }).isVisible(), 'admin makes fixture boundary explicit');
        await submit(card.getByRole('button', { name: 'Выключить в Dzen Chat', exact: true }));
        await page.reload();
        check(await card.getByRole('button', { name: 'Включить в Dzen Chat', exact: true }).isVisible(), 'disabled state persists after admin reload');
        await guest.goto(base + '/');
        check(await guest.locator('#chat-chat, #dzen-chat-widget-js').count() === 0, 'disabled widget is absent on the public page');

        await submit(card.getByRole('button', { name: 'Включить в Dzen Chat', exact: true }));
        const embed = guest.waitForResponse(response => response.url().includes('dzen_fixture_widget=fixture-widget'));
        await guest.reload();
        check((await embed).status() === 200, 'public embed request succeeds');
        const launcher = guest.getByRole('button', { name: 'Dzen Chat · тест', exact: true });
        await launcher.waitFor({ state: 'visible' });
        check(await launcher.isVisible(), 'enabling selected widget displays launcher to a logged-out visitor');
        check(await guest.locator('#chat-chat').count() === 1 && await guest.locator('#dzen-chat-widget-js').count() === 1, 'single mount and single loader');
        check(await guest.locator('#wpadminbar').count() === 0, 'public check uses a guest session');
        await launcher.click();
        const dialog = guest.getByRole('dialog', { name: 'Dzen Chat — локальный тест' });
        check(await dialog.isVisible() && await dialog.getByText('Локальный тест интерфейса.', { exact: false }).isVisible(), 'launcher opens explicitly labelled local test panel');
        await guest.keyboard.press('Escape');
        check(!await dialog.isVisible(), 'Escape closes the panel');
        await guest.reload();
        check(await launcher.isVisible(), 'public widget survives page reload');

        await card.getByRole('textbox', { name: 'Приветствие' }).fill('Проверка сохранённого приветствия\n<script>window.widgetXss=1</script>');
        await submit(card.getByRole('button', { name: 'Сохранить настройки', exact: true }));
        await guest.reload();
        await launcher.click();
        check(await dialog.getByText('Проверка сохранённого приветствия', { exact: true }).isVisible(), 'saved widget settings reach public embed');
        check(await dialog.locator('script').count() === 0 && await guest.evaluate(() => !window.widgetXss),
            'configured welcome cannot execute script');

        await submit(page.getByRole('button', { name: 'Не вставлять виджет на сайте', exact: true }));
        await guest.reload();
        check(await guest.locator('#chat-chat, #dzen-chat-widget-js').count() === 0, 'remove from site omits complete embed');
        check(await page.getByText('Виджет для сайта не выбран.', { exact: false }).isVisible(), 'admin explains unselected widget');
        await submit(card.getByRole('button', { name: 'Разместить на сайте', exact: true }));
        await guest.reload();
        check(await launcher.isVisible(), 'selecting enabled widget restores public launcher');
        check(browserErrors.length === 0, 'no public JavaScript errors');
    } finally {
        await page.goto(adminUrl);
        await card.getByRole('textbox', { name: 'Приветствие' }).fill(originalWelcome);
        await submit(card.getByRole('button', { name: 'Сохранить настройки', exact: true }));
        if (await card.getByRole('button', { name: 'Включить в Dzen Chat', exact: true }).count()) {
            await submit(card.getByRole('button', { name: 'Включить в Dzen Chat', exact: true }));
        }
        if (await card.getByRole('button', { name: 'Разместить на сайте', exact: true }).count()) {
            await submit(card.getByRole('button', { name: 'Разместить на сайте', exact: true }));
        }
        await guestContext.close();
    }
    return { passed: checks.length, checks };
}

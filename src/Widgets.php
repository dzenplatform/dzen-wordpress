<?php
namespace DzenChat;

defined('ABSPATH') || exit;

/** The implemented public Widgets API; no project or billing state is inferred here. */
final class Widgets
{
    public function __construct(private Credentials $credentials, private Api $api) {}

    private function key(string $id): string
    {
        $identity = $this->credentials->get();
        return 'dzen_chat_widget_' . hash('sha256', implode('|', [
            $identity['client_id'], Credentials::siteUrl(), Api::origin(), $id,
        ]));
    }

    private static function validate(mixed $data, ?string $id = null): array|\WP_Error
    {
        if (!is_array($data)
            || !is_string($data['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $data['id'])
            || !is_string($data['code'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $data['code'])
            || !is_string($data['name'] ?? null) || !preg_match('/\A.{1,128}\z/us', $data['name'])
            || !is_bool($data['is_enabled'] ?? null) || !is_bool($data['suggestions_enabled'] ?? null)
            || ($id !== null && $data['id'] !== $id)) {
            return new \WP_Error('dzen_widget_protocol', __('The service returned invalid widget data. Refresh the list.', 'dzen-chat'));
        }
        return array_intersect_key($data, array_flip(['id', 'code', 'name', 'is_enabled', 'suggestions_enabled']));
    }

    private function remember(array $widget): void
    {
        $key = $this->key($widget['id']);
        set_transient($key, $widget, 300);
        delete_transient($key . '_retry');
    }

    public function all(): array|\WP_Error
    {
        foreach (array_keys(get_option('dzen_chat_widgets', [])) as $id) {
            delete_transient($this->key((string) $id));
        }
        $result = $this->api->request('GET', '/widgets');
        if (is_wp_error($result)) return $result;
        if (!isset($result['items']) || !is_array($result['items']) || !array_is_list($result['items'])) {
            return new \WP_Error('dzen_widget_protocol', __('The service returned an invalid widget list.', 'dzen-chat'));
        }
        $widgets = [];
        foreach ($result['items'] as $item) {
            $widget = self::validate($item);
            if (is_wp_error($widget)) return $widget;
            if (isset($widgets[$widget['id']])) {
                return new \WP_Error('dzen_widget_protocol', __('The service response contains a duplicate widget.', 'dzen-chat'));
            }
            $widgets[$widget['id']] = $widget;
        }
        foreach (array_diff(array_keys(get_option('dzen_chat_widgets', [])), array_keys($widgets)) as $removed) {
            delete_transient($this->key((string) $removed));
        }
        foreach ($widgets as $widget) $this->remember($widget);
        update_option('dzen_chat_widgets', $widgets, false);
        return $widgets;
    }

    public function get(string $id, int $timeout = 15): array|\WP_Error
    {
        delete_transient($this->key($id));
        $result = $this->api->request('GET', '/widgets/' . Api::segment($id), timeout: $timeout);
        if (is_wp_error($result)) return $result;
        $widget = self::validate($result, $id);
        if (!is_wp_error($widget)) $this->remember($widget);
        return $widget;
    }

    /** Refresh remote enablement with bounded visitor latency, even without WP-Cron. */
    public function cached(string $id): array|\WP_Error
    {
        $key = $this->key($id);
        $widget = get_transient($key);
        if (is_array($widget)) return self::validate($widget, $id);
        if (get_transient($key . '_retry')) {
            return new \WP_Error('dzen_widget_pending', __('The widget is temporarily unavailable.', 'dzen-chat'));
        }
        set_transient($key . '_retry', true, 60);
        return $this->get($id, 3);
    }

    public function save(?string $id, array $changes): array|\WP_Error
    {
        if ($id !== null) delete_transient($this->key($id));
        $result = $this->api->request($id === null ? 'POST' : 'PATCH',
            '/widgets' . ($id === null ? '' : '/' . Api::segment($id)), [], $changes);
        if (is_wp_error($result)) return $result;
        $widget = self::validate($result, $id);
        if (is_wp_error($widget)) return $widget;
        foreach ($changes as $field => $value) {
            if (!array_key_exists($field, $widget) || $widget[$field] !== $value) {
                return new \WP_Error('dzen_widget_state', __('The service did not confirm the widget change. Refresh the list.', 'dzen-chat'));
            }
        }
        $this->remember($widget);
        $widgets = get_option('dzen_chat_widgets', []);
        $widgets[$widget['id']] = $widget;
        update_option('dzen_chat_widgets', $widgets, false);
        return $widget;
    }
}

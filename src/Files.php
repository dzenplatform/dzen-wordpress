<?php
namespace DzenChat;

defined('ABSPATH') || exit;

/** Text documents accepted by the existing JSON Files API. */
final class Files
{
    public function __construct(private Api $api) {}

    public static function uploadLimit(): int
    {
        return max(0, min(262144, wp_max_upload_size()));
    }

    public static function prepare(string $name, string $content): array|\WP_Error
    {
        $name = sanitize_file_name(wp_basename($name));
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['txt', 'md', 'markdown'], true)) {
            return new \WP_Error('file_type', __('Choose a TXT or Markdown file (.txt, .md, .markdown).', 'dzen-chat'));
        }
        if (strlen($name) > 255) return new \WP_Error('file_name', __('The filename is too long. Rename the file and try again.', 'dzen-chat'));
        if (strlen($content) > self::uploadLimit()) {
            return new \WP_Error('file_size', __('The document exceeds the upload size limit.', 'dzen-chat'));
        }
        if (!preg_match('//u', $content) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
            return new \WP_Error('file_encoding', __('The document must contain UTF-8 text. Binary files are not supported.', 'dzen-chat'));
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        if (trim($content) === '') return new \WP_Error('file_empty', __('The document is empty. Choose a file containing text.', 'dzen-chat'));
        return ['name' => $name, 'content' => $content];
    }

    public static function readUpload(mixed $upload): array|\WP_Error
    {
        if (!is_array($upload) || !is_int($upload['error'] ?? null)) {
            return new \WP_Error('file_missing', __('Choose a document to upload.', 'dzen-chat'));
        }
        if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return new \WP_Error('file_size', __('The document exceeds the upload size limit.', 'dzen-chat'));
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_string($upload['name'] ?? null)
            || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            return new \WP_Error('file_upload', __('The upload did not complete. Choose the file and try again.', 'dzen-chat'));
        }
        // Read only PHP's temporary HTTP upload. Do not copy it to the public media library.
        $content = file_get_contents($upload['tmp_name'], false, null, 0, self::uploadLimit() + 1);
        if ($content === false) return new \WP_Error('file_read', __('WordPress could not read the uploaded document.', 'dzen-chat'));
        return self::prepare($upload['name'], $content);
    }

    private static function validate(mixed $file, ?string $id = null): array|\WP_Error
    {
        if (!is_array($file) || !is_string($file['id'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $file['id'])
            || ($id !== null && $file['id'] !== $id) || !is_string($file['name'] ?? null)
            || !is_string($file['content'] ?? null) || !in_array($file['status'] ?? '', ['crawler', 'parsing', 'ready'], true)
            || !array_key_exists('status_error', $file)
            || ($file['status_error'] !== null && !is_string($file['status_error']))
            || !is_int($file['size_bytes'] ?? null) || $file['size_bytes'] < 0) {
            return new \WP_Error('file_protocol', __('The service returned invalid document data. Refresh the Index page.', 'dzen-chat'));
        }
        return $file;
    }

    public function all(): array|\WP_Error
    {
        $items = $this->api->items('/files');
        if (is_wp_error($items)) return $items;
        $files = [];
        foreach ($items as $item) {
            $file = self::validate($item);
            if (is_wp_error($file)) return $file;
            if (isset($files[$file['id']])) return new \WP_Error('file_protocol', __('The service returned duplicate documents.', 'dzen-chat'));
            $files[$file['id']] = $file;
        }
        return array_values($files);
    }

    public function get(string $id): array|\WP_Error
    {
        $file = $this->api->request('GET', '/files/' . Api::segment($id));
        return is_wp_error($file) ? $file : self::validate($file, $id);
    }

    public function create(array $document): array|\WP_Error
    {
        $result = $this->api->request('POST', '/files', [], $document);
        if (!is_wp_error($result)) $result = self::validate($result);
        if (is_wp_error($result)) {
            return new \WP_Error('file_unconfirmed', sprintf(
                /* translators: %s is the service error. */
                __('Upload was not confirmed: %s Check the document list before uploading again.', 'dzen-chat'),
                $result->get_error_message()));
        }
        if ($result['name'] !== $document['name'] || $result['content'] !== $document['content']) {
            return new \WP_Error('file_unconfirmed', __('The service did not confirm this document. Check the document list before uploading again.', 'dzen-chat'));
        }
        return $result;
    }

    public static function indexStatus(array $file): string
    {
        if ($file['status_error'] !== null && $file['status_error'] !== '') return 'errors';
        return ['crawler' => 'pending', 'parsing' => 'processing', 'ready' => 'ready'][$file['status']];
    }
}

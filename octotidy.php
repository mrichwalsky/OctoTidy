<?php
/**
 * Plugin Name: OctoTidy
 * Description: Media Attachment Verifier for finding Media Library assets that are likely unused across posts, meta, options, and builder data.
 * Version: 0.2.0
 * Author: GM8
 * License: GPL-2.0-or-later
 * Text Domain: octotidy
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OctoTidy_Media_Attachment_Verifier {
    private const OPTION_RESULTS = 'octotidy_scan_results';
    private const OPTION_SCANNED_AT = 'octotidy_scanned_at';
    private const NONCE_SCAN_ACTION = 'octotidy_scan';
    private const NONCE_EXPORT_ACTION = 'octotidy_export';
    private const NONCE_DELETE_ACTION = 'octotidy_delete_unused';
    private const REQUIRED_CAPABILITY = 'manage_options';
    private const BATCH_SIZE = 100;
    private const MAX_LOCATIONS = 10;

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_page']);
        add_action('admin_post_octotidy_scan', [__CLASS__, 'handle_scan']);
        add_action('admin_post_octotidy_export', [__CLASS__, 'handle_export']);
        add_action('admin_post_octotidy_delete_unused', [__CLASS__, 'handle_delete_unused']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('octotidy scan', [__CLASS__, 'cli_scan'], [
                'shortdesc' => 'Run the media verification and store results for Media > OctoTidy Verifier.',
            ]);
        }
    }

    public static function cli_scan(): void {
        $results = self::store_scan();
        $unused = count(array_filter($results, static function (array $row): bool {
            return !$row['used'];
        }));

        WP_CLI::success(sprintf('Checked %d attachments, %d likely unused. Review them under Media > OctoTidy Verifier.', count($results), $unused));
    }

    public static function register_admin_page(): void {
        add_media_page(
            __('OctoTidy Verifier', 'octotidy'),
            __('OctoTidy Verifier', 'octotidy'),
            self::REQUIRED_CAPABILITY,
            'octotidy',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'octotidy'));
        }

        $results = self::get_scan_results();
        $scanned_at = get_option(self::OPTION_SCANNED_AT, '');
        $unused_count = 0;
        $used_count = 0;

        foreach ($results as $row) {
            if (!empty($row['used'])) {
                $used_count++;
            } else {
                $unused_count++;
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('OctoTidy', 'octotidy'); ?></h1>
            <p><strong><?php echo esc_html__('Media Attachment Verifier', 'octotidy'); ?></strong></p>
            <p>
                <?php echo esc_html__('This MVP checks common WordPress storage locations for Media Library references. Treat "Likely unused" as review candidates, not automatic delete instructions.', 'octotidy'); ?>
            </p>
            <p>
                <?php echo esc_html__('Large library? Run the check over SSH instead so it cannot time out:', 'octotidy'); ?>
                <code>nice -n 19 wp octotidy scan</code>
            </p>

            <?php if (isset($_GET['octotidy_scanned']) && '1' === sanitize_text_field(wp_unslash($_GET['octotidy_scanned']))) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Verification complete.', 'octotidy'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['octotidy_deleted'])) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <?php
                        echo esc_html(sprintf(
                            __('Moved %d likely unused media item(s) to Trash. %d item(s) could not be moved.', 'octotidy'),
                            isset($_GET['octotidy_deleted']) ? absint(wp_unslash($_GET['octotidy_deleted'])) : 0,
                            isset($_GET['octotidy_failed']) ? absint(wp_unslash($_GET['octotidy_failed'])) : 0
                        ));
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 16px 0;">
                <input type="hidden" name="action" value="octotidy_scan">
                <?php wp_nonce_field(self::NONCE_SCAN_ACTION); ?>
                <?php submit_button(__('Run Verification', 'octotidy'), 'primary', 'submit', false); ?>
                <?php if (!empty($results)) : ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=octotidy_export'), self::NONCE_EXPORT_ACTION)); ?>">
                        <?php echo esc_html__('Export CSV', 'octotidy'); ?>
                    </a>
                <?php endif; ?>
            </form>

            <?php if ($scanned_at) : ?>
                <p>
                    <strong><?php echo esc_html__('Last check:', 'octotidy'); ?></strong>
                    <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $scanned_at)); ?>
                    &nbsp;|&nbsp;
                    <strong><?php echo esc_html__('Found:', 'octotidy'); ?></strong> <?php echo esc_html((string) $used_count); ?>
                    &nbsp;|&nbsp;
                    <strong><?php echo esc_html__('Likely unused:', 'octotidy'); ?></strong> <?php echo esc_html((string) $unused_count); ?>
                </p>
            <?php endif; ?>

            <?php if (empty($results)) : ?>
                <p><?php echo esc_html__('No scan results yet.', 'octotidy'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Status', 'octotidy'); ?></th>
                            <th><?php echo esc_html__('Media', 'octotidy'); ?></th>
                            <th><?php echo esc_html__('File', 'octotidy'); ?></th>
                            <th><?php echo esc_html__('Type', 'octotidy'); ?></th>
                            <th><?php echo esc_html__('Locations', 'octotidy'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row) : ?>
                            <tr>
                                <td>
                                    <?php if (!empty($row['used'])) : ?>
                                        <span style="color:#008a20;font-weight:600;"><?php echo esc_html__('Found', 'octotidy'); ?></span>
                                    <?php else : ?>
                                        <span style="color:#b32d2e;font-weight:600;"><?php echo esc_html__('Likely unused', 'octotidy'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $row['id'])); ?>">
                                        <?php echo esc_html($row['title']); ?>
                                    </a>
                                    <br>
                                    <code>#<?php echo esc_html((string) $row['id']); ?></code>
                                </td>
                                <td><code><?php echo esc_html($row['file']); ?></code></td>
                                <td><?php echo esc_html($row['type']); ?></td>
                                <td>
                                    <?php if (!empty($row['locations'])) : ?>
                                        <ol style="margin:0 0 0 18px;">
                                            <?php foreach (array_slice($row['locations'], 0, 5) as $location) : ?>
                                                <li><?php echo esc_html($location); ?></li>
                                            <?php endforeach; ?>
                                        </ol>
                                        <?php if (count($row['locations']) > 5) : ?>
                                            <em><?php echo esc_html(sprintf(__('%d more...', 'octotidy'), count($row['locations']) - 5)); ?></em>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <em><?php echo esc_html__('No references detected', 'octotidy'); ?></em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($unused_count > 0) : ?>
                    <hr>
                    <h2><?php echo esc_html__('Bulk Cleanup', 'octotidy'); ?></h2>
                    <p>
                        <?php echo esc_html__('This moves the current "Likely unused" verification results to the Media Library Trash. It does not permanently delete files immediately.', 'octotidy'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('<?php echo esc_js(__('Final confirmation: move all currently listed likely unused media items to Trash?', 'octotidy')); ?>');">
                        <input type="hidden" name="action" value="octotidy_delete_unused">
                        <?php wp_nonce_field(self::NONCE_DELETE_ACTION); ?>
                        <p>
                            <label>
                                <input type="checkbox" name="octotidy_confirm_reviewed" value="1" required>
                                <?php echo esc_html__('I reviewed this verification and understand these items may still be used dynamically.', 'octotidy'); ?>
                            </label>
                        </p>
                        <p>
                            <label for="octotidy_confirm_text">
                                <?php echo esc_html__('Type DELETE to confirm:', 'octotidy'); ?>
                            </label>
                            <input type="text" id="octotidy_confirm_text" name="octotidy_confirm_text" pattern="DELETE" required>
                        </p>
                        <?php submit_button(sprintf(__('Move %d Likely Unused Item(s) to Trash', 'octotidy'), $unused_count), 'delete', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_scan(): void {
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to run scans.', 'octotidy'));
        }

        check_admin_referer(self::NONCE_SCAN_ACTION);

        self::store_scan();

        wp_safe_redirect(add_query_arg('octotidy_scanned', '1', admin_url('upload.php?page=octotidy')));
        exit;
    }

    public static function handle_export(): void {
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to export scans.', 'octotidy'));
        }

        check_admin_referer(self::NONCE_EXPORT_ACTION);

        $results = self::get_scan_results();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=octotidy-media-scan.csv');
        nocache_headers();

        $output = fopen('php://output', 'w');
        if (!$output) {
            wp_die(esc_html__('Unable to open CSV output stream.', 'octotidy'));
        }

        fputcsv($output, ['ID', 'Title', 'Status', 'File', 'Type', 'Locations']);

        foreach ($results as $row) {
            fputcsv($output, [
                self::escape_csv_cell((string) $row['id']),
                self::escape_csv_cell((string) $row['title']),
                !empty($row['used']) ? 'Found' : 'Likely unused',
                self::escape_csv_cell((string) $row['file']),
                self::escape_csv_cell((string) $row['type']),
                self::escape_csv_cell(implode(' | ', (array) $row['locations'])),
            ]);
        }

        fclose($output);
        exit;
    }

    public static function handle_delete_unused(): void {
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to delete media.', 'octotidy'));
        }

        check_admin_referer(self::NONCE_DELETE_ACTION);

        $reviewed = isset($_POST['octotidy_confirm_reviewed']) && '1' === sanitize_text_field(wp_unslash($_POST['octotidy_confirm_reviewed']));
        $confirm_text = isset($_POST['octotidy_confirm_text']) ? sanitize_text_field(wp_unslash($_POST['octotidy_confirm_text'])) : '';

        if (!$reviewed || $confirm_text !== 'DELETE') {
            wp_die(esc_html__('Bulk cleanup was not confirmed.', 'octotidy'));
        }

        $results = self::get_scan_results();
        $kept_results = [];
        $deleted = 0;
        $failed = 0;

        foreach ($results as $row) {
            $attachment_id = isset($row['id']) ? (int) $row['id'] : 0;

            if ($attachment_id < 1 || !empty($row['used'])) {
                $kept_results[] = $row;
                continue;
            }

            if (!current_user_can('delete_post', $attachment_id) || get_post_type($attachment_id) !== 'attachment') {
                $failed++;
                $kept_results[] = $row;
                continue;
            }

            $trashed = wp_trash_post($attachment_id);

            if ($trashed) {
                $deleted++;
            } else {
                $failed++;
                $kept_results[] = $row;
            }
        }

        update_option(self::OPTION_RESULTS, $kept_results, false);

        wp_safe_redirect(add_query_arg([
            'octotidy_deleted' => $deleted,
            'octotidy_failed' => $failed,
        ], admin_url('upload.php?page=octotidy')));
        exit;
    }

    private static function store_scan(): array {
        $results = self::run_scan();
        update_option(self::OPTION_RESULTS, $results, false);
        update_option(self::OPTION_SCANNED_AT, time(), false);

        return $results;
    }

    /**
     * Single pass: index attachment files by basename, then stream every haystack once and
     * look up what it references. Memory stays at one batch of rows plus the attachment index.
     */
    private static function run_scan(): array {
        [$attachments, $files, $file_pattern] = self::index_attachments();
        $locations = [];

        foreach (self::haystacks() as [$label, $value]) {
            if ($value === '') {
                continue;
            }

            foreach (self::referenced_ids(wp_unslash($value), $label, $files, $file_pattern) as $id) {
                // ponytail: keeps the first MAX_LOCATIONS per attachment so the results option stays small.
                if (isset($attachments[$id]) && count($locations[$id] ?? []) < self::MAX_LOCATIONS) {
                    $locations[$id][$label] = true;
                }
            }
        }

        $results = [];

        foreach ($attachments as $id => $attachment) {
            $found = array_keys($locations[$id] ?? []);
            $results[] = $attachment + [
                'used' => !empty($found),
                'locations' => $found,
            ];
        }

        usort($results, static function (array $a, array $b): int {
            if ($a['used'] === $b['used']) {
                return $b['id'] <=> $a['id'];
            }

            return $a['used'] <=> $b['used'];
        });

        return $results;
    }

    private static function get_scan_results(): array {
        $results = get_option(self::OPTION_RESULTS, []);

        if (!is_array($results)) {
            return [];
        }

        $normalized = [];

        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $attachment_id = isset($row['id']) ? absint($row['id']) : 0;
            if ($attachment_id < 1) {
                continue;
            }

            $normalized[] = [
                'id' => $attachment_id,
                'title' => isset($row['title']) ? (string) $row['title'] : sprintf(__('Attachment #%d', 'octotidy'), $attachment_id),
                'file' => isset($row['file']) ? (string) $row['file'] : '',
                'type' => isset($row['type']) ? (string) $row['type'] : '',
                'used' => !empty($row['used']),
                'locations' => isset($row['locations']) && is_array($row['locations']) ? array_values(array_map('strval', $row['locations'])) : [],
            ];
        }

        return $normalized;
    }

    private static function index_attachments(): array {
        global $wpdb;

        $attachments = [];
        $files = [];
        $extensions = [];
        $rows = self::paged_rows(
            "SELECT p.ID AS row_id, p.post_title, p.post_mime_type, f.meta_value AS file, m.meta_value AS metadata
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
            WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'",
            'p.ID'
        );

        foreach ($rows as $row) {
            $id = (int) $row['row_id'];
            $file = (string) $row['file'];
            $attachments[$id] = [
                'id' => $id,
                'title' => (string) $row['post_title'] !== '' ? (string) $row['post_title'] : sprintf(__('Attachment #%d', 'octotidy'), $id),
                'file' => $file,
                'type' => (string) $row['post_mime_type'],
            ];

            if ($file === '') {
                continue;
            }

            $paths = [$file];
            $metadata = maybe_unserialize((string) $row['metadata']);

            if (is_array($metadata) && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                $dirname = dirname($file) === '.' ? '' : trailingslashit(dirname($file));

                foreach ($metadata['sizes'] as $size) {
                    if (!empty($size['file'])) {
                        $paths[] = $dirname . $size['file'];
                    }
                }
            }

            foreach (array_unique($paths) as $path) {
                $files[wp_basename($path)][] = [$path, $id];
                $extensions[strtolower(pathinfo($path, PATHINFO_EXTENSION))] = true;
            }
        }

        unset($extensions['']);

        return [$attachments, $files, self::file_pattern(array_keys($extensions))];
    }

    /**
     * Matches file basenames ending in one of the given extensions. The lookbehind only lets a
     * match start at a token boundary, which keeps long blobs (base64, minified JSON) linear.
     */
    private static function file_pattern(array $extensions): string {
        if (empty($extensions)) {
            return '';
        }

        $char = '[^\/\\\\\s"\'<>()=,;?#&|]';
        $extensions = array_map(static function (string $extension): string {
            return preg_quote($extension, '/');
        }, $extensions);

        return '/(?<!' . $char . ')' . $char . '+?\.(?:' . implode('|', $extensions) . ')(?![a-z0-9])/i';
    }

    private static function haystacks(): Generator {
        global $wpdb;

        $posts = self::paged_rows(
            "SELECT ID AS row_id, post_title, post_type, post_content, post_excerpt FROM {$wpdb->posts}
            WHERE post_type <> 'attachment' AND post_status NOT IN ('trash', 'auto-draft')",
            'ID'
        );

        foreach ($posts as $row) {
            $label = sprintf('Post #%d (%s): %s', (int) $row['row_id'], $row['post_type'], wp_strip_all_tags((string) $row['post_title']));
            yield [$label . ' content', (string) $row['post_content']];
            yield [$label . ' excerpt', (string) $row['post_excerpt']];
        }

        $post_meta = self::paged_rows(
            "SELECT pm.meta_id AS row_id, pm.post_id AS object_id, pm.meta_key, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type <> 'attachment'
            AND p.post_status NOT IN ('trash', 'auto-draft')
            AND pm.meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt')
            AND pm.meta_key NOT LIKE '\_wp\_attachment\_%'",
            'pm.meta_id'
        );

        foreach ($post_meta as $row) {
            yield [sprintf('Post meta #%d: %s', (int) $row['object_id'], (string) $row['meta_key']), (string) $row['meta_value']];
        }

        $meta_tables = [
            $wpdb->termmeta => ['term_id', 'Term meta'],
            $wpdb->commentmeta => ['comment_id', 'Comment meta'],
        ];

        foreach ($meta_tables as $table => [$id_column, $table_label]) {
            $rows = self::paged_rows(
                "SELECT meta_id AS row_id, {$id_column} AS object_id, meta_key, meta_value FROM {$table} WHERE 1 = 1",
                'meta_id'
            );

            foreach ($rows as $row) {
                yield [sprintf('%s #%d: %s', $table_label, (int) $row['object_id'], (string) $row['meta_key']), (string) $row['meta_value']];
            }
        }

        $options = self::paged_rows(
            "SELECT option_id AS row_id, option_name, option_value FROM {$wpdb->options}
            WHERE option_name NOT LIKE '\_transient\_%'
            AND option_name NOT LIKE '\_site\_transient\_%'
            AND option_name NOT LIKE 'octotidy\_%'",
            'option_id'
        );

        foreach ($options as $row) {
            yield [sprintf('Option: %s', (string) $row['option_name']), (string) $row['option_value']];
        }
    }

    /**
     * Keyset-paged rows, so only one batch is ever in memory. $sql must select the id column
     * as row_id and end in a WHERE clause.
     */
    private static function paged_rows(string $sql, string $id_column): Generator {
        global $wpdb;

        $last_id = 0;

        do {
            // ponytail: fixed row count, not bytes; lower BATCH_SIZE if huge builder rows still spike memory.
            $rows = $wpdb->get_results(
                $sql . ' AND ' . $id_column . ' > ' . $last_id . ' ORDER BY ' . $id_column . ' LIMIT ' . self::BATCH_SIZE,
                ARRAY_A
            );

            // A failed query must not look like "no references", or everything reads as unused.
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('OctoTidy scan query failed: ' . $wpdb->last_error);
            }

            $rows = $rows ?: [];

            foreach ($rows as $row) {
                yield $row;
            }

            if ($rows) {
                $last_id = (int) end($rows)['row_id'];
            }
        } while (count($rows) === self::BATCH_SIZE);
    }

    private static function referenced_ids(string $value, string $label, array $files, string $file_pattern): array {
        $ids = [];

        if ($file_pattern !== '' && preg_match_all($file_pattern, $value, $matches)) {
            foreach (array_unique($matches[0]) as $basename) {
                foreach ($files[$basename] ?? [] as [$path, $id]) {
                    if (strpos($value, $path) !== false) {
                        $ids[] = $id;
                    }
                }
            }
        }

        $keys = 'id|image|image_id|attachment_id|media|media_id|thumbnail_id|background_image';
        $patterns = [
            '/wp-image-(\d+)\b/',
            '/["\'](?:' . $keys . ')["\']\s*[:=]\s*["\']?(\d+)\b/',
            '/s:\d+:"(?:' . $keys . ')";i:(\d+);/',
            '/s:\d+:"(?:' . $keys . ')";s:\d+:"(\d+)";/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $value, $matches)) {
                $ids = array_merge($ids, $matches[1]);
            }
        }

        if (preg_match_all('/\bids=(["\']?)([^"\']*)\1/', $value, $matches)) {
            foreach ($matches[2] as $list) {
                preg_match_all('/\d+/', $list, $digits);
                $ids = array_merge($ids, $digits[0]);
            }
        }

        // Any standalone number counts when the location's name looks media related.
        if (preg_match('/(?:image|media|attachment|thumbnail|logo|icon|gallery|background|avatar|file)/i', $label) && preg_match_all('/\d+/', $value, $matches)) {
            $ids = array_merge($ids, $matches[0]);
        }

        return array_unique(array_map('intval', $ids));
    }

    private static function escape_csv_cell(string $value): string {
        $trimmed = ltrim($value);

        if ($trimmed !== '' && preg_match('/^[=+\-@]/', $trimmed)) {
            return "\t" . $value;
        }

        return $value;
    }
}

OctoTidy_Media_Attachment_Verifier::init();

<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.34.0: theme files over the full-level Claude / ChatGPT connection (yakup 2026-11-02: "eklentinin kurulu
 * her web sitesinde tema düzenleme, silme, ekleme, her şeyi yapabil").
 *
 * list_themes / list_theme_files / read_theme_file read; write_theme_files writes, adds and deletes files of any
 * installed theme (a new folder with a style.css makes a new theme); switch_theme activates one; delete_theme removes
 * an inactive one. Every write first copies the old files into wp-content/moxdop-backups (not web-readable), so
 * undo_change puts them back; PHP files are syntax-checked before anything is written, and a write to the active
 * theme (or its parent) is followed by a site check that restores the old files when the site broke.
 */
trait MoxDOP_Connector_AI_Themes
{
    /** @return array<string, array<string, mixed>> */
    public static function theme_definitions()
    {
        $theme = ['type' => 'string', 'description' => 'Theme folder (stylesheet) from list_themes; default the active theme.'];

        return [
            'list_themes' => ['level' => 'full', 'read' => true, 'description' => 'Installed themes: folder (stylesheet), name, version, parent, active, writable.', 'props' => []],
            'list_theme_files' => ['level' => 'full', 'read' => true, 'description' => 'Files of one theme with path, size, modified time and sha1 (max 2000).', 'props' => ['theme' => $theme]],
            'read_theme_file' => ['level' => 'full', 'read' => true, 'description' => 'One theme file. Text comes back as content; other files as content_base64 (max 2 MB).', 'props' => [
                'theme' => $theme, 'path' => ['type' => 'string', 'description' => 'Path inside the theme, e.g. functions.php or assets/site.css.'],
            ], 'required' => ['path']],
            'write_theme_files' => ['level' => 'full', 'read' => false, 'description' => 'Writes, adds or deletes up to 100 files of one theme in one change: files [{path, content | content_base64 | delete:true}]. A folder that does not exist yet becomes a new theme (send its style.css). PHP files are syntax-checked first; nothing is written when one fails. The old files are kept, so undo_change restores them. A write to the active theme or its parent is followed by a site check (home + paths) and rolled back when the site broke.', 'props' => [
                'theme' => $theme,
                'files' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'content_base64' => ['type' => 'string'], 'delete' => ['type' => 'boolean'],
                ], 'required' => ['path']]],
                'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Extra pages for the site check.'],
            ], 'required' => ['files']],
            'switch_theme' => ['level' => 'full', 'read' => false, 'description' => 'Activates an installed theme. A site check follows; a broken site gets the previous theme back. Undoable.', 'props' => [
                'theme' => ['type' => 'string'],
            ], 'required' => ['theme']],
            'delete_theme' => ['level' => 'full', 'read' => false, 'destructive' => true, 'description' => 'Removes an inactive theme (not the active one or its parent). Needs confirm="DELETE_THEME". The folder is moved to the backups first, so undo_change brings it back.', 'props' => [
                'theme' => ['type' => 'string'], 'confirm' => ['type' => 'string'],
            ], 'required' => ['theme', 'confirm']],
        ];
    }

    /* ---------------------------------------------------------------- helpers */

    private static function theme_slug($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return get_stylesheet();
        }

        return preg_match('/^[A-Za-z0-9._-]{1,100}$/', $slug) && strpos($slug, '..') === false ? $slug : '';
    }

    private static function theme_dir($slug)
    {
        return rtrim(get_theme_root($slug), '/\\').'/'.$slug;
    }

    /** @return string|WP_Error a normalised relative path inside a theme */
    private static function theme_path($path)
    {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || strpos($part, "\0") !== false) {
                return new WP_Error('invalid', 'Geçersiz dosya yolu: '.$path);
            }
            $parts[] = $part;
        }

        return $parts === [] ? new WP_Error('invalid', 'Dosya yolu boş.') : implode('/', $parts);
    }

    private static function backup_root()
    {
        $dir = WP_CONTENT_DIR.'/moxdop-backups';
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (! file_exists($dir.'/.htaccess')) {
            @file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (! file_exists($dir.'/index.php')) {
            @file_put_contents($dir.'/index.php', "<?php\n// Silence.\n");
        }

        return $dir;
    }

    /** @return true|string */
    private static function php_ok($code)
    {
        if (! class_exists('ParseError')) {
            return true;
        }
        try {
            token_get_all((string) $code, defined('TOKEN_PARSE') ? TOKEN_PARSE : 0);
        } catch (ParseError $error) {
            return $error->getMessage().' (satır '.$error->getLine().')';
        }

        return true;
    }

    private static function is_text($bytes)
    {
        return strpos((string) $bytes, "\0") === false && (function_exists('mb_check_encoding') ? mb_check_encoding((string) $bytes, 'UTF-8') : true);
    }

    private static function rmtree($dir)
    {
        if (! is_dir($dir)) {
            return @unlink($dir) || ! file_exists($dir);
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        return @rmdir($dir);
    }

    private static function theme_reset_caches()
    {
        wp_clean_themes_cache();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Puts the files of a backup back: [theme_files, slug, backup_file].
     *
     * @return true|string
     */
    private static function restore_theme_files($slug, $backup)
    {
        $data = is_readable($backup) ? @unserialize(gzuncompress((string) file_get_contents($backup)), ['allowed_classes' => false]) : null;
        if (! is_array($data)) {
            return 'yedek okunamadı: '.basename((string) $backup);
        }
        $dir = self::theme_dir($slug);
        foreach ((array) $data['files'] as $path => $old) {
            $target = $dir.'/'.$path;
            if ($old === null) {
                @unlink($target);
                continue;
            }
            wp_mkdir_p(dirname($target));
            if (@file_put_contents($target, $old) === false) {
                return 'yazılamadı: '.$path;
            }
        }
        if (! empty($data['new_theme']) && is_dir($dir)) {
            self::rmtree($dir);
        }
        self::theme_reset_caches();

        return true;
    }

    /* ------------------------------------------------------------------ tools */

    private function tool_list_themes()
    {
        $out = [];
        foreach (wp_get_themes(['errors' => null]) as $slug => $theme) {
            $out[] = ['theme' => $slug, 'name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'parent' => $theme->get_template() !== $slug ? $theme->get_template() : null,
                'active' => get_stylesheet() === $slug, 'parent_of_active' => get_template() === $slug && get_stylesheet() !== $slug, 'writable' => is_writable(self::theme_dir($slug)),
                'errors' => $theme->errors() ? $theme->errors()->get_error_message() : null];
        }

        return ['themes' => $out, 'theme_root' => get_theme_root()];
    }

    private function tool_list_theme_files(array $args)
    {
        $slug = self::theme_slug($args['theme'] ?? '');
        $dir = $slug === '' ? '' : self::theme_dir($slug);
        if ($dir === '' || ! is_dir($dir)) {
            return new WP_Error('not_found', 'Tema bulunamadı: '.($args['theme'] ?? ''));
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $files[] = ['path' => ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/'), 'size' => $file->getSize(),
                'modified' => gmdate('c', $file->getMTime()), 'sha1' => $file->getSize() <= 5 * 1048576 ? sha1_file($file->getPathname()) : null];
            if (count($files) >= 2000) {
                break;
            }
        }
        usort($files, static function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return ['theme' => $slug, 'active' => get_stylesheet() === $slug, 'files' => $files, 'truncated' => count($files) >= 2000];
    }

    private function tool_read_theme_file(array $args)
    {
        $slug = self::theme_slug($args['theme'] ?? '');
        $path = self::theme_path($args['path'] ?? '');
        if ($slug === '' || is_wp_error($path)) {
            return is_wp_error($path) ? $path : new WP_Error('invalid', 'Geçersiz tema.');
        }
        $file = self::theme_dir($slug).'/'.$path;
        if (! is_file($file)) {
            return new WP_Error('not_found', 'Dosya yok: '.$slug.'/'.$path);
        }
        if (filesize($file) > 2 * 1048576) {
            return new WP_Error('too_large', 'Dosya 2 MB\'tan büyük.');
        }
        $bytes = (string) file_get_contents($file);

        return ['theme' => $slug, 'path' => $path, 'size' => strlen($bytes), 'sha1' => sha1($bytes)]
            + (self::is_text($bytes) ? ['content' => $bytes] : ['content_base64' => base64_encode($bytes)]);
    }

    private function tool_write_theme_files(array $args, array $caller)
    {
        $slug = self::theme_slug($args['theme'] ?? '');
        if ($slug === '') {
            return new WP_Error('invalid', 'Geçersiz tema klasörü.');
        }
        $items = is_array($args['files'] ?? null) ? array_values($args['files']) : [];
        if ($items === [] || count($items) > 100) {
            return new WP_Error('invalid', 'files 1-100 dosya olmalı.');
        }
        $dir = self::theme_dir($slug);
        $new_theme = ! is_dir($dir);
        $plan = [];
        foreach ($items as $item) {
            $path = self::theme_path(is_array($item) ? ($item['path'] ?? '') : '');
            if (is_wp_error($path)) {
                return $path;
            }
            if (! empty($item['delete'])) {
                $plan[$path] = null;
                continue;
            }
            if (isset($item['content_base64'])) {
                $bytes = base64_decode((string) $item['content_base64'], true);
                if ($bytes === false) {
                    return new WP_Error('invalid', 'content_base64 çözülemedi: '.$path);
                }
            } elseif (isset($item['content'])) {
                $bytes = (string) $item['content'];
            } else {
                return new WP_Error('invalid', 'content, content_base64 ya da delete gerekli: '.$path);
            }
            if (preg_match('/\.(php|phtml|inc)$/i', $path) && ($bad = self::php_ok($bytes)) !== true) {
                return new WP_Error('php_syntax', 'PHP hatası, hiçbir dosya yazılmadı: '.$path.': '.$bad);
            }
            $plan[$path] = $bytes;
        }
        if ($new_theme && ! array_key_exists('style.css', $plan)) {
            return new WP_Error('invalid', 'Yeni tema için style.css gönderilmeli.');
        }

        // Back up what is there now (null = the file did not exist), outside the web root's reach.
        $old = [];
        foreach ($plan as $path => $bytes) {
            $target = $dir.'/'.$path;
            $old[$path] = is_file($target) ? (string) file_get_contents($target) : null;
        }
        $backup = self::backup_root().'/theme-'.$slug.'-'.gmdate('Ymd-His').'-'.wp_generate_password(8, false).'.bak';
        if (@file_put_contents($backup, gzcompress(serialize(['theme' => $slug, 'new_theme' => $new_theme, 'files' => $old]), 6)) === false) {
            return new WP_Error('backup', 'Yedek yazılamadı (wp-content/moxdop-backups); hiçbir dosya değişmedi.');
        }

        $write = static function () use ($plan, $dir) {
            foreach ($plan as $path => $bytes) {
                $target = $dir.'/'.$path;
                if ($bytes === null) {
                    if (is_file($target) && ! @unlink($target)) {
                        return new WP_Error('write', 'Silinemedi: '.$path);
                    }
                    continue;
                }
                if (! wp_mkdir_p(dirname($target)) || @file_put_contents($target, $bytes) === false) {
                    return new WP_Error('write', 'Yazılamadı: '.$path.' (klasör izni?)');
                }
            }
            self::theme_reset_caches();

            return true;
        };
        $restore = static function () use ($slug, $backup) {
            self::restore_theme_files($slug, $backup);
        };

        $live = in_array($slug, [get_stylesheet(), get_template()], true);
        $check = null;
        $guarded = [];
        if ($live) {
            $guarded = $this->guarded($write, $restore, is_array($args['paths'] ?? null) ? $args['paths'] : []);
            if (is_wp_error($guarded)) {
                if ($guarded->get_error_code() !== 'broke') {
                    $restore();
                }

                return $guarded;
            }
            $check = $guarded['site_check'];
        } else {
            $done = $write();
            if (is_wp_error($done)) {
                $restore();

                return $done;
            }
        }

        $change = self::remember([['theme_files', $slug, $backup]]);
        $written = count(array_filter($plan, static function ($b) {
            return $b !== null;
        }));
        $deleted = count($plan) - $written;
        MoxDOP_Connector_AI::record($caller, 'write_theme_files', 0, 'Tema '.$slug.': '.$written.' dosya yazıldı, '.$deleted.' silindi', $change, 'ai');

        return array_filter(['theme' => $slug, 'new_theme' => $new_theme, 'written' => $written, 'deleted' => $deleted, 'active' => $live,
            'change_id' => $change, 'site_check' => $check, 'note' => $guarded['note'] ?? null], static function ($v) {
                return $v !== null;
            });
    }

    private function tool_switch_theme(array $args, array $caller)
    {
        $slug = self::theme_slug($args['theme'] ?? '');
        $theme = $slug === '' ? null : wp_get_theme($slug);
        if (! $theme || ! $theme->exists() || $theme->errors()) {
            return new WP_Error('not_found', 'Tema yok ya da bozuk: '.($args['theme'] ?? ''));
        }
        $previous = get_stylesheet();
        if ($previous === $slug) {
            return ['theme' => $slug, 'active' => true, 'note' => 'Zaten etkin.'];
        }
        $guarded = $this->guarded(static function () use ($slug) {
            switch_theme($slug);

            return true;
        }, static function () use ($previous) {
            switch_theme($previous);
        });
        if (is_wp_error($guarded)) {
            return $guarded;
        }
        $change = self::remember([['theme_switch', $previous]]);
        MoxDOP_Connector_AI::record($caller, 'switch_theme', 0, 'Tema etkin: '.$slug.' (önceki '.$previous.')', $change, 'ai');

        return ['theme' => $slug, 'previous' => $previous, 'change_id' => $change, 'site_check' => $guarded['site_check']];
    }

    private function tool_delete_theme(array $args, array $caller)
    {
        if (($args['confirm'] ?? '') !== 'DELETE_THEME') {
            return new WP_Error('confirm', 'confirm="DELETE_THEME" gerekli.');
        }
        $slug = self::theme_slug($args['theme'] ?? '');
        $dir = $slug === '' ? '' : self::theme_dir($slug);
        if ($dir === '' || ! is_dir($dir)) {
            return new WP_Error('not_found', 'Tema bulunamadı.');
        }
        if (in_array($slug, [get_stylesheet(), get_template()], true)) {
            return new WP_Error('active', 'Etkin tema ya da onun ana teması silinemez; önce switch_theme.');
        }
        $target = self::backup_root().'/theme-dir-'.$slug.'-'.gmdate('Ymd-His').'-'.wp_generate_password(8, false);
        if (! @rename($dir, $target)) {
            return new WP_Error('write', 'Tema klasörü taşınamadı.');
        }
        self::theme_reset_caches();
        $change = self::remember([['theme_dir', $slug, $target]]);
        MoxDOP_Connector_AI::record($caller, 'delete_theme', 0, 'Tema kaldırıldı: '.$slug, $change, 'ai');

        return ['theme' => $slug, 'deleted' => true, 'change_id' => $change];
    }

    /** @return true|string|null null = not a theme step */
    private static function undo_theme_step(array $step)
    {
        switch ($step[0] ?? '') {
            case 'theme_files':
                return self::restore_theme_files((string) $step[1], (string) $step[2]);
            case 'theme_switch':
                $theme = wp_get_theme((string) $step[1]);
                if (! $theme->exists()) {
                    return 'önceki tema yok: '.$step[1];
                }
                switch_theme((string) $step[1]);

                return true;
            case 'theme_dir':
                $dir = self::theme_dir((string) $step[1]);
                if (is_dir($dir)) {
                    return 'aynı adla tema var: '.$step[1];
                }
                if (! @rename((string) $step[2], $dir)) {
                    return 'tema geri taşınamadı: '.$step[1];
                }
                self::theme_reset_caches();

                return true;
        }

        return null;
    }
}

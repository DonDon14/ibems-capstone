<?php

namespace App\Libraries;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ConventionScanner
{
    private const CONTROLLER_WARNING_LINES = 800;
    private const SCRIPT_WARNING_LINES = 700;
    private const VIEW_WARNING_LINES = 350;

    /** @var list<string> */
    private const AUTHENTICATED_VIEW_DIRECTORIES = [
        'app/Views/admin',
        'app/Views/accounting',
        'app/Views/store',
        'app/Views/store-admin',
        'app/Views/user',
    ];

    /** @var list<string> */
    private const INLINE_SCRIPT_ALLOWLIST = [
        'app/Views/store/receipt.php',
        'app/Views/user/receipt.php',
    ];

    /** @var list<string> */
    private const INLINE_STYLE_ALLOWLIST_PREFIXES = [
        'app/Views/errors/',
    ];

    /** @var list<string> */
    private const GLOBAL_CSS_OWNERS = [
        'public/assets/css/app.css',
        'public/assets/css/modern-ui.css',
        'public/assets/css/tailwind.css',
    ];

    /** @var list<string> */
    private const CANONICAL_CSS_CLASSES = [
        'primary-btn',
        'secondary-btn',
        'danger-btn',
        'btn-primary',
        'btn-secondary',
        'table-standard',
        'status-pill',
        'admin-modal',
        'acct-modal',
        'inv-modal',
        'receipt-modal',
        'app-modal',
    ];

    /** @var list<string> */
    private const LEGACY_ACTION_BUTTON_CLASSES = [
        'history-action',
        'scan-btn',
        'mini-btn',
        'admin-action-btn',
        'card-action-btn',
        'inventory-manage-btn',
    ];

    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @return list<array{severity:string,rule:string,path:string,line:int,message:string}>
     */
    public function scan(): array
    {
        $findings = [];
        $this->scanAuthenticatedViews($findings);
        $this->scanBrowserButtonMarkup($findings);
        $this->scanPageStylesheets($findings);
        $this->scanFileSizes($findings);

        usort($findings, static function (array $left, array $right): int {
            return [$left['path'], $left['line'], $left['rule']]
                <=> [$right['path'], $right['line'], $right['rule']];
        });

        return $findings;
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function scanAuthenticatedViews(array &$findings): void
    {
        foreach (self::AUTHENTICATED_VIEW_DIRECTORIES as $directory) {
            foreach ($this->files($directory, 'php') as $path) {
                $source = $this->read($path);
                if ($source === null) {
                    continue;
                }

                if (!preg_match("/extend\s*\(\s*['\"]layouts\//", $source)) {
                    $this->add($findings, 'warning', 'view.role_layout', $path, 1, 'Authenticated view does not extend a role layout.');
                }

                if (preg_match('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $source, $match, PREG_OFFSET_CAPTURE)
                    && !in_array($path, self::INLINE_SCRIPT_ALLOWLIST, true)) {
                    $this->add($findings, 'warning', 'view.inline_script', $path, $this->lineAt($source, $match[0][1]), 'Inline script is not allowlisted.');
                }

                if (preg_match('/<style\b|\sstyle\s*=/i', $source, $match, PREG_OFFSET_CAPTURE)
                    && !$this->hasAllowedPrefix($path, self::INLINE_STYLE_ALLOWLIST_PREFIXES)) {
                    $this->add($findings, 'info', 'view.inline_style', $path, $this->lineAt($source, $match[0][1]), 'Inline style requires a documented exception or extraction.');
                }

                $this->scanModals($findings, $path, $source);
                $this->scanCloseButtons($findings, $path, $source);
                $this->scanButtonVariants($findings, $path, $source);
                $this->scanControlLabels($findings, $path, $source);
            }
        }
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function scanBrowserButtonMarkup(array &$findings): void
    {
        foreach ($this->files('public/assets/js', 'js') as $path) {
            $source = $this->read($path);
            if ($source !== null) {
                $this->scanButtonVariants($findings, $path, $source);
            }
        }
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function scanButtonVariants(array &$findings, string $path, string $source): void
    {
        if (!preg_match_all('/<button\b[^>]*\bclass\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches as $match) {
            $classList = preg_split('/\s+/', trim($match[2][0])) ?: [];
            $legacy = array_values(array_intersect(self::LEGACY_ACTION_BUTTON_CLASSES, $classList));
            if ($legacy === []) {
                continue;
            }

            $this->add(
                $findings,
                'warning',
                'button.legacy_action_variant',
                $path,
                $this->lineAt($source, $match[0][1]),
                'Ordinary action button uses legacy class .' . implode(', .', $legacy) . '; use a shared semantic button variant.'
            );
        }
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function scanModals(array &$findings, string $path, string $source): void
    {
        $modalClasses = ['admin-modal', 'acct-modal', 'inv-modal', 'receipt-modal', 'app-modal'];
        if (!preg_match_all('/<div\b[^>]*\bclass\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches as $match) {
            $tag = $match[0][0];
            $offset = $match[0][1];
            $classList = preg_split('/\s+/', trim($match[2][0])) ?: [];
            if (array_intersect($modalClasses, $classList) === []) {
                continue;
            }

            $missing = [];
            if (!preg_match('/\brole\s*=\s*(["\'])dialog\1/i', $tag)) {
                $missing[] = 'role="dialog"';
            }
            if (!preg_match('/\baria-modal\s*=\s*(["\'])true\1/i', $tag)) {
                $missing[] = 'aria-modal="true"';
            }
            if (!preg_match('/\baria-labelledby\s*=|\baria-label\s*=/i', $tag)) {
                $missing[] = 'an accessible title';
            }
            if ($missing !== []) {
                $this->add($findings, 'warning', 'modal.source_semantics', $path, $this->lineAt($source, $offset), 'Modal source is missing ' . implode(', ', $missing) . '.');
            }
        }
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function scanCloseButtons(array &$findings, string $path, string $source): void
    {
        if (!preg_match_all('/<button\b[^>]*\bclass\s*=\s*(["\'])[^"\']*(?:modal-close|receipt-close)[^"\']*\1[^>]*>/i', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as [$tag, $offset]) {
            if (!preg_match('/\baria-label\s*=|\btitle\s*=/i', $tag)) {
                $this->add($findings, 'info', 'button.close_name', $path, $this->lineAt($source, $offset), 'Close button relies on runtime repair for its accessible name.');
            }
        }
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function scanControlLabels(array &$findings, string $path, string $source): void
    {
        $pattern = '/<(select|input)\b[^>]*\bid\s*=\s*(["\'])([^"\']+)\2[^>]*>/i';
        if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches as $match) {
            $tag = $match[0][0];
            $offset = $match[0][1];
            $kind = strtolower($match[1][0]);
            $id = $match[3][0];

            if ($kind === 'input' && !preg_match('/\btype\s*=\s*(["\'])(?:date|search|text|email|password|number|file)\1/i', $tag)) {
                continue;
            }

            $quotedId = preg_quote($id, '/');
            $hasVisibleLabel = preg_match('/<label\b[^>]*\bfor\s*=\s*(["\'])' . $quotedId . '\1/i', $source) === 1
                || $this->insideLabel($source, $offset);

            if (!$hasVisibleLabel) {
                $this->add($findings, 'info', 'control.visible_label', $path, $this->lineAt($source, $offset), "Control #{$id} has no associated visible label.");
            }
        }
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function scanPageStylesheets(array &$findings): void
    {
        $classes = implode('|', array_map(static fn (string $class): string => preg_quote($class, '/'), self::CANONICAL_CSS_CLASSES));
        $pattern = '/^\s*\.(' . $classes . ')(?=[\s:{.,#>])/m';

        foreach ($this->files('public/assets/css', 'css') as $path) {
            if (in_array($path, self::GLOBAL_CSS_OWNERS, true)) {
                continue;
            }

            $source = $this->read($path);
            if ($source === null || !preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches as $match) {
                $this->add($findings, 'warning', 'css.global_selector_owner', $path, $this->lineAt($source, $match[0][1]), "Page stylesheet defines canonical .{$match[1][0]} behavior.");
            }
        }
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function scanFileSizes(array &$findings): void
    {
        $groups = [
            ['app/Controllers', 'php', self::CONTROLLER_WARNING_LINES, 'size.controller'],
            ['public/assets/js', 'js', self::SCRIPT_WARNING_LINES, 'size.browser_script'],
        ];

        foreach (self::AUTHENTICATED_VIEW_DIRECTORIES as $directory) {
            $groups[] = [$directory, 'php', self::VIEW_WARNING_LINES, 'size.view'];
        }

        foreach ($groups as [$directory, $extension, $threshold, $rule]) {
            foreach ($this->files($directory, $extension) as $path) {
                $source = $this->read($path);
                if ($source === null) {
                    continue;
                }
                $lines = substr_count($source, "\n") + 1;
                if ($lines > $threshold) {
                    $this->add($findings, 'info', $rule, $path, 1, "File has {$lines} lines; warning threshold is {$threshold}.");
                }
            }
        }
    }

    /** @return list<string> */
    private function files(string $relativeDirectory, string $extension): array
    {
        $absoluteDirectory = $this->absolute($relativeDirectory);
        if (!is_dir($absoluteDirectory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluteDirectory));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== $extension) {
                continue;
            }
            $files[] = $this->relative($file->getPathname());
        }
        sort($files);

        return $files;
    }

    private function read(string $relativePath): ?string
    {
        $contents = @file_get_contents($this->absolute($relativePath));
        return $contents === false ? null : $contents;
    }

    private function absolute(string $relativePath): string
    {
        return rtrim($this->rootPath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function relative(string $absolutePath): string
    {
        $root = str_replace('\\', '/', rtrim($this->rootPath, "\\/"));
        $path = str_replace('\\', '/', $absolutePath);
        return ltrim(substr($path, strlen($root)), '/');
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, max(0, $offset)), "\n") + 1;
    }

    private function insideLabel(string $source, int $offset): bool
    {
        $before = substr($source, 0, $offset);
        return strrpos($before, '<label') > strrpos($before, '</label>');
    }

    /** @param list<string> $prefixes */
    private function hasAllowedPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{severity:string,rule:string,path:string,line:int,message:string}> $findings
     */
    private function add(array &$findings, string $severity, string $rule, string $path, int $line, string $message): void
    {
        $findings[] = compact('severity', 'rule', 'path', 'line', 'message');
    }
}

<?php

declare(strict_types=1);

namespace App\Recipes\Files;

use RuntimeException;

/**
 * Phase 11C — the sole entry point for writing recipe markdown files
 * to disk. Atomic, validated, contained.
 *
 * Atomicity: write to a temp file in the SAME directory as the target
 * (so the rename is atomic on the same filesystem), then rename.
 * If anything fails mid-process, the temp file is unlinked and the
 * original target is untouched. This protects against half-written
 * files if the process gets killed or the disk fills up.
 *
 * Validation: slug and category go through strict regex checks before
 * any I/O. The slug uniqueness check additionally scans recipes/*<slug>.md
 * so we don't silently shadow an existing recipe in another category.
 *
 * Containment: every resolved write target is required to be a
 * descendant of the configured recipes root. Belt-and-suspenders for
 * the regex validation — the writer should never touch anything
 * outside recipes/, full stop.
 *
 * This service does NOT call RecipeReindexer. The editor controller
 * composes the two — file-write is one operation; cache-update is
 * another. Keeping them decoupled means a backup tool can safely
 * write files without triggering indexer side effects, and the
 * indexer can run without writing files.
 */
final class RecipeFileWriter
{
    /** Slug: lowercase, digits, hyphens. No leading/trailing hyphen. 1-100 chars. */
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/';

    /** Category: lowercase letter first, then lowercase letters/digits/hyphens. */
    private const CATEGORY_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public function __construct(
        private readonly string $root,
    ) {
        // Resolve the root once, so realpath comparisons later don't suffer
        // from a non-canonical configured path.
        $resolved = realpath($root);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("Recipe root not found: {$root}");
        }
    }

    public function exists(string $slug): bool
    {
        $this->assertValidSlug($slug);
        return $this->locateExistingFile($slug) !== null;
    }

    /**
     * Atomically write a recipe markdown file at recipes/<category>/<slug>.md.
     *
     * Throws RuntimeException on validation failure or write failure.
     * The caller is responsible for catching and translating into HTTP
     * responses / artisan command output.
     */
    public function write(string $slug, string $category, string $markdown): WriteResult
    {
        $this->assertValidSlug($slug);
        $this->assertValidCategory($category);

        // Category directory must already exist — creating one is a
        // deliberate maintainer action, not a side effect of saving.
        $categoryDir = $this->resolvedRoot().'/'.$category;
        if (! is_dir($categoryDir)) {
            throw new RuntimeException(
                "Category '{$category}' has no directory at recipes/{$category}/. Create the directory first.",
            );
        }

        // Reject any attempt to write where the same slug lives in a
        // DIFFERENT category — we don't silently shadow.
        $existingPath = $this->locateExistingFile($slug);
        $existingCategory = $existingPath !== null ? basename(dirname($existingPath)) : null;
        if ($existingPath !== null && $existingCategory !== $category) {
            $relative = 'recipes/'.$existingCategory.'/'.$slug.'.md';
            throw new RuntimeException(
                "Slug '{$slug}' already exists at {$relative}. Delete that file or choose a different slug.",
            );
        }

        $targetPath = $categoryDir.'/'.$slug.'.md';
        $this->assertInsideRoot($targetPath);

        $status = file_exists($targetPath) ? 'updated' : 'created';

        $this->atomicWrite($targetPath, $markdown);

        return new WriteResult(status: $status, path: $targetPath);
    }

    public function delete(string $slug): DeleteResult
    {
        $this->assertValidSlug($slug);

        $existingPath = $this->locateExistingFile($slug);
        if ($existingPath === null) {
            return new DeleteResult(status: 'not_found', path: null);
        }
        $this->assertInsideRoot($existingPath);

        if (! @unlink($existingPath)) {
            throw new RuntimeException("Failed to delete {$existingPath} — check filesystem permissions.");
        }

        return new DeleteResult(status: 'deleted', path: $existingPath);
    }

    /**
     * Resolve the absolute path a slug+category would write to without
     * actually performing any I/O. Useful for the editor to display the
     * target path to the user before they hit save.
     */
    public function resolvePath(string $slug, ?string $category = null): ?string
    {
        $this->assertValidSlug($slug);
        if ($category !== null) {
            $this->assertValidCategory($category);
            return $this->resolvedRoot().'/'.$category.'/'.$slug.'.md';
        }
        return $this->locateExistingFile($slug);
    }

    // ---- internals ----

    private function resolvedRoot(): string
    {
        return realpath($this->root);
    }

    private function assertValidSlug(string $slug): void
    {
        if ($slug === '') {
            throw new RuntimeException('Slug is empty.');
        }
        if (strlen($slug) > 100) {
            throw new RuntimeException(
                "Slug too long ({$slug}). Must be 100 characters or fewer.",
            );
        }
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            throw new RuntimeException(
                "Invalid slug '{$slug}'. Must be lowercase letters, digits, and hyphens only; no leading or trailing hyphen.",
            );
        }
    }

    private function assertValidCategory(string $category): void
    {
        if ($category === '') {
            throw new RuntimeException('Category is empty.');
        }
        if (! preg_match(self::CATEGORY_PATTERN, $category)) {
            throw new RuntimeException(
                "Invalid category '{$category}'. Must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.",
            );
        }
    }

    /**
     * Walk recipes/*<slug>.md to find an existing file regardless of
     * category. Returns the absolute path or null.
     */
    private function locateExistingFile(string $slug): ?string
    {
        $entries = @scandir($this->resolvedRoot());
        if ($entries === false) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidateDir = $this->resolvedRoot().'/'.$entry;
            if (! is_dir($candidateDir)) {
                continue;
            }
            $candidateFile = $candidateDir.'/'.$slug.'.md';
            if (is_file($candidateFile)) {
                return $candidateFile;
            }
        }
        return null;
    }

    /**
     * Belt-and-suspenders containment check. The slug and category
     * validators above should already prevent traversal, but realpath
     * the resolved target and confirm it sits inside the configured
     * recipes root before any I/O happens.
     *
     * Note: the target file itself may not exist yet (write path),
     * so we realpath its parent directory and assert the result is
     * inside root.
     */
    private function assertInsideRoot(string $path): void
    {
        $parent = dirname($path);
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false) {
            throw new RuntimeException("Parent directory does not exist: {$parent}");
        }
        $root = $this->resolvedRoot();
        if ($resolvedParent !== $root && ! str_starts_with($resolvedParent.'/', $root.'/')) {
            throw new RuntimeException(
                "Resolved path {$path} is outside the recipes root ({$root}). Aborting.",
            );
        }
    }

    /**
     * Atomic write: write to a temp file in the same directory, then
     * rename onto the target. POSIX rename() is atomic on the same
     * filesystem. If any step fails, the temp file is unlinked.
     *
     * We use LOCK_EX on the temp write so concurrent writes (two
     * editor saves arriving simultaneously) don't interleave bytes
     * in the temp file. The rename itself is single-threaded by the
     * kernel.
     *
     * fsync: not explicitly invoked — file_put_contents closes the
     * handle, which queues the kernel write-back. For a single-user
     * LAN tool this is sufficient. If durability ever matters (we'd
     * lose the last save if power dies before write-back), upgrade to
     * fopen/fwrite/fflush/fsync.
     */
    private function atomicWrite(string $targetPath, string $markdown): void
    {
        $dir = dirname($targetPath);
        // Leading dot keeps the temp file hidden from default `ls`.
        $tempPath = $dir.'/.'.basename($targetPath).'.tmp.'.bin2hex(random_bytes(6));

        $bytesWritten = @file_put_contents($tempPath, $markdown, LOCK_EX);
        if ($bytesWritten === false || $bytesWritten !== strlen($markdown)) {
            @unlink($tempPath);
            throw new RuntimeException(
                "Failed to write temp file at {$tempPath} (wrote ".($bytesWritten === false ? 'false' : (string) $bytesWritten)." of ".strlen($markdown)." bytes). Check filesystem permissions and free space.",
            );
        }

        if (! @rename($tempPath, $targetPath)) {
            @unlink($tempPath);
            throw new RuntimeException(
                "Failed to rename {$tempPath} to {$targetPath}. The original file (if any) is untouched.",
            );
        }
    }
}

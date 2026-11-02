<?php

namespace App\Support\Install;

use InvalidArgumentException;
use RuntimeException;

/**
 * The `.env` file, read and written a line at a time so that everything else in it (comments, order, the other settings) stays as it is.
 */
class EnvironmentFile
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function createFrom(string $example): void
    {
        if (! is_file($example)) {
            throw new RuntimeException("There is no {$example} to make the environment file from.");
        }

        if (! @copy($example, $this->path)) {
            throw new RuntimeException("Could not write {$this->path}. Is the folder writable?");
        }
    }

    /**
     * The value of a setting without its quotes, or null when the file has no such line.
     */
    public function get(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $match) === 1) {
                return $this->unquote(trim($match[1]));
            }
        }

        return null;
    }

    /**
     * Replace the setting's line, or add one at the end. Only that line changes. A setting written twice is changed in both places,
     * because the dotenv loader lets the later line win and the file should not say two things.
     */
    public function set(string $key, string $value): void
    {
        $content = $this->read();
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $line = $key.'='.$this->quote($value);
        $pattern = '/^[ \t]*'.preg_quote($key, '/').'[ \t]*=[^\r\n]*/m';

        if (preg_match($pattern, $content) === 1) {
            // A callback, so that a `$` or a `\` in the value is not read as a reference.
            $content = (string) preg_replace_callback($pattern, fn () => $line, $content);
        } else {
            $content = $content === '' || str_ends_with($content, "\n") ? $content : $content.$eol;
            $content .= $line.$eol;
        }

        if (file_put_contents($this->path, $content, LOCK_EX) === false) {
            throw new RuntimeException("Could not write {$this->path}.");
        }
    }

    /**
     * How a value is written: as it is when it is made of plain characters, else in the quotes that keep it as it is.
     */
    private function quote(string $value): string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException('A value in the environment file cannot have a line break.');
        }

        if ($value === '' || preg_match('/^[A-Za-z0-9_\-.:\/@+,=]+$/', $value) === 1) {
            return $value;
        }

        if (! preg_match('/["\\\\$]/', $value)) {
            return '"'.$value.'"';
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        throw new InvalidArgumentException('That value has quotes and a "$" or a backslash in it, which the environment file cannot hold.');
    }

    private function unquote(string $value): string
    {
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $end = strpos($value, $value[0], 1);

            return $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
        }

        // An unquoted value ends where a comment starts.
        return rtrim((string) preg_replace('/\s+#.*$/', '', $value));
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        return preg_split('/\r\n|\r|\n/', $this->read()) ?: [];
    }

    private function read(): string
    {
        if (! is_file($this->path)) {
            return '';
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("Could not read {$this->path}.");
        }

        return $content;
    }
}

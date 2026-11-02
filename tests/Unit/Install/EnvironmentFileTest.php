<?php

namespace Tests\Unit\Install;

use App\Support\Install\EnvironmentFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EnvironmentFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'metaforger-env-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $file) {
            unlink($this->dir.DIRECTORY_SEPARATOR.$file);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    private function file(string $content): EnvironmentFile
    {
        file_put_contents($this->path(), $content);

        return new EnvironmentFile($this->path());
    }

    private function path(): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.'.env';
    }

    private function content(): string
    {
        return (string) file_get_contents($this->path());
    }

    #[Test]
    public function it_reads_a_setting_without_its_quotes_or_its_comment(): void
    {
        $env = $this->file("A=plain\nB=\"two words\"\nC='single'\nD=value # a note\nE=\"quoted # not a note\"\nF=\n");

        $this->assertSame('plain', $env->get('A'));
        $this->assertSame('two words', $env->get('B'));
        $this->assertSame('single', $env->get('C'));
        $this->assertSame('value', $env->get('D'));
        $this->assertSame('quoted # not a note', $env->get('E'));
        $this->assertSame('', $env->get('F'));
    }

    #[Test]
    public function it_finds_nothing_for_a_setting_that_is_not_there(): void
    {
        $env = $this->file("APP_NAME_LONG=x\n# APP_NAME=commented\n  OTHER=y\n");

        $this->assertNull($env->get('APP_NAME'));
        $this->assertSame('y', $env->get('OTHER'));
        $this->assertNull((new EnvironmentFile($this->dir.DIRECTORY_SEPARATOR.'missing'))->get('A'));
    }

    #[Test]
    public function it_replaces_only_the_line_it_was_asked_to(): void
    {
        $env = $this->file("# Site\nAPP_NAME=Old\n\nAPP_NAME_LONG=\"Old, longer\"\n# APP_NAME=commented\nAPP_URL=http://localhost # here\n");

        $env->set('APP_NAME', 'New');

        $this->assertSame("# Site\nAPP_NAME=New\n\nAPP_NAME_LONG=\"Old, longer\"\n# APP_NAME=commented\nAPP_URL=http://localhost # here\n", $this->content());
    }

    #[Test]
    public function it_keeps_windows_line_endings(): void
    {
        $env = $this->file("A=1\r\nAPP_URL=http://old.test\r\nB=2\r\n");

        $env->set('APP_URL', 'http://new.test');
        $env->set('ADDED', 'yes');

        $this->assertSame("A=1\r\nAPP_URL=http://new.test\r\nB=2\r\nADDED=yes\r\n", $this->content());
    }

    #[Test]
    public function it_adds_a_setting_that_is_not_there_on_a_line_of_its_own(): void
    {
        $this->file("A=1\nB=2")->set('C', '3');

        $this->assertSame("A=1\nB=2\nC=3\n", $this->content());
    }

    #[Test]
    public function it_makes_the_file_when_it_is_not_there(): void
    {
        $env = new EnvironmentFile($this->path());

        $this->assertFalse($env->exists());

        $env->set('A', '1');

        $this->assertTrue($env->exists());
        $this->assertSame("A=1\n", $this->content());
    }

    #[Test]
    public function it_changes_a_setting_that_is_written_twice_in_both_places(): void
    {
        $this->file("KEY=one\nOTHER=x\nKEY=two\n")->set('KEY', 'three');

        $this->assertSame("KEY=three\nOTHER=x\nKEY=three\n", $this->content());
    }

    #[Test]
    public function it_does_not_read_a_dollar_or_a_backslash_in_the_value_as_a_reference(): void
    {
        $env = $this->file("APP_NAME=Old\n");

        $env->set('APP_NAME', 'Cost $1 \\0 and $0');

        $this->assertSame('Cost $1 \\0 and $0', $env->get('APP_NAME'));
    }

    /**
     * @return array<string, array{string, string}> The value, and how it is written.
     */
    public static function quoting(): array
    {
        return [
            'plain' => ['http://localhost:8000', 'http://localhost:8000'],
            'empty' => ['', ''],
            'spaces' => ['My Site', '"My Site"'],
            'a hash' => ['Mine #1', '"Mine #1"'],
            'a dollar' => ['Price $5', "'Price \$5'"],
            'a backslash' => ['C:\\sites', "'C:\\sites'"],
            'a double quote' => ['The "best" site', "'The \"best\" site'"],
            'a single quote' => ["Tom's site", "\"Tom's site\""],
        ];
    }

    #[Test]
    #[DataProvider('quoting')]
    public function it_writes_a_value_in_the_quotes_that_keep_it_as_it_is(string $value, string $written): void
    {
        $env = $this->file("A=1\n");

        $env->set('NAME', $value);

        $this->assertStringContainsString("\nNAME={$written}\n", $this->content());
        $this->assertSame($value, $env->get('NAME'));
    }

    #[Test]
    public function it_refuses_a_value_with_a_line_break(): void
    {
        $env = $this->file("A=1\n");

        $this->expectException(InvalidArgumentException::class);

        try {
            $env->set('NAME', "one\ntwo");
        } finally {
            $this->assertSame("A=1\n", $this->content());
        }
    }

    #[Test]
    public function it_refuses_a_value_the_file_cannot_hold(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->file("A=1\n")->set('NAME', "It's \$5");
    }

    #[Test]
    public function it_copies_the_example(): void
    {
        $example = $this->dir.DIRECTORY_SEPARATOR.'example';
        file_put_contents($example, "APP_KEY=\n");
        $env = new EnvironmentFile($this->path());

        $env->createFrom($example);

        $this->assertSame("APP_KEY=\n", $this->content());
    }

    #[Test]
    public function it_says_so_when_there_is_no_example(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('There is no');

        (new EnvironmentFile($this->path()))->createFrom($this->dir.DIRECTORY_SEPARATOR.'nothing');
    }
}

<?php

namespace Tests\Feature;

use App\Services\Agents\OpenBaoAgentFileToken;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OpenBaoAgentFileTokenTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = realpath(sys_get_temp_dir()).'/chub-agent-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function write(string $value, string $name = 'token'): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $value);
        chmod($path, 0600);

        return $path;
    }

    private function rejected(OpenBaoAgentFileToken $resolver): void
    {
        try {
            $resolver();
            $this->fail('Unsafe sink accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('Host authentication unavailable.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_atomic_replacement_is_read_and_removal_has_no_cached_fallback(): void
    {
        $path = $this->write('synthetic-first');
        $resolver = new OpenBaoAgentFileToken($path);
        $this->assertSame('synthetic-first', $resolver());
        rename($this->write('synthetic-next', 'replacement'), $path);
        $this->assertSame('synthetic-next', $resolver());
        unlink($path);
        $this->rejected($resolver);
        $this->assertStringNotContainsString($path, print_r($resolver, true));
        $this->expectException(RuntimeException::class);
        serialize($resolver);
    }

    public function test_permissions_links_and_malformed_contents_fail_closed(): void
    {
        $path = $this->write('synthetic-value');
        $resolver = new OpenBaoAgentFileToken($path);
        chmod($path, 0640);
        $this->rejected($resolver);
        chmod($path, 0600);
        chmod($this->directory, 0750);
        $this->rejected($resolver);
        chmod($this->directory, 0700);
        link($path, $this->directory.'/hardlink');
        $this->rejected($resolver);
        unlink($this->directory.'/hardlink');
        unlink($path);
        symlink($this->write('synthetic-target', 'target'), $path);
        $this->rejected($resolver);
        unlink($path);
        foreach (['', "synthetic\n", "synthetic\0value", str_repeat('x', 8193)] as $value) {
            $this->write($value);
            $this->rejected($resolver);
        }
        $this->rejected(new OpenBaoAgentFileToken('relative/token'));
    }
}

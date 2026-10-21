<?php

namespace ScheduledSend\Tests;

use PHPUnit\Framework\TestCase;
use ScheduledSend\Process;

/**
 * Runs PHP scripts as children that misbehave in different ways.
 */
class ProcessTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scheduled_send_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    /**
     * @return string[] Command that runs the given PHP code
     */
    private function php(string $code): array
    {
        $script = $this->dir . '/child_' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($script, "<?php\n" . $code);

        return [PHP_BINARY, $script];
    }

    public function testStderrFloodBeforeReadingStdinDoesNotDeadlock(): void
    {
        $count = $this->dir . '/count';
        $argv  = $this->php('
            // Blocks once the pipe is full unless the parent drains stderr meanwhile
            fwrite(STDERR, str_repeat("e", 1500000));
            $n = 0;
            while (!feof(STDIN)) {
                $n += strlen(fread(STDIN, 65536));
            }
            file_put_contents(' . var_export($count, true) . ', $n);
            echo "done";
        ');

        $started = microtime(true);
        $result  = Process::run($argv, str_repeat('s', 1500000), 20);

        $this->assertLessThan(15, microtime(true) - $started);
        $this->assertFalse($result->timedOut());
        $this->assertTrue($result->inputSent());
        $this->assertSame(0, $result->exitCode());
        $this->assertSame('1500000', file_get_contents($count));
        $this->assertSame(Process::MAX_OUTPUT, strlen($result->output()));
    }

    public function testLargeInputRoundTripsThroughPartialWrites(): void
    {
        $data   = random_bytes(3000000);
        $result = Process::run($this->php('echo md5(stream_get_contents(STDIN));'), $data, 20);

        $this->assertTrue($result->inputSent());
        $this->assertSame(md5($data), $result->output());
        $this->assertSame(0, $result->exitCode());
    }

    public function testChildThatNeverReadsInputIsKilledAtDeadline(): void
    {
        $pidFile = $this->dir . '/pid';
        $argv    = $this->php('file_put_contents(' . var_export($pidFile, true) . ', getmypid()); sleep(30);');

        $started = microtime(true);
        $result  = Process::run($argv, str_repeat('x', 2000000), 1);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result->timedOut());
        $this->assertFalse($result->inputSent());
        $this->assertNull($result->exitCode());
        $this->assertGreaterThanOrEqual(1, $elapsed);
        $this->assertLessThan(8, $elapsed);
        $this->assertChildGone((int) file_get_contents($pidFile));
    }

    public function testTimeoutAfterAllInputWasWritten(): void
    {
        $pidFile = $this->dir . '/pid';
        $argv    = $this->php('
            file_put_contents(' . var_export($pidFile, true) . ', getmypid());
            stream_get_contents(STDIN);
            echo "got it";
            sleep(30);
        ');

        $result = Process::run($argv, str_repeat('x', 300000), 2);

        $this->assertTrue($result->timedOut());
        $this->assertTrue($result->inputSent());
        $this->assertNull($result->exitCode());
        $this->assertChildGone((int) file_get_contents($pidFile));
    }

    public function testChildThatIgnoresSigtermIsKilled(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl is not available');
        }

        $argv = $this->php('
            pcntl_signal(SIGTERM, SIG_IGN);
            stream_get_contents(STDIN);
            sleep(30);
        ');

        $started = microtime(true);
        $result  = Process::run($argv, 'x', 1);

        $this->assertTrue($result->timedOut());
        $this->assertTrue($result->inputSent());
        $this->assertLessThan(10, microtime(true) - $started);
    }

    /**
     * @dataProvider exitCodes
     */
    public function testExitCodeIsPropagated(int $code): void
    {
        $result = Process::run($this->php("echo 'out'; fwrite(STDERR, 'err'); exit($code);"), '', 10);

        $this->assertSame($code, $result->exitCode());
        $this->assertFalse($result->timedOut());
        $this->assertStringContainsString('out', $result->output());
        $this->assertStringContainsString('err', $result->output());
    }

    public function exitCodes(): array
    {
        return [[0], [1], [75], [255]];
    }

    public function testChildThatExitsBeforeReadingInputReportsIt(): void
    {
        $result = Process::run($this->php('fwrite(STDERR, "go away"); exit(67);'), str_repeat('x', 2000000), 10);

        $this->assertSame(67, $result->exitCode());
        $this->assertFalse($result->inputSent());
        $this->assertFalse($result->timedOut());
        $this->assertSame('go away', $result->output());
    }

    public function testEmptyInputIsClosedAtOnce(): void
    {
        // Would wait for end-of-input forever if stdin stayed open
        $result = Process::run($this->php('echo strlen(stream_get_contents(STDIN));'), '', 10);

        $this->assertTrue($result->inputSent());
        $this->assertSame('0', $result->output());
    }

    public function testChildKilledBySignalHasNoExitCode(): void
    {
        $result = Process::run($this->php('stream_get_contents(STDIN); posix_kill(getmypid(), 9); sleep(5);'), 'x', 10);

        $this->assertNull($result->exitCode());
        $this->assertFalse($result->timedOut());
        $this->assertTrue($result->inputSent());
    }

    public function testGrandchildHoldingThePipeDoesNotHoldUsUp(): void
    {
        // The child exits at once, a background process keeps stdout open for a while
        $argv = ['/bin/sh', '-c', 'echo hello; sleep 5 & exit 3'];

        $started = microtime(true);
        $result  = Process::run($argv, '', 20);

        $this->assertLessThan(4, microtime(true) - $started);
        $this->assertSame(3, $result->exitCode());
        $this->assertFalse($result->timedOut());
        $this->assertSame('hello', trim($result->output()));
    }

    public function testMissingProgramThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        Process::run([$this->dir . '/does-not-exist'], '', 5);
    }

    public function testInvalidArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Process::run(['/bin/true'], '', 0);
    }

    private function assertChildGone(int $pid): void
    {
        $this->assertGreaterThan(0, $pid);

        if (function_exists('posix_kill')) {
            $this->assertFalse(posix_kill($pid, 0), 'Child is still running');
        }
    }
}

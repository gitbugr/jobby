<?php
namespace Jobby;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Maknz\Slack\Client as Slack;

class Helper
{
    /**
     * @var int
     */
    public const UNIX = 0;

    /**
     * @var int
     */
    public const WINDOWS = 1;

    /**
     * @var resource[]
     */
    private array $lockHandles = [];

    /**
     * The Guzzle HTTP client instance
     *
     * @var Guzzle
     */
    protected Guzzle $guzzle;


    public function __construct()
    {
        $this->guzzle = new Guzzle;
    }

    /**
     * @throws Exception
     * @throws InfoException
     */
    public function acquireLock(string $lockFile): void
    {
        if (array_key_exists($lockFile, $this->lockHandles)) {
            throw new Exception("Lock already acquired (Lockfile: $lockFile).");
        }

        if (!file_exists($lockFile) && !touch($lockFile)) {
            throw new Exception("Unable to create file (File: $lockFile).");
        }

        $fh = fopen($lockFile, 'rb+');
        if ($fh === false) {
            throw new Exception("Unable to open file (File: $lockFile).");
        }

        $attempts = 5;
        while ($attempts > 0) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                $this->lockHandles[$lockFile] = $fh;
                ftruncate($fh, 0);
                fwrite($fh, getmypid());

                return;
            }
            usleep(250);
            --$attempts;
        }

        throw new InfoException("Job is still locked (Lockfile: $lockFile)!");
    }

    /**
     * @throws Exception
     */
    public function releaseLock(string $lockFile): void
    {
        if (!array_key_exists($lockFile, $this->lockHandles)) {
            throw new Exception("Lock NOT held - bug? Lockfile: $lockFile");
        }

        if ($this->lockHandles[$lockFile]) {
            ftruncate($this->lockHandles[$lockFile], 0);
            flock($this->lockHandles[$lockFile], LOCK_UN);
        }

        unset($this->lockHandles[$lockFile]);
    }

    public function getLockLifetime(string $lockFile): int
    {
        if (!file_exists($lockFile)) {
            return 0;
        }

        $pid = file_get_contents($lockFile);
        if (empty($pid)) {
            return 0;
        }

        if (!posix_kill((int) $pid, 0)) {
            return 0;
        }

        $stat = stat($lockFile);

        return (time() - $stat['mtime']);
    }

    public function getTempDir(): string
    {
        // @codeCoverageIgnoreStart
        if (function_exists('sys_get_temp_dir')) {
            $tmp = sys_get_temp_dir();
        } elseif (!empty($_SERVER['TMP'])) {
            $tmp = $_SERVER['TMP'];
        } elseif (!empty($_SERVER['TEMP'])) {
            $tmp = $_SERVER['TEMP'];
        } elseif (!empty($_SERVER['TMPDIR'])) {
            $tmp = $_SERVER['TMPDIR'];
        } else {
            $tmp = getcwd();
        }
        // @codeCoverageIgnoreEnd

        return $tmp;
    }

    public function getHost(): string
    {
        return php_uname('n');
    }

    public function getApplicationEnv(): ?string
    {
        return $_SERVER['APPLICATION_ENV'] ?? null;
    }

    public function getPlatform(): int
    {
        if (strncasecmp(PHP_OS_FAMILY, 'Win', 3) === 0) {
            // @codeCoverageIgnoreStart
            return self::WINDOWS;
            // @codeCoverageIgnoreEnd
        }

        return self::UNIX;
    }

    public function escape(string $input): string
    {
        $input = strtolower($input);
        $input = preg_replace('/[^a-z0-9_. -]+/', '', $input);
        $input = trim($input);
        $input = str_replace(' ', '_', $input);

        return preg_replace('/_{2,}/', '_', $input);
    }

    public function getSystemNullDevice(): string
    {
        $platform = $this->getPlatform();
        if ($platform === self::UNIX) {
            return '/dev/null';
        }
        return 'NUL';
    }

    public function sendSlackAlert(string $job, array $config, string $message): void
    {
        $host = $this->getHost();
        $body = <<<EOF
        $message

        You can find output for '$job' in {$config['output']} on $host.

        Best,
        jobby@$host
        EOF;

        $client = new Slack($config['slackUrl']);
        $client->to($config['slackChannel']);

        if ($config['slackSender']) {
            $client->from($config['slackSender']);
        }

        $client->send($body);

    }

    /**
     * @throws JsonException
     * @throws GuzzleException
     */
    public function sendMattermostAlert(string $job, array $config, string $message): void
    {
        $host = $this->getHost();
        $body = <<<EOF
        $message

        You can find output for '$job' in {$config['output']} on $host.

        Best,
        jobby@$host
        EOF;
        $payload = ['text'=>$body];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->guzzle->post($config['mattermostUrl'], ['body' => $encoded]);

    }
}

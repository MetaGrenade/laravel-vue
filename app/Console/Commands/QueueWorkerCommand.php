<?php

namespace App\Console\Commands;

use App\Support\Queues\WorkerQueues;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Starts Laravel's `queue:work` or `queue:listen` on every queue the app uses: the connection's own (DB_QUEUE, REDIS_QUEUE) and the ones
 * the app names, `default` and `mail` (docs/redis.md). The Laravel commands read one queue unless `--queue` lists more, and `--queue`
 * replaces the connection's own, so a command line cannot be written once for every site; this one looks it up. Everything after the
 * mode is handed to the Laravel command as typed, and a `--queue` you give is kept.
 */
class QueueWorkerCommand extends Command
{
    protected $signature = 'metaforge:queue
        {mode? : work (queue:work) or listen (queue:listen)}';

    protected $description = "Run queue:work or queue:listen on the connection's own queue, default and mail";

    public function __construct()
    {
        parent::__construct();

        // The options belong to queue:work and queue:listen, which check them.
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $tokens = $this->input instanceof ArgvInput ? $this->input->getRawTokens(true) : [(string) $this->argument('mode')];
        $mode = array_shift($tokens);

        if (! in_array($mode, ['work', 'listen'], true)) {
            $this->components->error('Say work or listen first, then any options of queue:work or queue:listen: php artisan metaforge:queue work --tries=3');

            return self::INVALID;
        }

        $name = "queue:$mode";
        $worker = $this->getApplication()->find($name);
        $worker->mergeApplicationDefinition();

        // Read with the worker's own definition, to learn which connection it will use and whether it was told what to read.
        $given = new ArgvInput(['artisan', $name, ...$tokens], $worker->getDefinition());

        if (! filled($given->getOption('queue'))) {
            $tokens[] = '--queue='.implode(',', WorkerQueues::forConnection($given->getArgument('connection')));
        }

        return $worker->run(new ArgvInput(['artisan', $name, ...$tokens]), $this->output);
    }
}

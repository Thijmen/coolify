<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Facades\Process;

class RemoteProcessExecutionerService
{
    public function execute(string $command): RemoteProcessExecutedResult
    {
        $timeout = config('constants.ssh.command_timeout');
        $process = Process::timeout($timeout)->run($command);

        $output = trim($process->output());
        $exitCode = $process->exitCode();

        return new RemoteProcessExecutedResult($output, $exitCode);
    }
}


// TODO: Move to own class
class RemoteProcessExecutedResult {
    public function __construct(private string $output, private int $exitCode) {}

    public function getOutput(): string {
        return $this->output;
    }

    public function getExitCode(): int {
        return $this->exitCode;
    }
}

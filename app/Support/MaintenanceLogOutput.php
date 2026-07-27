<?php

namespace App\Support;

use Symfony\Component\Console\Output\Output;

/**
 * A console Output that streams each finished line straight into the maintenance log as it is
 * written — so a long-running task (e.g. feed:vacuum over many stores) shows live progress in the
 * admin popup instead of dumping everything only when it finishes. Partial writes are buffered
 * until a newline; call flush() for any trailing remainder.
 */
class MaintenanceLogOutput extends Output
{
    private string $buffer = '';

    protected function doWrite(string $message, bool $newline): void
    {
        $this->buffer .= $message.($newline ? "\n" : '');

        while (($p = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $p), "\r");
            $this->buffer = substr($this->buffer, $p + 1);
            if (trim($line) !== '') {
                MaintenanceLog::write($line);
            }
        }
    }

    /** Emit any buffered text that never ended in a newline. */
    public function flush(): void
    {
        if (trim($this->buffer) !== '') {
            MaintenanceLog::write(rtrim($this->buffer, "\r\n"));
        }
        $this->buffer = '';
    }
}

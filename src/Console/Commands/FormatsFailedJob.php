<?php

namespace Bref\LaravelBridge\Console\Commands;

trait FormatsFailedJob
{
    /**
     * Normalize a failed-job record (shape differs between providers: DB,
     * DynamoDB, File…) into a consistent JSON structure.
     */
    protected function format(object $job): array
    {
        $payload = is_string($job->payload ?? null) ? json_decode($job->payload, true) : null;
        $payload = is_array($payload) ? $payload : [];

        return [
            'id' => $job->id ?? null,
            'uuid' => $payload['uuid'] ?? null,
            'connection' => $job->connection ?? null,
            'queue' => $job->queue ?? null,
            'name' => $payload['displayName'] ?? ($payload['job'] ?? null),
            'exception' => $job->exception ?? null,
            'failed_at' => isset($job->failed_at) ? (string) $job->failed_at : null,
            'payload' => $payload,
        ];
    }

    protected function writeJson(mixed $data): void
    {
        $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR));
    }
}

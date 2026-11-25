<?php

declare(strict_types=1);

namespace Bruno;

use SensitiveParameter;

final class MediaWorker
{
    /**
     * Share the media runner between the HTTPS and CLI schedules.
     */
    public function __construct(
        private readonly AnkiBridge $anki,
        private readonly Gateway $gateway,
        private readonly string $directory,
        #[SensitiveParameter] private readonly string $aiKey,
        #[SensitiveParameter] private readonly string $falKey,
    ) {
    }

    /**
     * Submit no more than one mnemonic batch at a time without locking the collection during the request.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $result = $this->anki->call('jobs', ['aiKey' => $this->aiKey, 'falKey' => $this->falKey]);
        if ($this->aiKey === '') {
            return $result;
        }
        $lock = fopen($this->directory . '/mnemonic-worker.lock', mode: 'c');
        if ($lock === false) {
            throw new AppException('Merksatz-Aufträge konnten nicht geöffnet werden.', 503);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return $result;
            }
            $batch = $this->anki->call('claim-mnemonics');
            if (!$batch['cards']) {
                return $result;
            }
            $cards = array_map(static fn (array $card): array => ['front' => $card['front'], 'back' => $card['back']], $batch['cards']);
            $mnemonics = [];
            try {
                $mnemonics = $this->gateway->mnemonics($cards);
            } finally {
                $saved = $this->anki->call('finish-mnemonics', ['batch' => $batch, 'results' => (object) $mnemonics]);
            }

            return $result + ['mnemonics' => $saved];
        } finally {
            fclose($lock);
        }
    }
}

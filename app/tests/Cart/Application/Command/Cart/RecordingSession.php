<?php

namespace Siroko\Tests\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Transaction\TransactionalSession;

/**
 * Stands in for the Doctrine session, recording that a handler asked for one
 * transaction and that its writes happened inside it.
 *
 * Vive en su propio fichero, y no dentro del test que lo estrenó, para que el
 * autoload PSR-4 lo encuentre: compartido entre varios tests, sólo funcionaba
 * mientras PHPUnit cargara antes el fichero que lo declaraba, así que ejecutar
 * uno de los otros por separado -`--filter`, un solo fichero- fallaba con
 * "Class not found".
 */
final class RecordingSession implements TransactionalSession
{
    public int $transactions = 0;

    /** @var list<string> */
    public array $log = [];

    public function executeAtomically(callable $operation): mixed
    {
        $this->transactions++;
        $this->log[] = 'begin';

        $result = $operation();

        $this->log[] = 'commit';

        return $result;
    }
}

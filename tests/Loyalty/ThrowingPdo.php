<?php

namespace Tests\Loyalty;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO that can be told to blow up on one specific statement.
 *
 * Used to induce a mid-operation failure so the rollback path can be asserted on
 * observable end state. The loyalty service performs several writes per movement
 * (ledger insert, then cache recompute) and the only way to prove they are one
 * unit is to break the second one and check the first did not survive.
 */
class ThrowingPdo extends PDO
{
    /** @var string|null case-insensitive substring; a matching statement throws */
    private $failOn;

    public function failOnStatementContaining(?string $needle): void
    {
        $this->failOn = $needle;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failOn !== null && stripos($query, $this->failOn) !== false) {
            throw new PDOException('induced failure on: ' . $this->failOn);
        }

        return parent::prepare($query, $options);
    }
}

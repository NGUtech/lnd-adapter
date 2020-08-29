<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lnd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lnd\Migration\RabbitMq;

use Daikon\RabbitMq3\Migration\RabbitMq3Migration;

final class SetupLndQueue20190311144000 extends RabbitMq3Migration
{
    public function getDescription(string $direction = self::MIGRATE_UP): string
    {
        return $direction === self::MIGRATE_UP
            ? 'Create RabbitMQ queue for LND messages.'
            : 'Delete RabbitMQ queue for LND messages.';
    }

    public function isReversible(): bool
    {
        return true;
    }

    protected function up(): void
    {
        $this->declareQueue('lnd.adapter.messages', false, true, false, false);
        $this->bindQueue('lnd.adapter.messages', 'lnd.adapter.exchange', 'lnd.message.#');
    }

    protected function down(): void
    {
        $this->deleteQueue('lnd.adapter.messages');
    }
}

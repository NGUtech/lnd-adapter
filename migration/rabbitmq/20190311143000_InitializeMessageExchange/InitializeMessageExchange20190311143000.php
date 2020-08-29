<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lnd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lnd\Migration\RabbitMq;

use Daikon\RabbitMq3\Migration\RabbitMq3Migration;
use PhpAmqpLib\Exchange\AMQPExchangeType;

final class InitializeMessageExchange20190311143000 extends RabbitMq3Migration
{
    public function getDescription(string $direction = self::MIGRATE_UP): string
    {
        return $direction === self::MIGRATE_UP
            ? 'Create a RabbitMQ message exchange for the LND-Adapter context.'
            : 'Delete the RabbitMQ message message exchange for the LND-Adapter context.';
    }

    public function isReversible(): bool
    {
        return true;
    }

    protected function up(): void
    {
        $this->createMigrationList('lnd.adapter.migration_list');
        $this->declareExchange(
            'lnd.adapter.exchange',
            'x-delayed-message',
            false,
            true,
            false,
            false,
            false,
            ['x-delayed-type' => AMQPExchangeType::TOPIC]
        );
    }

    protected function down(): void
    {
        $this->deleteExchange('lnd.adapter.exchange');
        $this->deleteExchange('lnd.adapter.migration_list');
    }
}

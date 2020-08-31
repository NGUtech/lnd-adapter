<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lnd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lnd\Message;

use Daikon\AsyncJob\Worker\WorkerInterface;
use Daikon\Boot\Service\Provisioner\MessageBusProvisioner;
use Daikon\Interop\Assertion;
use Daikon\Interop\RuntimeException;
use Daikon\MessageBus\MessageBusInterface;
use Daikon\RabbitMq3\Connector\RabbitMq3Connector;
use Daikon\ValueObject\Timestamp;
use NGUtech\Bitcoin\Service\SatoshiCurrencies;
use NGUtech\Lightning\Message\LightningInvoiceMessageInterface;
use NGUtech\Lightning\Message\LightningMessageInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

final class LndMessageWorker implements WorkerInterface
{
    private const MESSAGE_INVOICE = 'lnd.message.invoice';
    private const MESSAGE_HTLC_EVENT = 'lnd.message.htlc_event';
    private const STATE_INVOICE_OPEN = 0;
    private const STATE_INVOICE_SETTLED = 1;
    private const STATE_INVOICE_CANCELLED = 2;
    private const STATE_INVOICE_ACCEPTED = 3;
    private const EVENT_HTLC_FORWARD = 7;
    private const EVENT_HTLC_FORWARD_FAIL = 8;
    private const EVENT_HTLC_SETTLE = 9;
    private const EVENT_HTLC_LINK_FAIL = 10;

    private RabbitMq3Connector $connector;

    private MessageBusInterface $messageBus;

    private LoggerInterface $logger;

    private array $settings;

    public function __construct(
        RabbitMq3Connector $connector,
        MessageBusInterface $messageBus,
        LoggerInterface $logger,
        array $settings = []
    ) {
        $this->connector = $connector;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function run(array $parameters = []): void
    {
        $queue = $parameters['queue'];
        Assertion::notBlank($queue);

        $messageHandler = function (AMQPMessage $amqpMessage): void {
            $this->execute($amqpMessage);
        };

        /** @var AMQPChannel $channel */
        $channel = $this->connector->getConnection()->channel();
        $channel->basic_qos(0, 1, false);
        $channel->basic_consume($queue, '', true, false, false, false, $messageHandler);

        while (count($channel->callbacks)) {
            $channel->wait();
        }
    }

    private function execute(AMQPMessage $amqpMessage): void
    {
        try {
            $message = $this->createMessage($amqpMessage);
            if ($message instanceof LightningMessageInterface) {
                $this->messageBus->publish($message, MessageBusProvisioner::EVENTS_CHANNEL);
            }
            $amqpMessage->ack();
        } catch (RuntimeException $error) {
            $this->logger->error(
                "Error handling lnd message '{$amqpMessage->getRoutingKey()}'.",
                ['exception' => $error->getTrace()]
            );
            $amqpMessage->nack();
        }
    }

    private function createMessage(AMQPMessage $amqpMessage): ?LightningMessageInterface
    {
        switch ($amqpMessage->getRoutingKey()) {
            case self::MESSAGE_INVOICE:
                $message = $this->createInvoiceMessage($amqpMessage);
                break;
            case self::MESSAGE_HTLC_EVENT:
                $message = $this->createHtlcMessage($amqpMessage);
                break;
            default:
                // ignore unknown routing keys
        }

        return $message ?? null;
    }

    private function createInvoiceMessage(AMQPMessage $amqpMessage): LightningInvoiceMessageInterface
    {
        $invoice = json_decode($amqpMessage->body, true);

        switch ($invoice['state']) {
            case self::STATE_INVOICE_OPEN:
                $messageFqcn = LndInvoiceRequested::class;
                $timestamp = Timestamp::fromTime($invoice['creationDate']);
                break;
            case self::STATE_INVOICE_SETTLED:
                $messageFqcn = LndInvoiceSettled::class;
                $timestamp = Timestamp::fromTime($invoice['settleDate']);
                break;
            case self::STATE_INVOICE_CANCELLED:
                $messageFqcn = LndInvoiceCancelled::class;
                $timestamp = $amqpMessage->get('timestamp');
                break;
            case self::STATE_INVOICE_ACCEPTED:
                $messageFqcn = LndInvoiceAccepted::class;
                $timestamp = $amqpMessage->get('timestamp');
                break;
            default:
                throw new RuntimeException("Unhandled LND invoice state '".$invoice['state']."'.");
        }

        return $messageFqcn::fromNative([
            'preimageHash' => $invoice['rHash'],
            'preimage' => $invoice['rPreimage'] ?? null,
            'request' => $invoice['paymentRequest'],
            'amount' => $invoice['valueMsat'].SatoshiCurrencies::MSAT,
            'amountPaid' => $invoice['amtPaidMsat'].SatoshiCurrencies::MSAT,
            'timestamp' => (string)$timestamp,
            'cltvExpiry' => $invoice['cltvExpiry']
        ]);
    }

    private function createHtlcMessage(AMQPMessage $amqpMessage): LightningMessageInterface
    {
        $event = json_decode($amqpMessage->body, true);

        switch ($event['event']) {
            case self::EVENT_HTLC_FORWARD:
                $messageFqcn = LndHtlcForwarded::class;
                break;
            case self::EVENT_HTLC_FORWARD_FAIL:
                $messageFqcn = LndHtlcForwardFailed::class;
                break;
            case self::EVENT_HTLC_SETTLE:
                $messageFqcn = LndHtlcSettled::class;
                break;
            case self::EVENT_HTLC_LINK_FAIL:
                $messageFqcn = LndHtlcLinkFailed::class;
                break;
            default:
                throw new RuntimeException("Unhandled LND event '".$event['event']."'.");
        }

        return $messageFqcn::fromNative([
            'incomingChannelId' => $event['incomingChannelId'],
            'outgoingChannelId' => $event['outgoingChannelId'],
            'incomingHtlcId' => $event['incomingHtlcId'],
            'outgoingHtlcId' => $event['outgoingHtlcId'],
            'timestamp' => substr_replace(substr($event['timestampNs'], 0, -3), '.', -6, 0),
            'eventType' => $event['eventType']
        ]);
    }
}

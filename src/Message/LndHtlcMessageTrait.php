<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lnd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lnd\Message;

use Daikon\Interop\Assertion;
use Daikon\ValueObject\IntValue;
use Daikon\ValueObject\Text;
use Daikon\ValueObject\Timestamp;

trait LndHtlcMessageTrait
{
    private Text $incomingChannelId;

    private Text $outgoingChannelId;

    private Text $incomingHtlcId;

    private Text $outgoingHtlcId;

    private Timestamp $timestamp;

    private IntValue $eventType;

    /** @param array $state */
    public static function fromNative($state): self
    {
        Assertion::isArray($state);
        Assertion::keyExists($state, 'incomingChannelId');
        Assertion::keyExists($state, 'outgoingChannelId');
        Assertion::keyExists($state, 'incomingHtlcId');
        Assertion::keyExists($state, 'outgoingHtlcId');
        Assertion::keyExists($state, 'timestamp');
        Assertion::keyExists($state, 'eventType');

        return new self(
            Text::fromNative($state['incomingChannelId']),
            Text::fromNative($state['outgoingChannelId']),
            Text::fromNative($state['incomingHtlcId']),
            Text::fromNative($state['outgoingHtlcId']),
            Timestamp::fromString($state['timestamp']),
            IntValue::fromNative($state['eventType']),
        );
    }

    public function getIncomingChannelId(): Text
    {
        return $this->incomingChannelId;
    }

    public function getOutgoingChannelId(): Text
    {
        return $this->outgoingChannelId;
    }

    public function getIncomingHtlcId(): Text
    {
        return $this->incomingHtlcId;
    }

    public function getOutgoingHtlcId(): Text
    {
        return $this->outgoingHtlcId;
    }

    public function getTimestamp(): Timestamp
    {
        return $this->timestamp;
    }

    public function getEventType(): IntValue
    {
        return $this->eventType;
    }

    public function toNative(): array
    {
        return [
            'incomingChannelId' => (string)$this->incomingChannelId,
            'outgoingChannelId' => (string)$this->outgoingChannelId,
            'incomingHtlcId' => (string)$this->incomingHtlcId,
            'outgoingHtlcId' => (string)$this->outgoingHtlcId,
            'timestamp' => (string)$this->timestamp,
            'eventType' => $this->eventType->toNative(),
        ];
    }

    private function __construct(
        Text $incomingChannelId,
        Text $outgoingChannelId,
        Text $incomingHtlcId,
        Text $outgoingHtlcId,
        Timestamp $timestamp,
        IntValue $eventType
    ) {
        $this->incomingChannelId = $incomingChannelId;
        $this->outgoingChannelId = $outgoingChannelId;
        $this->incomingHtlcId = $incomingHtlcId;
        $this->outgoingHtlcId = $outgoingHtlcId;
        $this->timestamp = $timestamp;
        $this->eventType = $eventType;
    }
}

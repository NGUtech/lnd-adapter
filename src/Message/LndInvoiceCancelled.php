<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lnd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lnd\Message;

use NGUtech\Lightning\Message\LightningInvoiceMessageInterface;
use NGUtech\Lightning\Message\LightningInvoiceMessageTrait;

final class LndInvoiceCancelled implements LightningInvoiceMessageInterface
{
    use LightningInvoiceMessageTrait;
}

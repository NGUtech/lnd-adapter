<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lnd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lnd\Service;

use Daikon\Interop\Assertion;
use Daikon\Money\Exception\PaymentServiceFailed;
use Invoicesrpc\AddHoldInvoiceRequest;
use Invoicesrpc\AddHoldInvoiceResp;
use Invoicesrpc\CancelInvoiceMsg;
use Invoicesrpc\CancelInvoiceResp;
use Invoicesrpc\SettleInvoiceMsg;
use Invoicesrpc\SettleInvoiceResp;
use NGUtech\Bitcoin\ValueObject\Hash;
use NGUtech\Lightning\Entity\LightningInvoice;
use NGUtech\Lightning\Service\LightningHoldServiceInterface;
use NGUtech\Lnd\Connector\LndGrpcClient;

final class LndHoldService extends LndService implements LightningHoldServiceInterface
{
    public function request(LightningInvoice $invoice): LightningInvoice
    {
        Assertion::true($this->canRequest($invoice->getAmount()), 'Lnd hold service cannot request given amount.');

        $expiry = $invoice->getExpiry()->toNative();
        Assertion::between($expiry, 60, 31536000, 'Invoice expiry is not acceptable.');

        $preimageHash = Hash::sum($invoice->getPreimage()->toBinary());

        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var AddHoldInvoiceResp $response */
        list($response, $status) = $client->invoicesrpc->AddHoldInvoice(new AddHoldInvoiceRequest([
            'hash' => $preimageHash->toBinary(),
            'memo' => (string)$invoice->getLabel(),
            'value_msat' => $invoice->getAmount()->getAmount(),
            'expiry' => $expiry = min($invoice->getExpiry()->toNative(), 31536000),
            'cltv_expiry' => $invoice->getCltvExpiry()->toNative()
        ]))->wait();

        if ($status->code !== 0) {
            $this->logger->error($status->details);
            throw new PaymentServiceFailed($status->details);
        }

        return $invoice->withValues([
            'preimageHash' => $preimageHash,
            'request' => $response->getPaymentRequest(),
            'expiry' => $expiry,
            'blockHeight' => $this->getInfo()['blockHeight']
        ]);
    }

    public function settle(LightningInvoice $invoice): bool
    {
        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var SettleInvoiceResp $response */
        list($response, $status) = $client->invoicesrpc->SettleInvoice(new SettleInvoiceMsg([
            'preimage' => $invoice->getPreimage()->toBinary()
        ]))->wait();

        return $status->code === 0;
    }

    public function cancel(LightningInvoice $invoice): bool
    {
        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var CancelInvoiceResp $response */
        list($response, $status) = $client->invoicesrpc->CancelInvoice(new CancelInvoiceMsg([
            'payment_hash' => $invoice->getPreimageHash()->toBinary()
        ]))->wait();

        return $status->code === 0;
    }
}

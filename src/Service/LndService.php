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
use Daikon\Money\Exception\PaymentServiceUnavailable;
use Daikon\Money\Service\MoneyServiceInterface;
use Daikon\Money\ValueObject\MoneyInterface;
use Daikon\ValueObject\Timestamp;
use Grpc\ServerStreamingCall;
use Lnrpc\AddInvoiceResponse;
use Lnrpc\FeeLimit;
use Lnrpc\GetInfoRequest;
use Lnrpc\GetInfoResponse;
use Lnrpc\Invoice;
use Lnrpc\Invoice\InvoiceState as LnrpcInvoiceState;
use Lnrpc\Payment;
use Lnrpc\Payment\PaymentStatus as LnrpcPaymentStatus;
use Lnrpc\PaymentFailureReason;
use Lnrpc\PaymentHash;
use Lnrpc\PayReq;
use Lnrpc\PayReqString;
use Lnrpc\QueryRoutesRequest;
use Lnrpc\QueryRoutesResponse;
use Lnrpc\Route;
use NGUtech\Bitcoin\Service\SatoshiCurrencies;
use NGUtech\Bitcoin\ValueObject\Bitcoin;
use NGUtech\Bitcoin\ValueObject\Hash;
use NGUtech\Lightning\Entity\LightningInvoice;
use NGUtech\Lightning\Service\LightningServiceInterface;
use NGUtech\Lightning\ValueObject\Request;
use NGUtech\Lightning\Entity\LightningPayment;
use NGUtech\Lightning\ValueObject\InvoiceState;
use NGUtech\Lightning\ValueObject\PaymentState;
use NGUtech\Lnd\Connector\LndGrpcClient;
use NGUtech\Lnd\Connector\LndGrpcConnector;
use Psr\Log\LoggerInterface;
use Routerrpc\SendPaymentRequest;
use Routerrpc\TrackPaymentRequest;

class LndService implements LightningServiceInterface
{
    protected LoggerInterface $logger;

    protected LndGrpcConnector $connector;

    protected MoneyServiceInterface $moneyService;

    protected array $settings;

    public function __construct(
        LoggerInterface $logger,
        LndGrpcConnector $connector,
        MoneyServiceInterface $moneyService,
        array $settings = []
    ) {
        $this->logger = $logger;
        $this->connector = $connector;
        $this->moneyService = $moneyService;
        $this->settings = $settings;
    }

    public function request(LightningInvoice $invoice): LightningInvoice
    {
        Assertion::true($this->canRequest($invoice->getAmount()), 'Lnd service cannot request given amount.');

        $expiry = $invoice->getExpiry()->toNative();
        Assertion::between($expiry, 60, 31536000, 'Invoice expiry is not acceptable.');

        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var AddInvoiceResponse $response */
        list($response, $status) = $client->lnrpc->AddInvoice(new Invoice([
            'r_preimage' => $invoice->getPreimage()->toBinary(),
            'memo' => (string)$invoice->getLabel(),
            'value_msat' => $this->convert((string)$invoice->getAmount())->getAmount(),
            'expiry' => $expiry,
            'cltv_expiry' => $invoice->getCltvExpiry()->toNative()
        ]))->wait();

        if ($status->code !== 0) {
            $this->logger->error($status->details);
            throw new PaymentServiceFailed($status->details);
        }

        return $invoice->withValues([
            'preimageHash' => bin2hex($response->getRHash()),
            'request' => $response->getPaymentRequest(),
            'expiry' => $expiry,
            'blockHeight' => $this->getInfo()['blockHeight'],
            'createdAt' => Timestamp::now()
        ]);
    }

    public function send(LightningPayment $payment): LightningPayment
    {
        Assertion::true($this->canSend($payment->getAmount()), 'Lnd service cannot send given amount.');

        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var ServerStreamingCall $stream */
        $stream = $client->routerrpc->SendPaymentV2(new SendPaymentRequest([
            'max_parts' => $this->settings['send']['max_parts'] ?? 5,
            'payment_request' => (string)$payment->getRequest(),
            'timeout_seconds' => $this->settings['send']['timeout'] ?? 30,
            'fee_limit_msat' => $payment->getFeeEstimate()->getAmount(),
        ]), [], ['timeout' => ($this->settings['send']['timeout'] ?? 30) * 1000000]);

        $result = null;
        foreach ($stream->responses() as $response) {
            $result = $response;
        }

        if ($stream->getStatus()->code !== 0) {
            $this->logger->error($stream->getStatus()->details);
            throw new PaymentServiceFailed($stream->getStatus()->details);
        }

        /** @var Payment $result */
        if ($result->getStatus() === LnrpcPaymentStatus::FAILED) {
            $failureCode = $result->getFailureReason();
            $failureMessage = PaymentFailureReason::name($failureCode);
            if (in_array($failureCode, [
                PaymentFailureReason::FAILURE_REASON_NO_ROUTE,
                PaymentFailureReason::FAILURE_REASON_INSUFFICIENT_BALANCE
            ])) {
                throw new PaymentServiceUnavailable($failureMessage);
            }
            $this->logger->error($failureMessage);
            throw new PaymentServiceFailed($failureMessage);
        }

        return $payment->withValues([
            'preimage' => $result->getPaymentPreimage(),
            'preimageHash' => $result->getPaymentHash(),
            'feeSettled' => $result->getFeeMsat().SatoshiCurrencies::MSAT,
        ]);
    }

    public function decode(Request $request): LightningInvoice
    {
        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var PayReq $response */
        list($response, $status) = $client->lnrpc->DecodePayReq(
            new PayReqString(['pay_req' => (string)$request])
        )->wait();

        if ($status->code !== 0) {
            $this->logger->error($status->details);
            throw new PaymentServiceFailed($status->details);
        }

        return LightningInvoice::fromNative([
            'preimageHash' => $response->getPaymentHash(),
            'request' => $request,
            'destination' => $response->getDestination(),
            'amount' => $response->getNumMsat().SatoshiCurrencies::MSAT,
            'description' => $response->getDescription(),
            'expiry' => $response->getExpiry(),
            'cltvExpiry' => $response->getCltvExpiry(),
            'createdAt' => $response->getTimestamp()
        ]);
    }

    public function estimateFee(LightningPayment $payment): Bitcoin
    {
        $feeLimit = $payment->getAmount()->percentage($payment->getFeeLimit()->toNative(), Bitcoin::ROUND_UP);

        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var QueryRoutesResponse $response */
        list($response, $status) = $client->lnrpc->QueryRoutes(
            new QueryRoutesRequest([
                'pub_key' => (string)$payment->getDestination(),
                'amt_msat' => $payment->getAmount()->getAmount(),
                'fee_limit' => new FeeLimit(['fixed_msat' => $feeLimit->getAmount()])
            ])
        )->wait();

        if ($status->code !== 0) {
            $this->logger->error($status->details);
            throw new PaymentServiceFailed($status->details);
        }

        $routeFee = Bitcoin::zero();
        /** @var Route $route */
        foreach ($response->getRoutes() as $route) {
            $currentRouteFee = Bitcoin::fromNative($route->getTotalFeesMsat().SatoshiCurrencies::MSAT);
            if (!$currentRouteFee->isLessThanOrEqual($routeFee)) {
                $routeFee = $currentRouteFee;
            }
        }

        //@risky if a zero cost route is available then assume node will use that
        return !$routeFee->isZero() && $feeLimit->isGreaterThanOrEqual($routeFee) ? $feeLimit : $routeFee;
    }

    public function getInvoice(Hash $preimageHash): ?LightningInvoice
    {
        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var Invoice $invoice */
        list($invoice, $status) = $client->lnrpc->LookupInvoice(
            new PaymentHash(['r_hash_str' => (string)$preimageHash])
        )->wait();

        if ($status->code !== 0) {
            return null;
        }

        return LightningInvoice::fromNative([
            'preimage' => bin2hex($invoice->getRPreimage()),
            'preimageHash' => bin2hex($invoice->getRHash()),
            'request' => $invoice->getPaymentRequest(),
            'amount' => $invoice->getValueMsat().SatoshiCurrencies::MSAT,
            'amountPaid' => $invoice->getAmtPaidMsat().SatoshiCurrencies::MSAT,
            'label' => $invoice->getMemo(),
            'state' => (string)$this->mapInvoiceState($invoice->getState()),
            'createdAt' => $invoice->getCreationDate()
        ]);
    }

    public function getPayment(Hash $preimageHash): ?LightningPayment
    {
        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        $stream = $client->routerrpc->TrackPaymentV2(new TrackPaymentRequest([
            'payment_hash' => $preimageHash->toBinary(),
            'no_inflight_updates' => true
        ]), [], ['timeout' => 5 * 1000000]);

        $payment = null;
        foreach ($stream->responses() as $response) {
            /** @var Payment $payment */
            $payment = $response;
        }

        if ($stream->getStatus()->code !== 0) {
            return null;
        }

        //@todo return null if not found
        if (!$payment) {
            $this->logger->error($stream->getStatus()->details);
            throw new PaymentServiceFailed($stream->getStatus()->details);
        }

        return LightningPayment::fromNative([
            'preimage' => $payment->getPaymentPreimage(),
            'preimageHash' => $payment->getPaymentHash(),
            'request' => $payment->getPaymentRequest(),
            'amount' => $payment->getValueMsat().SatoshiCurrencies::MSAT,
            'amountPaid' => $payment->getValueMsat().SatoshiCurrencies::MSAT, //may change in future
            'feeSettled' => $payment->getFeeMsat().SatoshiCurrencies::MSAT,
            'state' => (string)$this->mapPaymentState($payment->getStatus()),
            'createdAt' => $payment->getCreationDate()
        ]);
    }

    public function getInfo(): array
    {
        /** @var LndGrpcClient $client */
        $client = $this->connector->getConnection();

        /** @var GetInfoResponse $response */
        list($response, $status) = $client->lnrpc->GetInfo(new GetInfoRequest)->wait();

        if ($status->code !== 0) {
            $this->logger->error($status->details);
            throw new PaymentServiceFailed($status->details);
        }

        return json_decode($response->serializeToJsonString(), true);
    }

    public function canRequest(MoneyInterface $amount): bool
    {
        return ($this->settings['request']['enabled'] ?? true)
            && $amount->isGreaterThanOrEqual(
                $this->convert(($this->settings['request']['minimum'] ?? LightningInvoice::AMOUNT_MIN))
            ) && $amount->isLessThanOrEqual(
                $this->convert(($this->settings['request']['maximum'] ?? LightningInvoice::AMOUNT_MAX))
            );
    }

    public function canSend(MoneyInterface $amount): bool
    {
        return ($this->settings['send']['enabled'] ?? true)
            && $amount->isGreaterThanOrEqual(
                $this->convert(($this->settings['send']['minimum'] ?? LightningInvoice::AMOUNT_MIN))
            ) && $amount->isLessThanOrEqual(
                $this->convert(($this->settings['send']['maximum'] ?? LightningInvoice::AMOUNT_MAX))
            );
    }

    protected function convert(string $amount, string $currency = SatoshiCurrencies::MSAT): Bitcoin
    {
        return $this->moneyService->convert($this->moneyService->parse($amount), $currency);
    }

    protected function mapInvoiceState(int $state): InvoiceState
    {
        $invoiceState = null;
        switch ($state) {
            case LnrpcInvoiceState::OPEN:
            case LnrpcInvoiceState::ACCEPTED:
                $invoiceState = InvoiceState::PENDING;
                break;
            case LnrpcInvoiceState::SETTLED:
                $invoiceState = InvoiceState::SETTLED;
                break;
            case LnrpcInvoiceState::CANCELED:
                $invoiceState = InvoiceState::CANCELLED;
                break;
            default:
                throw new PaymentServiceFailed("Unknown invoice state '$state'.");
        }
        return InvoiceState::fromNative($invoiceState);
    }

    protected function mapPaymentState(int $state): PaymentState
    {
        $paymentState = null;
        switch ($state) {
            case LnrpcPaymentStatus::UNKNOWN:
            case LnrpcPaymentStatus::IN_FLIGHT:
                $paymentState = PaymentState::PENDING;
                break;
            case LnrpcPaymentStatus::SUCCEEDED:
                $paymentState = PaymentState::COMPLETED;
                break;
            case LnrpcPaymentStatus::FAILED:
                $paymentState = PaymentState::FAILED;
                break;
            default:
                throw new PaymentServiceFailed("Unknown payment state '$state'.");
        }
        return PaymentState::fromNative($paymentState);
    }
}

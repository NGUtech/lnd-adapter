<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lnd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lnd\Connector;

use Invoicesrpc\InvoicesClient;
use Lnrpc\LightningClient;
use Routerrpc\RouterClient;

final class LndGrpcClient
{
    public LightningClient $lnrpc;

    public InvoicesClient $invoicesrpc;

    public RouterClient $routerrpc;

    public function __construct(string $endpoint, array $options)
    {
        $this->lnrpc = new LightningClient($endpoint, $options);
        $this->invoicesrpc = new InvoicesClient($endpoint, $options);
        $this->routerrpc = new RouterClient($endpoint, $options);
    }
}

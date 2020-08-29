<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lnd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lnd\Connector;

use Daikon\Dbal\Connector\ConnectorInterface;
use Daikon\Dbal\Connector\ProvidesConnector;
use Grpc\ChannelCredentials;

final class LndGrpcConnector implements ConnectorInterface
{
    use ProvidesConnector;

    protected function connect(): LndGrpcClient
    {
        //@todo support node selection
        putenv('GRPC_SSL_CIPHER_SUITES=HIGH+ECDSA');
        $endpoint = sprintf('%s:%s', $this->settings['host'], $this->settings['port']);
        $sslCert = file_get_contents($this->settings['tlscertpath']);
        $macaroon = file_get_contents($this->settings['macaroonpath']);
        $metadataCallaback = function (array $metadata) use ($macaroon): array {
            return array_merge($metadata, ['macaroon' => [bin2hex($macaroon)]]);
        };

        /** @psalm-suppress UndefinedClass */
        return new LndGrpcClient($endpoint, [
            'credentials' => ChannelCredentials::createSsl($sslCert),
            'update_metadata' => $metadataCallaback
        ]);
    }
}

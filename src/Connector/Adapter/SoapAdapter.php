<?php

namespace Webservicesnl\Connector\Adapter;

use Webservicesnl\Soap\Client\SoapClient;

/**
 * Class SoapAdapter.
 *
 * SoapAdapter for a ConnectInterface
 */
class SoapAdapter extends AbstractAdapter implements AdapterInterface
{
    const PROTOCOL_NAME = 'soap';

    /**
     * @var SoapClient
     */
    protected $client;

    /**
     * SoapAdapter constructor.
     *
     * @param SoapClient $client
     */
    public function __construct(SoapClient $client)
    {
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $functionName
     * @param mixed  $args
     *
     * @return mixed
     */
    public function call($functionName, $args)
    {
        if (in_array($functionName, get_class_methods('SoapClient'), false)) {
            return $this->client->{$functionName}($args);
        }

        return $this->client->soapCall($functionName, $args);
    }
}

<?php

namespace WebservicesNl\Protocol\Soap\Client;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use WebservicesNl\Common\Endpoint\Manager;
use WebservicesNl\Common\Exception\ClientException as WsClientException;
use WebservicesNl\Common\Exception\Server\NoServerAvailableException;
use WebservicesNl\Connector\Client\ClientInterface;
use WebservicesNl\Protocol\Soap\Exception\ConverterInterface;
use WebservicesNl\Utils\ArrayUtils;

/**
 * PHP SoapClient with curl for HTTP transport.
 *
 * Extends the native PHP SoapClient. Adds PSR7 Client (Guzzle) for making the calls for better timeout management.
 * Also optional loggerInterface (middleware client) helps with tracing and debugging calls.
 */
class SoapClient extends \SoapClient implements ClientInterface
{
    use LoggerAwareTrait;

    const PROTOCOL = 'soap';

    /**
     * @var ConverterInterface
     */
    private $converter;

    /**
     * Guzzle Client for the SOAP calls.
     *
     * @var HttpClient
     */
    private $httpClient;

    /**
     * @var Manager
     */
    private $manager;

    /**
     * Soap settings.
     *
     * @var SoapSettings;
     */
    private $settings;

    /**
     * Content types for SOAP versions.
     *
     * @var array(string=>string)
     */
    protected static $versionToContentTypeMap = [
        SOAP_1_1 => 'text/xml; charset=utf-8',
        SOAP_1_2 => 'application/soap+xml; charset=utf-8',
    ];

    /**
     * Parsed WSDL functions signatures 'functionA => [arg_a, arg_b]'
     *
     * @var array
     */
    private $signatures;

    /**
     * SoapClient constructor.
     *
     * @param SoapSettings $settings
     * @param Manager      $manager
     * @param HttpClient   $client
     *
     * @throws NoServerAvailableException
     * @throws \InvalidArgumentException
     * @throws \Ddeboer\Transcoder\Exception\ExtensionMissingException
     * @throws \Ddeboer\Transcoder\Exception\UnsupportedEncodingException
     * @throws \SoapFault
     */
    public function __construct(SoapSettings $settings, Manager $manager, HttpClient $client)
    {
        $this->settings = $settings;
        $this->manager = $manager;
        $this->httpClient = $client;

        // throws an Exception when no endpoint is met
        $active = $this->manager->getActiveEndpoint();
        $this->log('Initial endpoint is ' . (string) $active->getUri(), LogLevel::INFO);

        // initiate the native PHP SoapClient for fetching all the WSDL stuff
        $soapSettings = ArrayUtils::toUnderscore($this->settings->toArray());
        parent::__construct((string) $active->getUri()->withQuery('wsdl'), array_filter($soapSettings));
        $this->signatures = $this->parseSignatures();
    }

    /**
     * Parse the function signatures from the configured WSDL
     *
     * @return array
     */
    protected function parseSignatures()
    {
        $signatures = [];
        foreach ($this->__getFunctions() as $functionSignature) {
            $functionMatches = [];
            if (!preg_match('~(?<function>[\w]+)\(.*\)$~', $functionSignature, $functionMatches)) {
                $this->log(
                    sprintf('Could not parse function signature \'%s\'', $functionSignature),
                    LogLevel::WARNING
                );
                continue;
            }

            $paramMatches = [];
            if (!preg_match_all('~(((?<type>[\w]+) )?\$(?<name>[\w]+),?)~', $functionSignature, $paramMatches)) {
                //this can happen simply if there are no parameters in the function signature
                continue;
            }

            //every function will be added with a list of ordered parameter names
            $signatures[$functionMatches['function']] = $paramMatches['name'];
        }

        return $signatures;
    }

    /**
     * @return array
     */
    public function getSignatures()
    {
        return $this->signatures;
    }

    /**
     * Triggers the SOAP request over HTTP.
     * Sent request by cURL instead of native SOAP request.
     *
     * @param string      $request
     * @param string      $location
     * @param string      $action
     * @param int         $version
     * @param string|null $one_way
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws WsClientException
     * @throws NoServerAvailableException
     * @throws \InvalidArgumentException
     * @throws \SoapFault
     *
     * @return string the XML SOAP response
     */
    public function __doRequest($request, $location, $action, $version, $one_way = null)
    {
        $active = $this->manager->getActiveEndpoint();
        try {
            $response = $this->doHttpRequest($request, (string) $active->getUri(), $action);
            $this->manager->updateLastConnected();

            return $response;
        } // when a connection failed try the next server, else return the response
        catch (ConnectException $exception) {
            $active->setStatus('error');
            $this->log('Endpoint is not responding', 'error', ['endpoint' => $active]);

            return $this->__doRequest($request, $location, $action, $version, $one_way);
        }
    }

    /**
     * Proxy function to SoapCall.
     *
     * @param array $args
     *
     * @throws \Exception
     * @throws \SoapFault
     *
     * @return mixed
     */
    public function call(array $args = [])
    {
        $args += ['functionName' => ''];

        $functionName = $args['functionName'];
        unset($args['functionName']);

        return $this->__soapCall($functionName, $args);
    }

    /**
     * Determine the SOAPHeaders for given version.
     *
     * @param string $action
     *
     * @return array
     */
    private function createHeaders($action)
    {
        $headers = ['Content-Type' => self::$versionToContentTypeMap[$this->settings->getSoapVersion()]];
        if ($this->settings->getSoapVersion() === SOAP_1_1) {
            $headers['SOAPAction'] = $action;
        }

        return $headers;
    }

    /**
     * Determines methods.
     * For Soap it's either GET or POST.
     *
     * @param string|null $request
     *
     * @return string
     */
    private static function determineMethod($request)
    {
        return ($request === null || (is_string($request) && trim($request) === '')) ? 'GET' : 'POST';
    }

    /**
     * Http version of doRequest.
     *
     * @param string $requestBody
     * @param string $location
     * @param string $action
     *
     * @throws WsClientException
     * @throws \SoapFault
     * @throws \InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return string
     *
     * @todo move exception handler to middleware, find solution error suppressing
     */
    private function doHttpRequest($requestBody, $location, $action)
    {
        // get soap details for request
        $headers = $this->createHeaders($action);
        $method = self::determineMethod($requestBody);

        // try to fire a request and return it
        try {
            $requestObj = new Request($method, $location, $headers, $requestBody);
            $response = $this->httpClient->send($requestObj);
            // Throw a SoapFault if the response was received, but it can't be read into valid XML
            if ($response->getStatusCode() > 399 && @simplexml_load_string((string) $response->getBody()) === false) {
                throw new \SoapFault('Server', 'Invalid SoapResponse');
            }

            return (string) $response->getBody();
        } catch (ClientException $e) {
            // if a client exception is thrown, the guzzle instance, is configured to throw exceptions
            $code = ($e->getResponse() !== null) ? 'Server' : 'Client.Input';
            throw new \SoapFault('Client', $e->getMessage(), null, $code);
        }
    }

    /**
     * @return ConverterInterface
     */
    public function getConverter()
    {
        return $this->converter;
    }

    /**
     * @param ConverterInterface $converter
     */
    public function setConverter($converter)
    {
        $this->converter = $converter;
    }

    /**
     * @return HttpClient
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }

    /**
     * Return this connector over which a connection is established.
     *
     * @return string
     */
    public function getProtocolName()
    {
        return static::PROTOCOL;
    }

    /**
     * Log message.
     *
     * @param string $message
     * @param int    $level
     * @param array  $context
     */
    public function log($message, $level, array $context = [])
    {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Prepares the soapCall.
     *
     * @param string     $function_name
     * @param array      $arguments
     * @param array      $options
     * @param array      $input_headers
     * @param array|null $output_headers
     *
     * @throws \Exception|\SoapFault
     *
     * @return mixed
     */
    public function __soapCall(
        $function_name,
        $arguments = [],
        $options = [],
        $input_headers = [],
        &$output_headers = null
    ) {
        $this->log('Called:' . $function_name, LogLevel::INFO, ['arguments' => $arguments]);

        //check if the function is in the list of signatures from the wsdl
        if (!array_key_exists($function_name, $this->signatures)) {
            throw new \SoapFault('Client', sprintf('Invalid function %s called', $function_name));
        }

        //if arguments are passed in with an associative array, make sure they are ordered correctly
        //warning! this does not work for doclit endpoints, since the signature becomes one 'parameter' key
        if (ArrayUtils::isAssociativeArray($arguments)) {
            //create a prototype argument list from the function signature
            $prototype = array_fill_keys($this->signatures[$function_name], null);
            //if there are arguments passed that are not part of the signature
            $invalidArguments = array_diff_key($arguments, $prototype);
            if (count($invalidArguments) > 0) {
                $this->log(
                    sprintf(
                        'Invalid argument(s): [%s] passed to function %s',
                        implode(',', array_keys($invalidArguments)),
                        $function_name
                    ),
                    LogLevel::WARNING
                );
            }

            //by merging with the prototype ensure only valid arguments are passed + in the correct order
            $arguments = array_merge($prototype, array_intersect_key($arguments, $prototype));
        }

        try {
            dump($function_name);
            dump($arguments);
            dump($options);
            dump($input_headers);
            return parent::__soapCall($function_name, $arguments, $options, $input_headers, $output_headers);
        } catch (\SoapFault $fault) {
            if ($this->getConverter() !== null) {
                throw $this->getConverter()->convertToException($fault);
            }
            throw $fault;
        }
    }
}

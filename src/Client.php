<?php

namespace garethp\HttpPlayback;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @method ResponseInterface get($uri, array $options = [])
 * @method ResponseInterface head($uri, array $options = [])
 * @method ResponseInterface put($uri, array $options = [])
 * @method ResponseInterface post($uri, array $options = [])
 * @method ResponseInterface patch($uri, array $options = [])
 * @method ResponseInterface delete($uri, array $options = [])
 * @method ResponseInterface getAsync($uri, array $options = [])
 * @method ResponseInterface headAsync($uri, array $options = [])
 * @method ResponseInterface putAsync($uri, array $options = [])
 * @method ResponseInterface postAsync($uri, array $options = [])
 * @method ResponseInterface patchAsync($uri, array $options = [])
 * @method ResponseInterface deleteAsync($uri, array $options = [])
 */
class Client implements ClientInterface
{
    const LIVE = 'live';

    const RECORD = 'record';

    const PLAYBACK = 'playback';

    protected $mode = 'live';

    protected $callList = [];

    protected $recordLocation;

    protected $recordFileName = 'saveState.json';

    private $shutdownRegistered = false;

    private $options;

    /**
     * @var GuzzleClient
     */
    private $client;

    public function __construct($options = [])
    {
        $options = array_replace_recursive(
            ['mode' => null, 'recordLocation' => null, 'recordFileName' => null],
            $options
        );

        if ($options['mode'] !== null) {
            $this->mode = $options['mode'];
            unset($options['mode']);
        }

        if ($options['recordLocation'] !== null) {
            $this->recordLocation = $options['recordLocation'];
            unset($options['recordLocation']);
        }

        if ($options['recordFileName'] !== null) {
            $this->recordFileName = $options['recordFileName'];
            unset($options['recordFileName']);
        }

        $this->setUpCallList();
        $this->client = new GuzzleClient($options);
        $this->options = $options;
        $this->registerShutdown();
    }

    public function __call($method, $args)
    {
        if (count($args) < 1) {
            throw new \InvalidArgumentException('Magic request methods require a URI and optional options array');
        }

        $uri = $args[0];
        $opts = isset($args[1]) ? $args[1] : [];

        return substr($method, -5) === 'Async'
            ? $this->requestAsync(substr($method, 0, -5), $uri, $opts)
            : $this->request($method, $uri, $opts);
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->doSend($request, $options);
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->doSend($request, $options, true);
    }


    /**
     * @param $method
     * @param null $uri
     * @param array $options
     *
     * @return ResponseInterface
     */
    public function request($method, $uri = null, array $options = []): ResponseInterface
    {
        return $this->doRequest($method, $uri, $options);
    }

    /**
     * @param $method
     * @param null $uri
     * @param array $options
     *
     * @return ResponseInterface
     */
    public function requestAsync($method, $uri = null, array $options = []): PromiseInterface
    {
        return $this->doRequest($method, $uri, $options, true);
    }

    public function getConfig(?string $option = null)
    {
        $options = $this->client->getConfig();
        $options = array_merge($options, $this->options);

        return $option === null
            ? $options
            : (isset($options[$option]) ? $options[$option] : null);
    }

    protected function sendWrapper(RequestInterface $request, array $options, $async = false)
    {
        try {
            if ($async) {
                return $this->client->sendAsync($request, $options);
            }

            return $this->client->send($request, $options);
        } catch (\Exception $e) {
            return $e;
        }
    }

    protected function doSend(RequestInterface $request, array $options, $async = false)
    {
        if ($this->mode === self::PLAYBACK) {
            $response = array_shift($this->callList);
        } else {
            $response = $this->sendWrapper($request, $options, $async);
        }

        if ($this->mode === self::RECORD) {
            $this->callList[] = $response;
        }

        if ($response instanceof \Exception) {
            throw $response;
        }

        return $response;
    }

    protected function requestWrapper($method, $uri = null, array $options = [], $async = false)
    {
        try {
            if ($async) {
                return $this->client->requestAsync($method, $uri, $options);
            }

            return $this->client->request($method, $uri, $options);
        } catch (\Exception $e) {
            return $e;
        }
    }

    protected function doRequest($method, $uri = null, array $options = [], $async = false)
    {
        if ($this->mode === self::PLAYBACK) {
            $response = array_shift($this->callList);
        } else {
            $response = $this->requestWrapper($method, $uri, $options, $async);
        }

        if ($this->mode === self::RECORD) {
            $this->callList[] = $response;
        }

        if ($response instanceof \Exception) {
            throw $response;
        }

        return $response;
    }

    protected function setUpCallList()
    {
        if ($this->mode !== self::PLAYBACK) {
            return;
        }

        $this->callList = $this->arrayToResponses($this->getRecordings());
    }

    protected function getRecordFilePath()
    {
        $path = $this->recordLocation . DIRECTORY_SEPARATOR . $this->recordFileName;
        $path = str_replace("\\", DIRECTORY_SEPARATOR, $path);

        return $path;
    }

    protected function getRecordings()
    {
        $saveLocation = $this->getRecordFilePath();
        return json_decode(file_get_contents($saveLocation), true);
    }

    public function changeRecordLocationAndFile($recordLocation, $recordFileName)
    {
        if ($this->mode == self::RECORD) {
            $this->endRecord();
            $this->mode = self::RECORD;
        }

        $this->recordLocation = $recordLocation;
        $this->recordFileName = $recordFileName;

        $this->setUpCallList();
    }

    public function endRecord()
    {
        if ($this->mode != self::RECORD) {
            return;
        }

        $saveList = $this->responsesToArray($this->callList);
        $this->mode = self::LIVE;

        $saveLocation = $this->getRecordFilePath();
        $folder = pathinfo($saveLocation)['dirname'];
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        file_put_contents($saveLocation, json_encode($saveList));
        $this->callList = [];
    }

    protected function registerShutdown()
    {
        if (!$this->shutdownRegistered) {
            register_shutdown_function(array($this, 'endRecord'));
            $this->shutdownRegistered = true;
        }
    }

    /**
     * @param $responses
     * @return array
     */
    protected function responsesToArray($responses)
    {
        $array = [];
        foreach ($responses as $response) {
            /** @var Response $response */

            if ($response instanceof \Exception) {
                $save = [
                    'error' => true,
                    'errorClass' => get_class($response),
                    'errorMessage' => $response->getMessage(),
                    'request' => [
                        'method' => $response->getRequest()->getMethod(),
                        'uri' => $response->getRequest()->getUri()->__toString(),
                        'headers' => $response->getRequest()->getHeaders(),
                        'body' => $response->getRequest()->getBody()->__toString()
                    ],
                    'response' => [
                        'statusCode' => $response->hasResponse() ? $response->getResponse()->getStatusCode() : 500,
                        'headers' => $response->hasResponse() ? $response->getResponse()->getHeaders() : [],
                        'body' => $response->hasResponse() ? $response->getResponse()->getBody()->__toString() : ''
                    ]
                ];
            } else {
                $save = [
                    'error' => false,
                    'statusCode' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $response->getBody()->__toString()
                ];
            }
            $array[] = $save;
        }

        return $array;
    }

    /**
     * @param $items
     * @return Response[]
     */
    protected function arrayToResponses($items)
    {
        $mockedResponses = [];
        foreach ($items as $item) {
            if (!$item['error']) {
                $mockedResponses[] = new Response($item['statusCode'], $item['headers'], $item['body']);
            } else {
                $errorClass = $item['errorClass'];
                $request = new Request(
                    $item['request']['method'],
                    $item['request']['uri'],
                    $item['request']['headers'],
                    $item['request']['body']
                );
                $response = new Response(
                    $item['response']['statusCode'],
                    $item['response']['headers'],
                    $item['response']['body']
                );

                if (
                    is_a($errorClass, BadResponseException::class, true)
                    ||
                    is_subclass_of($errorClass, BadResponseException::class, true)
                ) {
                    $mockedResponses[] = new $errorClass($item['errorMessage'], $request, $response);
                } else {
                    $mockedResponses[] = new $errorClass($item['errorMessage'], $request);
                }
            }
        }

        return $mockedResponses;
    }
}

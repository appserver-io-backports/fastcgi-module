<?php

/**
 * \TechDivision\FastCgiModule\FastCgiModule
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @category  Library
 * @package   TechDivision_FastCgiModule
 * @author    Tim Wagner <tw@techdivision.com>
 * @author    Bernhard Wick <b.wick@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_FastCgiModule
 */

namespace TechDivision\FastCgiModule;

use TechDivision\Connection\ConnectionRequestInterface;
use TechDivision\Connection\ConnectionResponseInterface;
use TechDivision\Http\HttpProtocol;
use TechDivision\Http\HttpResponseStates;
use TechDivision\Http\HttpRequestInterface;
use TechDivision\Http\HttpResponseInterface;
use TechDivision\Server\Dictionaries\ServerVars;
use TechDivision\Server\Dictionaries\ModuleVars;
use TechDivision\Server\Dictionaries\ModuleHooks;
use TechDivision\Server\Interfaces\ModuleInterface;
use TechDivision\Server\Exceptions\ModuleException;
use TechDivision\Server\Interfaces\RequestContextInterface;
use TechDivision\Server\Interfaces\ServerContextInterface;
use Crunch\FastCGI\ConnectionException;
use Crunch\FastCGI\Client as FastCgiClient;

/**
 * This module allows us to let requests be handled by Fast-CGI client
 * that has been configured in the web servers configuration.
 *
 * @category  Library
 * @package   TechDivision_FastCgiModule
 * @author    Tim Wagner <tw@techdivision.com>
 * @author    Bernhard Wick <b.wick@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_FastCgiModule
 */
class FastCgiModule implements ModuleInterface
{

    /**
     * The default IP address for the Fast-CGI connection.
     *
     * @var string
     */
    const DEFAULT_FAST_CGI_IP = '127.0.0.1';

    /**
     * The default port for the Fast-CGI connection.
     *
     * @var integer
     */
    const DEFAULT_FAST_CGI_PORT = 9010;

    /**
     * Defines the module name.
     *
     * @var string
     */
    const MODULE_NAME = 'fastcgi';

    /**
     * Implements module logic for given hook, in this case passing the Fast-CGI request
     * through to the configured Fast-CGI server.
     *
     * @param \TechDivision\Connection\ConnectionRequestInterface     $request        A request object
     * @param \TechDivision\Connection\ConnectionResponseInterface    $response       A response object
     * @param \TechDivision\Server\Interfaces\RequestContextInterface $requestContext A requests context instance
     * @param int                                                     $hook           The current hook to process logic for
     *
     * @return bool
     * @throws \TechDivision\Server\Exceptions\ModuleException
     */
    public function process(
        ConnectionRequestInterface $request,
        ConnectionResponseInterface $response,
        RequestContextInterface $requestContext,
        $hook
    ) {

        try {

            // in php an interface is, by definition, a fixed contract. It is immutable.
            // so we have to declair the right ones afterwards...
            /** @var $request \TechDivision\Http\HttpRequestInterface */
            /** @var $request \TechDivision\Http\HttpResponseInterface */

            // if false hook is comming do nothing
            if (ModuleHooks::REQUEST_POST !== $hook) {
                return;
            }

            // check if server handler sais php modules should react on this request as file handler
            if ($requestContext->getServerVar(ServerVars::SERVER_HANDLER) !== self::MODULE_NAME) {
                return;
            }

            // check if file does not exist
            if (!$requestContext->hasServerVar(ServerVars::SCRIPT_FILENAME)) {
                $response->setStatusCode(404);
                throw new ModuleException(null, 404);
            }

            // create a new the FastCGI client/connection
            $fastCgiConnection = $this->getFastCgiClient($requestContext)->connect();

            // prepare the Fast-CGI environment variables
            $environment = $this->prepareEnvironment($request, $requestContext);

            // rewind the body stream
            $bodyStream = $request->getBodyStream();
            rewind($bodyStream);

            // initialize a new FastCGI request instance
            $fastCgiRequest = $fastCgiConnection->newRequest($environment, $bodyStream);

            // process the request
            $rawResponse = $fastCgiConnection->request($fastCgiRequest);

            // format the raw response
            $fastCgiResponse = $this->formatResponse($rawResponse->content);

            // set the Fast-CGI response value in the WebServer response
            $response->setStatusCode($fastCgiResponse['statusCode']);
            $response->appendBodyStream($fastCgiResponse['body']);

            // set the headers found in the Fast-CGI response
            if (array_key_exists('headers', $fastCgiResponse)) {
                foreach ($fastCgiResponse['headers'] as $headerName => $headerValue) {
                    // if found an array, e. g. for the Set-Cookie header, we add each value
                    if (is_array($headerValue)) {
                        foreach ($headerValue as $value) {
                            $response->addHeader($headerName, $value, true);
                        }
                    } else {
                        $response->addHeader($headerName, $headerValue);
                    }
                }
            }

            // add the X-Powered-By header
            $response->addHeader(HttpProtocol::HEADER_X_POWERED_BY, __CLASS__);

            // set response state to be dispatched after this without calling other modules process
            $response->setState(HttpResponseStates::DISPATCH);

        } catch (\Exception $e) { // catch all exceptions
            throw new ModuleException($e);
        }
    }

    /**
     * Creates and returns a new FastCGI client instance.
     *
     * @param \TechDivision\Server\Interfaces\RequestContextInterface $requestContext A requests context instance
     *
     * @return \Crunch\FastCGI\Connection The FastCGI connection instance
     */
    protected function getFastCgiClient(RequestContextInterface $requestContext)
    {

        // initialize default host/port
        $host = FastCgiModule::DEFAULT_FAST_CGI_IP;
        $port = FastCgiModule::DEFAULT_FAST_CGI_PORT;

        // set the connection data to be used for the Fast-CGI connection
        $fileHandlerVariables = array();

        // check if we've configured module variables
        if ($requestContext->hasModuleVar(ModuleVars::VOLATILE_FILE_HANDLER_VARIABLES)) {

            // load the volatile file handler variables and set connection data
            $fileHandlerVariables = $requestContext->getModuleVar(ModuleVars::VOLATILE_FILE_HANDLER_VARIABLES);
            if (isset($fileHandlerVariables['host'])) {
                $host = $fileHandlerVariables['host'];
            }
            if (isset($fileHandlerVariables['port'])) {
                $port = $fileHandlerVariables['port'];
            }

        }

        // create and return the FastCGI client
        return new FastCgiClient($host, $port);
    }

    /**
     * Prepares and returns the array with the FastCGI environment varaibles.
     *
     * @param \TechDivision\Connection\ConnectionRequestInterface     $request        A request object
     * @param \TechDivision\Server\Interfaces\RequestContextInterface $requestContext A requests context instance
     *
     * @return array The array with the prepared FastCGI environment variables
     */
    protected function prepareEnvironment(ConnectionRequestInterface $request, RequestContextInterface $requestContext)
    {

        // prepare the Fast-CGI environment variables
        $environment = array(
            ServerVars::GATEWAY_INTERFACE => 'FastCGI/1.0',
            ServerVars::REQUEST_METHOD    => $requestContext->getServerVar(ServerVars::REQUEST_METHOD),
            ServerVars::SCRIPT_FILENAME   => $requestContext->getServerVar(ServerVars::SCRIPT_FILENAME),
            ServerVars::QUERY_STRING      => $requestContext->getServerVar(ServerVars::QUERY_STRING),
            ServerVars::SCRIPT_NAME       => $requestContext->getServerVar(ServerVars::SCRIPT_NAME),
            ServerVars::REQUEST_URI       => $requestContext->getServerVar(ServerVars::REQUEST_URI),
            ServerVars::DOCUMENT_ROOT     => $requestContext->getServerVar(ServerVars::DOCUMENT_ROOT),
            ServerVars::SERVER_PROTOCOL   => $requestContext->getServerVar(ServerVars::SERVER_PROTOCOL),
            ServerVars::HTTPS             => $requestContext->getServerVar(ServerVars::HTTPS),
            ServerVars::SERVER_SOFTWARE   => $requestContext->getServerVar(ServerVars::SERVER_SOFTWARE),
            ServerVars::REMOTE_ADDR       => $requestContext->getServerVar(ServerVars::REMOTE_ADDR),
            ServerVars::REMOTE_PORT       => $requestContext->getServerVar(ServerVars::REMOTE_PORT),
            ServerVars::SERVER_ADDR       => $requestContext->getServerVar(ServerVars::SERVER_ADDR),
            ServerVars::SERVER_PORT       => $requestContext->getServerVar(ServerVars::SERVER_PORT),
            ServerVars::SERVER_NAME       => $requestContext->getServerVar(ServerVars::SERVER_NAME)
        );

        // if we found a redirect status, add it to the environment variables
        if ($requestContext->hasServerVar(ServerVars::REDIRECT_STATUS)) {
            $environment[ServerVars::REDIRECT_STATUS] = $requestContext->getServerVar(ServerVars::REDIRECT_STATUS);
        }

        // if we found a Content-Type header, add it to the environment variables
        if ($request->hasHeader(HttpProtocol::HEADER_CONTENT_TYPE)) {
            $environment['CONTENT_TYPE'] = $request->getHeader(HttpProtocol::HEADER_CONTENT_TYPE);
        }

        // if we found a Content-Length header, add it to the environment variables
        if ($request->hasHeader(HttpProtocol::HEADER_CONTENT_LENGTH)) {
            $environment['CONTENT_LENGTH'] = $request->getHeader(HttpProtocol::HEADER_CONTENT_LENGTH);
        }

        // create an HTTP_ environment variable for each header
        foreach ($request->getHeaders() as $key => $value) {
            $environment['HTTP_' . str_replace('-', '_', strtoupper($key))] = $value;
        }

        // create an HTTP_ environment variable for each server environment variable
        foreach ($requestContext->getEnvVars() as $key => $value) {
            $environment[$key] = $value;
        }

        // return the prepared environment
        return $environment;
    }

    /**
     * Format the response into an array with separate statusCode, headers, body, and error output.
     *
     * @param string $stdout The plain, unformatted response.
     *
     * @return array An array containing the headers and body content
     * @throws \TechDivision\Server\Exceptions\ModuleException
     */
    protected function formatResponse($stdout)
    {

        // split the header from the body.  Split on \n\n.
        $doubleCr = strpos($stdout, "\r\n\r\n");
        $rawHeader = substr($stdout, 0, $doubleCr);
        $rawBody = substr($stdout, $doubleCr, strlen($stdout));

        // format the header.
        $header = array();
        $headerLines = explode("\n", $rawHeader);

        // initialize the status code and the status header
        $code = '200';
        $headerStatus = '200 OK';

        // iterate over the headers found in the response.
        foreach ($headerLines as $line) {

            // initialize the array with the matches
            $matches = array();

            // extract the header data.
            if (preg_match('/([\w-]+):\s*(.*)$/', $line, $matches)) {

                // initialize header name/value.
                $headerName = strtolower($matches[1]);
                $headerValue = trim($matches[2]);

                // if we found an status header (will only be available if not have a 200).
                if ($headerName == 'status') {

                    // initialize the status header and the code.
                    $headerStatus = $headerValue;
                    $code = $headerValue;
                    if (false !== ($pos = strpos($code, ' '))) {
                        $code = substr($code, 0, $pos);
                    }
                }

                // we need to know if this header is already available
                if (array_key_exists($headerName, $header)) {

                    // check if the value is an array already
                    if (is_array($header[$headerName])) {
                        // Simply append the next header value
                        $header[$headerName][] = $headerValue;
                    } else {
                        // convert the existing value into an array and append the new header value
                        $header[$headerName] = array($header[$headerName], $headerValue);
                    }

                } else {
                    $header[$headerName] = $headerValue;
                }
            }
        }

        // set the status header finally
        $header['status'] = $headerStatus;

        // check the FastCGI response code
        if (false === ctype_digit($code)) {
            throw new ModuleException("Unrecognizable status code returned from fastcgi: $code");
        }

        // return the array with the response
        return array(
            'statusCode' => (int) $code,
            'headers'    => $header,
            'body'       => trim($rawBody)
        );
    }

    /**
     * Returnss an array of module names which should be executed first.
     *
     * @return array The array of module names
     */
    public function getDependencies()
    {
        return array();
    }

    /**
     * Returns the module name
     *
     * @return string The module name
     */
    public function getModuleName()
    {
        return self::MODULE_NAME;
    }

    /**
     * Initiates the module.
     *
     * @param \TechDivision\Server\Interfaces\ServerContextInterface $serverContext The servers context instance
     *
     * @return bool
     * @throws \TechDivision\Server\Exceptions\ModuleException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function init(ServerContextInterface $serverContext)
    {
        // nothing yet
    }

    /**
     * Prepares the module for upcoming request in specific context.
     *
     * @return bool
     * @throws \TechDivision\Server\Exceptions\ModuleException
     */
    public function prepare()
    {
        // nothing yet
    }
}

<?php

/**
 * This file is part of the php-epp2 library.
 *
 * (c) Gunter Grodotzki <gunter@afri.cc>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * Modified for the PANDI WHMCS registrar module, see lib/EPP/README.md (last change 2026-11-16).
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs\EPP;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Login as LoginCommand;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Response as ResponseFrame;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\ResponseFactory;
use Exception;

/**
 * An abstract client client for the Extensible Provisioning Protocol (EPP)
 *
 * Extend this class in your custom EPP Client
 *
 * @see http://tools.ietf.org/html/rfc5734
 * @see ClientInterface
 *
 * As this class is abstract and relies on subclass implementation details it's untestable
 * @codeCoverageIgnore
 */
abstract class AbstractClient implements ClientInterface
{
    protected $host;
    protected $port;
    protected $username;
    protected $password;
    protected $lang;
    protected $version;
    protected $services;
    protected $serviceExtensions;
    protected $ssl;
    protected $local_cert;
    protected $ca_cert;
    protected $pk_cert;
    protected $passphrase;
    protected $verify_peer;
    protected $debug;
    protected $connect_timeout;
    protected $timeout;
    protected $objectSpec;

    /**
     * objURIs / extURIs announced by the server greeting (null until a greeting was parsed)
     *
     * @var array|null
     */
    protected $greetingServices;
    protected $greetingExtensions;

    /**
     * Optional callable(string $request, string $response) invoked for every frame exchange
     *
     * @var callable|null
     */
    protected $frameObserver;

    /**
     * {@inheritdoc}
     *
     * @see \WHMCS\Module\Registrar\Pandiwhmcs\EPP\ClientInterface::connect()
     */
    abstract public function connect($newPassword = false);

    abstract public function close();

    abstract protected function log($message);

    /**
     * Send frame to EPP server
     *
     * @param FrameInterface $frame Frame to send
     *
     * @throws Exception on send error
     */
    abstract public function sendFrame(FrameInterface $frame);

    /**
     * Get response frame from EPP server (use after sendFrame)
     *
     * @throws Exception on frame receive error
     *
     * @return string raw XML of EPP Frame
     */
    abstract public function getFrame();

    public function request(FrameInterface $frame)
    {
        if ($frame instanceof TransactionAwareInterface) {
            $frame->setClientTransactionId(
                $this->generateClientTransactionId()
                );
        }

        $this->sendFrame($frame);

        $return = $this->getFrame();

        if (is_callable($this->frameObserver)) {
            call_user_func($this->frameObserver, (string) $frame, (string) $return);
        }

        return ResponseFactory::build($return, $this->objectSpec);
    }

    /**
     * Register a callable(string $request, string $response) that sees every frame exchange
     *
     * @param callable|null $observer
     */
    public function setFrameObserver($observer)
    {
        $this->frameObserver = $observer;
    }

    /**
     * objURIs announced by the server greeting, null if no greeting was parsed
     *
     * @return array|null
     */
    public function getGreetingServices()
    {
        return $this->greetingServices;
    }

    /**
     * extURIs announced by the server greeting, null if no greeting was parsed
     *
     * @return array|null
     */
    public function getGreetingExtensions()
    {
        return $this->greetingExtensions;
    }

    /**
     * extURIs that were (or will be) sent in the <login> svcExtension
     *
     * @return array
     */
    public function getServiceExtensions()
    {
        return is_array($this->serviceExtensions) ? $this->serviceExtensions : [];
    }

    /**
     * Parse the server greeting and restrict the login services to what the
     * server announced. RFC 5730 section 2.9.1.1: the client MUST NOT request
     * a service or extension that was not announced in the greeting.
     *
     * @param string $greeting raw greeting XML
     */
    protected function negotiateServices($greeting)
    {
        if (!is_string($greeting) || $greeting === '') {
            return;
        }

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($greeting)) {
            return;
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('epp', $this->objectSpec->xmlns('epp'));

        $objURIs = [];
        foreach ($xpath->query('//epp:epp/epp:greeting/epp:svcMenu/epp:objURI') as $node) {
            $objURIs[] = trim($node->textContent);
        }
        $extURIs = [];
        foreach ($xpath->query('//epp:epp/epp:greeting/epp:svcMenu/epp:svcExtension/epp:extURI') as $node) {
            $extURIs[] = trim($node->textContent);
        }

        if (empty($objURIs)) {
            return;
        }

        $this->greetingServices = $objURIs;
        $this->greetingExtensions = $extURIs;

        if (is_array($this->services)) {
            $this->services = array_values(array_intersect($this->services, $objURIs));
        }
        if (is_array($this->serviceExtensions)) {
            $this->serviceExtensions = array_values(array_intersect($this->serviceExtensions, $extURIs));
        }
    }

    public function __construct(array $config, ?ObjectSpec $objectSpec = null)
    {
        if (!empty($config['debug']) && is_bool($config['debug'])) {
            $this->debug = $config['debug'];
        } else {
            $this->debug = false;
        }

        if (is_null($objectSpec)) {
            $objectSpec = new ObjectSpec();
        }

        $this->objectSpec = $objectSpec;

        $this->prepareConnectionOptions($config);
        $this->prepareCredentials($config);
        $this->prepareSSLOptions($config);
        $this->prepareEPPServices($config);
        $this->prepareEPPVersionLang($config);
    }

    /**
     * Get client's ObjectSpec
     *
     * @return ObjectSpec
     */
    public function getObjectSpec()
    {
        return $this->objectSpec;
    }

    /**
     * Set client's ObjectSpec
     *
     * @param ObjectSpec $newObjectSpec
     */
    public function setObjectSpec(ObjectSpec $newObjectSpec)
    {
        $this->objectSpec = $newObjectSpec;
    }

    /**
     * Get config value from config array if set (default otherwise)
     *
     * Basically, a simple null coallesce operator (since we need to support PHP 5.5)
     *
     * @param array $config
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    protected function getConfigDefault(array $config, string $key, $default = null)
    {
        if (!empty($config[$key])) {
            return $config[$key];
        }

        return $default;
    }

    /**
     * Get config value from config array if set (default otherwise)
     *
     * special version for bool values
     *
     * @param array $config
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     *
     * @see AbstractClient::getConfigDefault
     */
    protected function getConfigDefaultBool(array $config, string $key, $default = null)
    {
        if (isset($config[$key]) && is_bool($config[$key])) {
            return $config[$key];
        }

        return $default;
    }

    /**
     * Get config value from config array if set (default otherwise)
     *
     * special version for aray values
     *
     * @param array $config
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     *
     * @see AbstractClient::getConfigDefault
     */
    protected function getConfigDefaultArray(array $config, string $key, $default = null)
    {
        if (!empty($config[$key]) && is_array($config[$key])) {
            return $config[$key];
        }

        return $default;
    }

    /**
     * Get config value from config array if set (default otherwise)
     *
     * special version for files
     *
     * @param array $config
     * @param string $key
     * @param mixed $default
     *
     * @throws Exception in case file is specified but not readable
     *
     * @return mixed
     *
     * @see AbstractClient::getConfigDefault
     */
    protected function getConfigDefaultReadableFile(array $config, string $key, $default = null)
    {
        if (!empty($config[$key])) {
            $return = (string) $config[$key];

            if (!is_readable($return)) {
                throw new \Exception(sprintf('unable to read %s: %s', $key, $return));
            }

            return $return;
        }

        return $default;
    }

    protected function prepareConnectionOptions(array $config)
    {
        $this->host = $this->getConfigDefault($config, 'host');
        $this->port = $this->getConfigDefault($config, 'port', false);
        $this->connect_timeout = (int) $this->getConfigDefault($config, 'connect_timeout', 16);
        $this->timeout = (int) $this->getConfigDefault($config, 'timeout', 32);
    }

    protected function prepareCredentials(array $config)
    {
        $this->username = $this->getConfigDefault($config, 'username');
        $this->password = $this->getConfigDefault($config, 'password');
    }

    protected function prepareSSLOptions(array $config)
    {
        $this->ssl = $this->getConfigDefaultBool($config, 'ssl', false);

        $this->local_cert = $this->getConfigDefaultReadableFile($config, 'local_cert');
        $this->ca_cert = $this->getConfigDefaultReadableFile($config, 'ca_cert');
        $this->pk_cert = $this->getConfigDefaultReadableFile($config, 'pk_cert');

        $this->passphrase = $this->getConfigDefault($config, 'passphrase');

        // RFC 5734 section 9: the server certificate must be verified
        $this->verify_peer = $this->getConfigDefaultBool($config, 'verify_peer', true);
    }

    protected function prepareEPPServices(array $config)
    {
        $this->services = $this->getConfigDefaultArray($config, 'services');
        $this->serviceExtensions = $this->getConfigDefaultArray($config, 'serviceExtensions');
    }

    protected function prepareEPPVersionLang(array $config)
    {
        $this->lang = $this->getConfigDefault($config, 'lang', 'en');
        $this->version = $this->getConfigDefault($config, 'version', '1.0');
    }

    protected function generateClientTransactionId()
    {
        // clTRID must be unique per client (RFC 5730 section 2.5); uniqid() alone
        // can collide between concurrent PHP processes, so add random bytes
        return substr($this->username . '-' . uniqid() . '-' . bin2hex(random_bytes(4)), 0, 64);
    }

    /**
     * Generate and send login frame
     *
     * @param bool|string $newPassword New password to set on login, false if not changing password
     *
     * @throws \Exception On unsuccessful login
     *
     * @return \WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Response Login response
     */
    protected function login($newPassword = false)
    {
        // send login command
        $login = new LoginCommand($this->objectSpec);
        $login->setClientId($this->username);
        $login->setPassword($this->password);
        if ($newPassword) {
            $login->setNewPassword($newPassword);
        }
        $login->setVersion($this->version);
        $login->setLanguage($this->lang);

        if (!empty($this->services) && is_array($this->services)) {
            foreach ($this->services as $urn) {
                $login->addService($urn);
            }

            if (!empty($this->serviceExtensions) && is_array($this->serviceExtensions)) {
                foreach ($this->serviceExtensions as $extension) {
                    $login->addServiceExtension($extension);
                }
            }
        }

        $response = $this->request($login);
        unset($login);

        // check if login was successful
        if (!($response instanceof ResponseFrame)) {
            throw new \Exception('there was a problem logging onto the EPP server');
        } elseif ($response->code() !== 1000) {
            throw new \Exception($response->message(), $response->code());
        }

        return $response;
    }
}

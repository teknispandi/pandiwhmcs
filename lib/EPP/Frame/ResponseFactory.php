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

namespace WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Response\MessageQueue;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\ObjectSpec;
use DOMDocument;
use DOMXPath;

class ResponseFactory
{
    /**
     * Build response frame
     *
     * @param string $buffer
     * @param ObjectSpec $objectSpec
     *
     * @return string|\WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Response\MessageQueue|\WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Response
     */
    public static function build($buffer, ?ObjectSpec $objectSpec = null)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $xml->registerNodeClass('\DOMElement', '\WHMCS\Module\Registrar\Pandiwhmcs\EPP\DOM\DOMElement');
        $xml->loadXML($buffer);

        $xpath = new DOMXPath($xml);

        if (is_null($objectSpec)) {
            $objectSpec = new ObjectSpec();
        }

        foreach ($objectSpec->specs as $prefix => $spec) {
            $xpath->registerNamespace($prefix, $spec['xmlns']);
        }

        $nodes = $xml->getElementsByTagNameNS($objectSpec->xmlns('epp'), 'epp');
        if ($nodes === null || $nodes->length !== 1) {
            return $buffer;
        }

        if (!$nodes->item(0)->hasChildNodes()) {
            return $buffer;
        }

        foreach ($nodes->item(0)->childNodes as $node) {
            // ignore non-nodes
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            // ok now we can create an object according to the response-frame
            $frame_type = strtolower($node->localName);
            if ($frame_type === 'response') {
                // check if it is a message queue @todo this should go into the response object!
                $results = $xpath->query('//epp:epp/epp:response/epp:msgQ');
                if ($results->length > 0) {
                    return new MessageQueue($xml, $objectSpec);
                }

                return new Response($xml, $objectSpec);
            }
        }

        return $buffer;
    }
}

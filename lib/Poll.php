<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Poll as PollCommand;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Response\MessageQueue;

/**
 * Service message queue (RFC 5730 section 2.9.2.3).
 *
 * Every message is recorded in the WHMCS activity log and acknowledged.
 * Outbound transfers that completed mark the domain "Transferred Away";
 * inbound transfers are left to TransferSync, which also sets the new expiry
 * date and sends the WHMCS notifications.
 */
class Poll
{
    /**
     * @param Connection $connection
     * @param int        $max messages to process in one call
     *
     * @return int number of acknowledged messages
     */
    public static function process(Connection $connection, $max = 10)
    {
        $processed = 0;
        for ($i = 0; $i < $max; ++$i) {
            $request = new PollCommand();
            $request->request();
            $response = $connection->request($request);
            $result = $response->results()[0];
            $code = (int) $result->code();

            if ($code === 1300) {
                break;
            }
            if ($code !== 1301) {
                throw new EppException((string) $result->message(), $code);
            }
            if (!$response instanceof MessageQueue || !is_string($response->queueId()) || $response->queueId() === '') {
                break;
            }

            self::handle($response, $connection->registrarId());

            $ack = new PollCommand();
            $ack->ack($response->queueId());
            $connection->send($ack);
            ++$processed;
        }

        return $processed;
    }

    /**
     * process() for callers that must not fail because of the queue (domain sync).
     *
     * @param Connection $connection
     */
    public static function drain(Connection $connection)
    {
        try {
            self::process($connection);
        } catch (\Exception $e) {
            Logger::error('poll', $e->getMessage());
        }
    }

    /**
     * @param MessageQueue $message
     * @param string       $registrarId
     */
    private static function handle(MessageQueue $message, $registrarId)
    {
        $text = trim((string) $message->queueMessage());
        $data = $message->data();

        if (!empty($data['trnData']) && is_array($data['trnData'])) {
            $transfer = $data['trnData'];
            $name = Helper::hostName(isset($transfer['name']) && is_string($transfer['name']) ? $transfer['name'] : '');
            $status = isset($transfer['trStatus']) ? (string) $transfer['trStatus'] : '';
            $requester = isset($transfer['reID']) ? (string) $transfer['reID'] : '';
            $actor = isset($transfer['acID']) ? (string) $transfer['acID'] : '';
            $outbound = strcasecmp($actor, $registrarId) === 0 && strcasecmp($requester, $registrarId) !== 0;

            if ($name !== '' && $outbound && in_array($status, ['clientApproved', 'serverApproved'], true)) {
                Store::setDomainStatus($name, 'Transferred Away');
            }

            Module::activity(sprintf(
                '%s transfer of %s: %s (requested by %s)%s',
                $outbound ? 'Outbound' : 'Inbound',
                $name,
                $status,
                $requester,
                $outbound && $status === 'pending' ? ' - approve or reject it before the registry auto-approves' : ''
            ));

            return;
        }

        $name = '';
        if (!empty($data['panData']['name']) && is_string($data['panData']['name'])) {
            $name = $data['panData']['name'];
        } elseif (!empty($data['infData']['name']) && is_string($data['infData']['name'])) {
            $name = $data['infData']['name'];
        }

        Module::activity(sprintf('Registry message%s: %s', $name !== '' ? ' for ' . $name : '', $text !== '' ? $text : '(no text)'));
    }
}

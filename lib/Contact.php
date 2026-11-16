<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Create\Contact as CreateContact;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Info\Contact as InfoContact;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Update\Contact as UpdateContact;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Update\Domain as UpdateDomain;

/**
 * Contact object operations, RFC 5733.
 *
 * A contact is handled as an array with the keys
 * name, org, street (string[]), city, sp, pc, cc, voice, fax, email.
 * The postal info is sent both as "loc" (UTF-8) and "int" (ASCII transliteration),
 * RFC 5733 section 2.3.
 */
class Contact
{
    /** WHMCS contact block label => EPP role, in display order */
    const ROLES = ['Registrant' => 'registrant', 'Technical' => 'tech', 'Admin' => 'admin', 'Billing' => 'billing'];

    const FIELDS = ['name', 'org', 'street', 'city', 'sp', 'pc', 'cc', 'voice', 'fax', 'email'];

    /**
     * Contact from the WHMCS order/registration parameters.
     *
     * @param array  $params
     * @param string $prefix '' for the registrant, 'admin' for the admin contact fields
     *
     * @return array
     */
    public static function fromParams(array $params, $prefix = '')
    {
        $get = function ($key) use ($params, $prefix) {
            return isset($params[$prefix . $key]) ? trim((string) $params[$prefix . $key]) : '';
        };

        $name = $prefix === '' ? $get('fullname') : '';
        if ($name === '') {
            $name = trim($get('firstname') . ' ' . $get('lastname'));
        }

        $country = $prefix === '' ? $get('countrycode') : '';
        if ($country === '') {
            $country = $get('country');
        }

        $phonecc = $prefix === '' ? $get('phonecc') : '';
        $phone = $get('fullphonenumber') !== '' ? $get('fullphonenumber') : $get('phonenumber');

        return [
            'name' => $name,
            'org' => $get('companyname'),
            'street' => array_values(array_filter([$get('address1'), $get('address2')], 'strlen')),
            'city' => $get('city'),
            'sp' => $get('state'),
            'pc' => $get('postcode'),
            'cc' => strtoupper($country),
            'voice' => Helper::phone($phone, $phonecc !== '' ? $phonecc : (isset($params['phonecc']) ? $params['phonecc'] : null)),
            'fax' => '',
            'email' => $get('email'),
        ];
    }

    /**
     * Contact from a WHMCS "contact details" form block (SaveContactDetails).
     *
     * @param array       $fields
     * @param string|null $defaultCc calling code used for national phone numbers
     *
     * @return array
     */
    public static function fromWhmcsForm(array $fields, $defaultCc = null)
    {
        $get = function ($key) use ($fields) {
            return isset($fields[$key]) ? trim((string) $fields[$key]) : '';
        };

        $name = trim($get('First Name') . ' ' . $get('Last Name'));
        if ($name === '') {
            $name = $get('Full Name');
        }

        return [
            'name' => $name,
            'org' => $get('Company Name'),
            'street' => array_values(array_filter([$get('Address 1'), $get('Address 2'), $get('Address 3')], 'strlen')),
            'city' => $get('City'),
            'sp' => $get('State'),
            'pc' => $get('Postcode'),
            'cc' => strtoupper($get('Country')),
            'voice' => Helper::phone($get('Phone Number'), $defaultCc),
            'fax' => Helper::phone($get('Fax Number'), $defaultCc),
            'email' => $get('Email Address'),
        ];
    }

    /**
     * WHMCS "contact details" block for a contact. Uses the standard WHMCS field
     * labels so "use existing contact" in the client area fills them in.
     *
     * @param array $contact
     *
     * @return array
     */
    public static function toWhmcsForm(array $contact)
    {
        list($first, $last) = Helper::splitName($contact['name']);

        return [
            'First Name' => $first,
            'Last Name' => $last,
            'Company Name' => $contact['org'],
            'Email Address' => $contact['email'],
            'Address 1' => isset($contact['street'][0]) ? $contact['street'][0] : '',
            'Address 2' => isset($contact['street'][1]) ? $contact['street'][1] : '',
            'City' => $contact['city'],
            'State' => $contact['sp'],
            'Postcode' => $contact['pc'],
            'Country' => $contact['cc'],
            'Phone Number' => $contact['voice'],
            'Fax Number' => $contact['fax'],
        ];
    }

    /**
     * Check the RFC 5733 constraints the registry would otherwise reject with a 2xxx.
     *
     * @param array $contact
     *
     * @throws \InvalidArgumentException
     */
    public static function validate(array $contact)
    {
        if ($contact['name'] === '') {
            throw new \InvalidArgumentException('Contact name is required');
        }
        if ($contact['city'] === '') {
            throw new \InvalidArgumentException('City is required');
        }
        if (!preg_match('/^[A-Z]{2}$/', $contact['cc'])) {
            throw new \InvalidArgumentException('Country must be a two-letter ISO 3166-1 code');
        }
        if ($contact['email'] === '') {
            throw new \InvalidArgumentException('Email address is required');
        }
        if (count($contact['street']) > 3) {
            throw new \InvalidArgumentException('At most three street lines are allowed');
        }
        if (strlen($contact['pc']) > 16) {
            throw new \InvalidArgumentException('Postcode is longer than 16 characters');
        }
        foreach (['name', 'org', 'city', 'sp'] as $field) {
            // postalLineType maxLength is 255 characters (not bytes)
            if (preg_match_all('/./us', $contact[$field]) > 255) {
                throw new \InvalidArgumentException(sprintf('%s is longer than 255 characters', $field));
            }
        }
    }

    /**
     * <contact:create> (RFC 5733 section 3.2.1) with a fresh ID, retried on 2302.
     *
     * @param Connection $connection
     * @param array      $contact
     * @param string[]   $created   receives the ID that was created
     *
     * @return string contact ID
     */
    public static function create(Connection $connection, array $contact, array &$created)
    {
        self::validate($contact);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $id = Helper::contactId($connection->registrarId());

            $frame = new CreateContact();
            $frame->setId($id);
            $frame->setName($contact['name']);
            if ($contact['org'] !== '') {
                $frame->setOrganization($contact['org']);
            }
            foreach ($contact['street'] as $street) {
                $frame->addStreet($street);
            }
            $frame->setCity($contact['city']);
            if ($contact['sp'] !== '') {
                $frame->setProvince($contact['sp']);
            }
            if ($contact['pc'] !== '') {
                $frame->setPostalCode($contact['pc']);
            }
            $frame->setCountryCode($contact['cc']);
            if ($contact['voice'] !== '') {
                $frame->setVoice($contact['voice']);
            }
            if ($contact['fax'] !== '') {
                $frame->setFax($contact['fax']);
            }
            $frame->setEmail($contact['email']);
            // <contact:authInfo> is mandatory in a create; empty under RFC 9154
            $frame->setAuthInfo($connection->supports(Connection::EXT_SECURE_AUTHINFO) ? '' : Helper::authInfo());

            try {
                $connection->send($frame);
            } catch (EppException $e) {
                if ($e->getCode() === 2302) {
                    continue;
                }
                throw $e;
            }

            $created[] = $id;

            return $id;
        }

        throw new EppException('Could not allocate a unique contact ID', 2302);
    }

    /**
     * <contact:info> (RFC 5733 section 3.1.2), preferring the localized postal info.
     *
     * @param Connection $connection
     * @param string     $id
     *
     * @return array contact array plus 'id' and 'clID'
     */
    public static function info(Connection $connection, $id)
    {
        $frame = new InfoContact();
        $frame->setId($id);
        $data = $connection->send($frame)->data();

        $inf = isset($data['infData']) && is_array($data['infData']) ? $data['infData'] : [];
        $postal = [];
        if (isset($inf['postalInfo@loc']) && is_array($inf['postalInfo@loc'])) {
            $postal = $inf['postalInfo@loc'];
        } elseif (isset($inf['postalInfo@int']) && is_array($inf['postalInfo@int'])) {
            $postal = $inf['postalInfo@int'];
        }
        $addr = isset($postal['addr']) && is_array($postal['addr']) ? $postal['addr'] : [];

        $text = function (array $source, $key) {
            return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : '';
        };

        $streets = [];
        foreach (Helper::toList(isset($addr['street']) ? $addr['street'] : null) as $street) {
            if (is_string($street) && trim($street) !== '') {
                $streets[] = trim($street);
            }
        }

        return [
            'id' => $id,
            'clID' => $text($inf, 'clID'),
            'name' => $text($postal, 'name'),
            'org' => $text($postal, 'org'),
            'street' => $streets,
            'city' => $text($addr, 'city'),
            'sp' => $text($addr, 'sp'),
            'pc' => $text($addr, 'pc'),
            'cc' => strtoupper($text($addr, 'cc')),
            'voice' => $text($inf, 'voice'),
            'fax' => $text($inf, 'fax'),
            'email' => $text($inf, 'email'),
        ];
    }

    /**
     * <contact:update> (RFC 5733 section 3.2.5) replacing the postal info and
     * communication details. Empty optional values clear the field.
     *
     * @param Connection $connection
     * @param string     $id
     * @param array      $contact
     */
    public static function update(Connection $connection, $id, array $contact)
    {
        self::validate($contact);

        $frame = new UpdateContact();
        $frame->setId($id);
        $frame->changeName($contact['name']);
        $frame->changeOrganization($contact['org']);
        foreach ($contact['street'] as $street) {
            $frame->changeAddStreet($street);
        }
        $frame->changeCity($contact['city']);
        $frame->changeProvince($contact['sp']);
        $frame->changePostalCode($contact['pc']);
        $frame->changeCountryCode($contact['cc']);
        $frame->changeVoice($contact['voice']);
        $frame->changeFax($contact['fax']);
        $frame->changeEmail($contact['email']);
        $connection->send($frame);
    }

    /**
     * WHMCS GetContactDetails: one block per distinct contact object.
     *
     * @param Connection $connection
     * @param string     $domain
     *
     * @return array
     */
    public static function detailsForDomain(Connection $connection, $domain)
    {
        $ids = self::roleIds(Domain::info($connection, $domain, null, 'none'));

        $details = [];
        $seen = [];
        foreach (array_keys(self::ROLES) as $label) {
            if (empty($ids[$label]) || in_array($ids[$label], $seen, true)) {
                continue;
            }
            try {
                $details[$label] = self::toWhmcsForm(self::info($connection, $ids[$label]));
                $seen[] = $ids[$label];
            } catch (EppException $e) {
                // contacts of another registrar may not be readable (2201)
                if ($label === 'Registrant') {
                    throw $e;
                }
            }
        }

        return $details;
    }

    /**
     * WHMCS SaveContactDetails. Contacts sponsored by this registrar are updated
     * in place; contacts of another registrar (e.g. after an inbound transfer)
     * are replaced by new contacts linked to the domain.
     *
     * @param Connection $connection
     * @param array      $params
     *
     * @return bool false when nothing had to change
     */
    public static function saveForDomain(Connection $connection, array $params)
    {
        $domain = Helper::domainName($params);
        $details = isset($params['contactdetails']) && is_array($params['contactdetails']) ? $params['contactdetails'] : [];
        $defaultCc = isset($params['phonecc']) ? $params['phonecc'] : null;

        $ids = self::roleIds(Domain::info($connection, $domain, null, 'none'));
        $changed = false;
        $handled = [];
        $newRegistrant = null;
        $add = [];
        $remove = [];

        foreach (self::ROLES as $label => $role) {
            if (empty($details[$label]) || !is_array($details[$label])) {
                continue;
            }
            $oldId = isset($ids[$label]) ? $ids[$label] : null;
            if ($oldId !== null && in_array($oldId, $handled, true)) {
                continue;
            }

            $contact = self::fromWhmcsForm($details[$label], $defaultCc);

            $current = null;
            if ($oldId !== null) {
                try {
                    $current = self::info($connection, $oldId);
                } catch (EppException $e) {
                    if (!in_array($e->getCode(), [2201, 2303], true)) {
                        throw $e;
                    }
                }
            }

            if ($current !== null && strcasecmp($current['clID'], $connection->registrarId()) === 0) {
                if (!self::same($current, $contact)) {
                    self::update($connection, $oldId, $contact);
                    $changed = true;
                }
                $handled[] = $oldId;
                continue;
            }

            $created = [];
            $newId = self::create($connection, $contact, $created);
            $changed = true;

            // every role that pointed at the old contact moves to the new one
            foreach (self::ROLES as $otherLabel => $otherRole) {
                $sharesOld = $oldId !== null && isset($ids[$otherLabel]) && $ids[$otherLabel] === $oldId;
                if ($otherLabel !== $label && (!$sharesOld || $otherRole === 'registrant' || $role === 'registrant')) {
                    continue;
                }
                if ($otherRole === 'registrant') {
                    $newRegistrant = $newId;
                } else {
                    $add[] = [$otherRole, $newId];
                    if ($oldId !== null) {
                        $remove[] = [$otherRole, $oldId];
                    }
                }
            }
            if ($oldId !== null) {
                $handled[] = $oldId;
            }
        }

        if ($newRegistrant !== null || $add) {
            $frame = new UpdateDomain();
            $frame->setDomain($domain);
            foreach ($add as $pair) {
                self::linkContact($frame, $pair[0], $pair[1], false);
            }
            foreach ($remove as $pair) {
                self::linkContact($frame, $pair[0], $pair[1], true);
            }
            if ($newRegistrant !== null) {
                $frame->changeRegistrant($newRegistrant);
            }
            $connection->send($frame);
        }

        return $changed;
    }

    /**
     * First contact ID per WHMCS block label.
     *
     * @param array $info flattened <domain:infData>
     *
     * @return array label => id
     */
    private static function roleIds(array $info)
    {
        $ids = [];
        if (!empty($info['registrant']) && is_string($info['registrant'])) {
            $ids['Registrant'] = $info['registrant'];
        }
        foreach (['Technical' => 'tech', 'Admin' => 'admin', 'Billing' => 'billing'] as $label => $type) {
            $list = Helper::contactIds($info, $type);
            if ($list) {
                $ids[$label] = $list[0];
            }
        }

        return $ids;
    }

    /**
     * @param array $a
     * @param array $b
     *
     * @return bool whether both contacts carry the same data
     */
    private static function same(array $a, array $b)
    {
        foreach (self::FIELDS as $field) {
            if ($a[$field] !== $b[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param UpdateDomain $frame
     * @param string       $role
     * @param string       $id
     * @param bool         $remove
     */
    private static function linkContact(UpdateDomain $frame, $role, $id, $remove)
    {
        if ($role === 'admin') {
            $frame->addAdminContact($id, $remove);
        } elseif ($role === 'tech') {
            $frame->addTechContact($id, $remove);
        } elseif ($role === 'billing') {
            $frame->addBillingContact($id, $remove);
        }
    }
}

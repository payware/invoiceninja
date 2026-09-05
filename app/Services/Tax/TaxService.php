<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Services\Tax;

use App\Models\Client;

class TaxService
{
    /** Where the VIES verdict is kept in the client's private notes, idempotently. */
    private const MARK_OPEN = '[VIES]';

    private const MARK_CLOSE = '[/VIES]';

    public function __construct(public Client $client) {}

    /**
     * payware: three changes to upstream, all about чл. 45 ППЗДДС.
     *
     *  1. No `ext-soap` guard. VatNumberCheck now speaks the REST API, so the check runs on
     *     the stock image instead of returning silently and leaving every EU business rated
     *     as a consumer.
     *  2. **A "no" is written.** Upstream sets `has_valid_vat_number = true` and has no
     *     else, so a number VIES rejects keeps whatever the flag was and a counterparty
     *     whose registration is cancelled keeps reverse charge for ever. An answer of
     *     "invalid" now clears the flag - but only an answer. A service outage
     *     (isUnavailable) changes nothing at all: a busy VIES server must never move a tax
     *     treatment.
     *  3. The consultation number and the date are kept, because чл. 45 ППЗДДС wants the
     *     number valid **on the date of the supply** and the requestIdentifier is the
     *     evidence that it was asked.
     */
    public function validateVat(): self
    {
        $client_country_code = $this->client->shipping_country
            ? $this->client->shipping_country->iso_3166_2
            : $this->client->country->iso_3166_2;

        $check = (new VatNumberCheck(
            $this->client->vat_number,
            $client_country_code,
            $this->client->company->settings->vat_number ?? null
        ))->run();

        if ($check->isUnavailable()) {
            nlog("VIES_UNAVAILABLE client={$this->client->hashed_id} vat={$this->client->vat_number} error={$check->getError()} - flag left unchanged");

            return $this;
        }

        $valid = $check->isValid();

        $this->client->has_valid_vat_number = $valid;

        if ($valid) {
            if (! $this->client->name && strlen($check->getName()) > 2) {
                $this->client->name = $check->getName();
            }

            if (empty($this->stripStamp($this->client->private_notes)) && strlen($check->getAddress()) > 2) {
                $this->client->private_notes = $check->getAddress();
            }
        }

        $this->client->private_notes = $this->stamp(
            $this->client->private_notes,
            implode(' ', array_filter([
                $valid ? 'valid' : 'NOT valid',
                $check->getRequestIdentifier() ? 'ref '.$check->getRequestIdentifier() : '',
                $check->getError() ? '('.$check->getError().')' : '',
                'checked '.now()->toDateTimeString(),
            ]))
        );

        $this->client->saveQuietly();

        nlog("VIES_CHECKED client={$this->client->hashed_id} vat={$this->client->vat_number} valid=".($valid ? 'true' : 'false')." ref={$check->getRequestIdentifier()}");

        return $this;
    }

    /** The notes with any previous VIES block removed. */
    private function stripStamp(?string $notes): string
    {
        return trim(preg_replace(
            '/'.preg_quote(self::MARK_OPEN, '/').'.*?'.preg_quote(self::MARK_CLOSE, '/').'/s',
            '',
            (string) $notes
        ));
    }

    /** The notes with exactly one VIES block, carrying $line. */
    private function stamp(?string $notes, string $line): string
    {
        $kept = $this->stripStamp($notes);

        return trim($kept."\n".self::MARK_OPEN.' '.$line.' '.self::MARK_CLOSE);
    }

    public function initTaxProvider() {}
}

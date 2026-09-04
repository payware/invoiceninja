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

use Illuminate\Support\Facades\Http;

/**
 * payware: rewritten to the Commission's REST API.
 *
 * Upstream calls VIES over SOAP, and `ext-soap` is not in the invoiceninja/invoiceninja
 * image. TaxService::validateVat() opens with `if (!extension_loaded('soap')) return`, so
 * on a stock deployment this class never ran and no VAT number was ever validated - which
 * leaves every EU business in the B2C branch of the tax rules and charges it origin VAT
 * instead of reverse charge. Checked 2026-09-04 on 5.13.19: `php -m` has no soap.
 *
 * Two further changes, both about not turning "I could not tell you" into "no":
 *
 *  - `valid` is **three-valued**. true and false are facts about the counterparty; null is
 *    a fact about the service. VIES reports "not registered" and "my member state's system
 *    is down" through the same field, and only the first may move a tax treatment.
 *  - the POST form is used rather than the GET one, because only the POST - which names the
 *    enquirer - comes back with a `requestIdentifier`. That consultation number is the
 *    evidence that the check was made, which чл. 45 ППЗДДС wants kept.
 *
 * This mirrors payware_bg/vat/vies_check.py, deliberately: the two systems ask the same
 * service the same way, so they cannot disagree about the same counterparty.
 */
class VatNumberCheck
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    private const TIMEOUT = 20;

    private const ATTEMPTS = 4;

    /** Codes that mean the service could not answer, not that the number is bad. */
    private const UNAVAILABLE = [
        'MS_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'SERVICE_UNAVAILABLE',
        'TIMEOUT', 'GLOBAL_MAX_CONCURRENT_REQ', 'SERVER_BUSY',
    ];

    private array $response = [];

    public function __construct(protected ?string $vat_number, protected string $country_code, protected ?string $requester_vat = null) {}

    public function run(): self
    {
        [$country, $number] = $this->split($this->vat_number, $this->country_code);

        if (! $country || ! $number) {
            $this->response = ['valid' => null, 'error' => 'No VAT number provided'];

            return $this;
        }

        $payload = ['countryCode' => $country, 'vatNumber' => $number];

        [$rq_country, $rq_number] = $this->split($this->requester_vat, '');
        if ($rq_country && $rq_number) {
            $payload['requesterMemberStateCode'] = $rq_country;
            $payload['requesterNumber'] = $rq_number;
        }

        $answer = null;
        $error = '';

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            if ($attempt) {
                usleep((int) (1_500_000 * $attempt));
            }

            try {
                $r = Http::timeout(self::TIMEOUT)->asJson()->post(self::ENDPOINT, $payload);

                if ($r->failed()) {
                    $error = 'HTTP '.$r->status();

                    continue;
                }

                $body = $r->json();
            } catch (\Throwable $e) {
                $error = class_basename($e).': '.$e->getMessage();

                continue;
            }

            $code = $this->errorOf($body);

            if (in_array($code, self::UNAVAILABLE, true)) {
                $error = $code;

                continue;
            }

            $answer = $body;
            $error = $code;
            break;
        }

        if ($answer === null) {
            // Never an answer about the number. `valid` stays null.
            $this->response = ['valid' => null, 'error' => $error ?: 'VIES did not answer'];

            return $this;
        }

        if (! array_key_exists('valid', $answer) || $answer['valid'] === null || ($answer['actionSucceed'] ?? true) === false) {
            $this->response = ['valid' => null, 'error' => $error ?: 'VIES returned no verdict'];

            return $this;
        }

        $this->response = [
            'valid' => (bool) $answer['valid'],
            'request_id' => $answer['requestIdentifier'] ?? '',
            'name' => trim($answer['name'] ?? ''),
            'address' => trim(preg_replace('/\s+/', ' ', $answer['address'] ?? '')),
            'error' => ((bool) $answer['valid'] || $error === 'INVALID') ? '' : $error,
        ];

        return $this;
    }

    /** 'ESB12345678' -> ['ES', 'B12345678']. Greece files as EL, not GR. */
    private function split(?string $vat, string $fallback_country): array
    {
        $vat = strtoupper(str_replace([' ', '-'], '', trim($vat ?? '')));

        if ($vat === '') {
            return [null, null];
        }

        if (strlen($vat) > 2 && ctype_alpha(substr($vat, 0, 2))) {
            $code = substr($vat, 0, 2);

            return [$code === 'GR' ? 'EL' : $code, substr($vat, 2)];
        }

        $code = strtoupper(trim($fallback_country));

        return [$code === 'GR' ? 'EL' : ($code ?: null), $vat];
    }

    /** The error code, wherever this particular answer chose to put it. */
    private function errorOf(mixed $body): string
    {
        if (! is_array($body)) {
            return '';
        }

        $err = strtoupper(trim((string) ($body['userError'] ?? '')));

        if ($err !== '') {
            return $err;
        }

        foreach (($body['errorWrappers'] ?? []) as $wrapper) {
            $code = strtoupper(trim((string) ($wrapper['error'] ?? '')));

            if ($code !== '') {
                return $code;
            }
        }

        return '';
    }

    public function getResponse(): array
    {
        return $this->response;
    }

    /** True only when VIES said so. A service outage is not a "no". */
    public function isValid(): bool
    {
        return ($this->response['valid'] ?? null) === true;
    }

    /** True only when VIES said the number is not a current registration. */
    public function isInvalid(): bool
    {
        return ($this->response['valid'] ?? null) === false;
    }

    /** True when VIES did not answer at all - the case that must change nothing. */
    public function isUnavailable(): bool
    {
        return ($this->response['valid'] ?? null) === null;
    }

    public function getRequestIdentifier(): string
    {
        return $this->response['request_id'] ?? '';
    }

    public function getName(): string
    {
        return $this->response['name'] ?? '';
    }

    public function getAddress(): string
    {
        return $this->response['address'] ?? '';
    }

    public function getError(): string
    {
        return $this->response['error'] ?? '';
    }
}
